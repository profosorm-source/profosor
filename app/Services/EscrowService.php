<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;

/**
 * EscrowService - تسویه‌ مرکزی برای تمام ماژول‌های مالی
 * 
 * وضعیت‌های Escrow:
 * - pending:    انتقال از seller/advertiser منتظر
 * - in_escrow:  funds held
 * - released:   transferred to seller/advertiser
 * - refunded:   returned to buyer
 * - disputed:   waiting for resolution
 */
class EscrowService
{
    private Database $db;
    private Logger   $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db     = $db;
        $this->logger = $logger;
    }

    /**
     * درخواست نگهداری funds (Seller → Escrow)
     * ✅ Transaction-based state machine
     */
    public function holdFunds(
        int    $orderId,
        string $orderType,
        int    $buyerId,
        int    $sellerId,
        float  $amount,
        string $currency = 'USDT'
    ): array {
        try {
            $this->db->beginTransaction();

            // ✅ Check if escrow already exists
            $existing = $this->db->query(
                "SELECT id FROM escrow_transactions 
                 WHERE order_id = ? AND order_type = ? AND status != ? 
                 LIMIT 1",
                [$orderId, $orderType, 'refunded']
            )->fetch();

            if ($existing) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow already exists for this order'];
            }

            // ✅ Validate amount
            if ($amount <= 0) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Invalid amount'];
            }

            // ✅ Create escrow record
            $escrowData = [
                'order_id'    => $orderId,
                'order_type'  => $orderType,
                'buyer_id'    => $buyerId,
                'seller_id'   => $sellerId,
                'amount'      => $amount,
                'currency'    => $currency,
                'status'      => 'pending', // awaiting transfer
                'held_at'     => date('Y-m-d H:i:s'),
                'expires_at'  => date('Y-m-d H:i:s', strtotime('+30 days')),
            ];

            $stmt = $this->db->prepare(
                "INSERT INTO escrow_transactions 
                 (order_id, order_type, buyer_id, seller_id, amount, currency, status, held_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $result = $stmt->execute([
                $escrowData['order_id'],
                $escrowData['order_type'],
                $escrowData['buyer_id'],
                $escrowData['seller_id'],
                $escrowData['amount'],
                $escrowData['currency'],
                $escrowData['status'],
                $escrowData['held_at'],
                $escrowData['expires_at'],
            ]);

            if (!$result) {
                throw new \Exception('Failed to create escrow record');
            }

            $this->logger->info('escrow.hold_requested', [
                'order_id' => $orderId,
                'order_type' => $orderType,
                'amount' => $amount,
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId,
            ]);

            $this->db->commit();
            return ['ok' => true, 'escrow_id' => $this->db->lastInsertId()];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('escrow.hold.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تایید و نگهداری funds (pending → in_escrow)
     * ✅ With database locking
     */
    public function confirmHold(int $orderId, string $orderType, int $sellerId): array
    {
        try {
            $this->db->beginTransaction();

            // ✅ Acquire write lock
            $escrow = $this->db->query(
                "SELECT * FROM escrow_transactions 
                 WHERE order_id = ? AND order_type = ? AND seller_id = ?
                 AND status = 'pending' FOR UPDATE",
                [$orderId, $orderType, $sellerId]
            )->fetch();

            if (!$escrow) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow not found or already confirmed'];
            }

            // ✅ Update status
            $stmt = $this->db->prepare(
                "UPDATE escrow_transactions 
                 SET status = 'in_escrow', confirmed_at = ?
                 WHERE id = ? AND status = 'pending'"
            );
            $result = $stmt->execute([date('Y-m-d H:i:s'), $escrow->id]);

            if (!$result || $stmt->rowCount() === 0) {
                throw new \Exception('Failed to confirm escrow');
            }

            $this->logger->info('escrow.confirmed', [
                'escrow_id' => $escrow->id,
                'order_id' => $orderId,
                'amount' => $escrow->amount,
            ]);

            $this->db->commit();
            return ['ok' => true, 'escrow_id' => $escrow->id];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('escrow.confirm.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تحویل funds به فروشنده (in_escrow → released)
     * ✅ Final state - cannot be reversed
     */
    public function releaseFunds(int $escrowId, int $sellerId, string $releasedBy): array
    {
        try {
            $this->db->beginTransaction();

            // ✅ Acquire lock & validate state
            $escrow = $this->db->query(
                "SELECT * FROM escrow_transactions 
                 WHERE id = ? AND seller_id = ? 
                 AND status IN ('in_escrow', 'partial')
                 FOR UPDATE",
                [$escrowId, $sellerId]
            )->fetch();

            if (!$escrow) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow not found or cannot be released'];
            }

            // ✅ Update escrow status
            $stmt = $this->db->prepare(
                "UPDATE escrow_transactions 
                 SET status = 'released', released_at = ?, released_by = ?
                 WHERE id = ?"
            );
            $result = $stmt->execute([date('Y-m-d H:i:s'), $releasedBy, $escrowId]);

            if (!$result || $stmt->rowCount() === 0) {
                throw new \Exception('Failed to release funds');
            }

            // ✅ Log audit trail
            $this->db->query(
                "INSERT INTO escrow_audit 
                 (escrow_id, action, amount, performed_by, created_at)
                 VALUES (?, ?, ?, ?, ?)",
                [$escrowId, 'released', $escrow->amount, $releasedBy, date('Y-m-d H:i:s')]
            );

            $this->logger->info('escrow.released', [
                'escrow_id' => $escrowId,
                'order_id' => $escrow->order_id,
                'amount' => $escrow->amount,
                'seller_id' => $sellerId,
            ]);

            $this->db->commit();
            return ['ok' => true, 'amount' => $escrow->amount];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('escrow.release.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * بازگرداندن funds به خریدار (in_escrow/pending → refunded)
     * ✅ Used for cancellations or refunds
     */
    public function refundFunds(
        int    $escrowId,
        int    $buyerId,
        string $reason,
        string $initiatedBy
    ): array {
        try {
            $this->db->beginTransaction();

            // ✅ Acquire lock
            $escrow = $this->db->query(
                "SELECT * FROM escrow_transactions 
                 WHERE id = ? AND buyer_id = ? 
                 AND status IN ('in_escrow', 'pending', 'disputed')
                 FOR UPDATE",
                [$escrowId, $buyerId]
            )->fetch();

            if (!$escrow) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow not found or cannot be refunded'];
            }

            // ✅ Prevent double refund
            if ($escrow->status === 'refunded') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Already refunded'];
            }

            // ✅ Update status
            $stmt = $this->db->prepare(
                "UPDATE escrow_transactions 
                 SET status = 'refunded', 
                     refunded_at = ?, 
                     refund_reason = ?,
                     refunded_by = ?
                 WHERE id = ?"
            );
            $result = $stmt->execute([
                date('Y-m-d H:i:s'),
                $reason,
                $initiatedBy,
                $escrowId
            ]);

            if (!$result || $stmt->rowCount() === 0) {
                throw new \Exception('Failed to refund');
            }

            // ✅ Log refund
            $this->db->query(
                "INSERT INTO escrow_audit 
                 (escrow_id, action, amount, performed_by, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$escrowId, 'refunded', $escrow->amount, $initiatedBy, $reason, date('Y-m-d H:i:s')]
            );

            $this->logger->info('escrow.refunded', [
                'escrow_id' => $escrowId,
                'order_id' => $escrow->order_id,
                'amount' => $escrow->amount,
                'reason' => $reason,
            ]);

            $this->db->commit();
            return ['ok' => true, 'amount' => $escrow->amount, 'refund_id' => $escrowId];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('escrow.refund.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * وضعیت را به disputed تغییر بده (در صورت اختلاف)
     * ✅ Prevents release/refund during dispute
     */
    public function markAsDisputed(int $escrowId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                "UPDATE escrow_transactions 
                 SET status = 'disputed', disputed_at = ?, dispute_reason = ?
                 WHERE id = ? AND status IN ('in_escrow', 'pending')"
            );
            $result = $stmt->execute([date('Y-m-d H:i:s'), $reason, $escrowId]);

            if (!$result || $stmt->rowCount() === 0) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Failed to mark as disputed'];
            }

            $this->logger->info('escrow.disputed', ['escrow_id' => $escrowId, 'reason' => $reason]);
            $this->db->commit();
            return ['ok' => true];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * دریافت وضعیت escrow
     */
    public function getStatus(int $escrowId): ?object
    {
        return $this->db->query(
            "SELECT * FROM escrow_transactions WHERE id = ?",
            [$escrowId]
        )->fetch();
    }

    /**
     * دریافت escrow برای order
     */
    public function getByOrder(int $orderId, string $orderType): ?object
    {
        return $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE order_id = ? AND order_type = ? 
             ORDER BY id DESC LIMIT 1",
            [$orderId, $orderType]
        )->fetch();
    }

    /**
     * بررسی اینکه آیا escrow منقضی‌ شده (مثل قبل از تحویل)
     */
    public function isExpired(int $escrowId): bool
    {
        $escrow = $this->db->query(
            "SELECT expires_at FROM escrow_transactions WHERE id = ?",
            [$escrowId]
        )->fetch();

        return $escrow && strtotime($escrow->expires_at) < time();
    }
}
