<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * InfluencerDispute Model - Dispute resolution between customer & influencer
 * 
 * Status Flow: open_peer → (resolved_peer | escalated) → (resolved_admin | closed)
 */
class InfluencerDispute extends Model
{
    // ┌─────────────────────────────────────────────────────────────┐
    // │ Status Constants
    // └─────────────────────────────────────────────────────────────┘
    public const STATUS_OPEN_PEER = 'open_peer';
    public const STATUS_RESOLVED_PEER = 'resolved_peer';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_RESOLVED_ADMIN = 'resolved_admin';
    public const STATUS_CLOSED = 'closed';

    public const OPEN_STATUSES = [self::STATUS_OPEN_PEER, self::STATUS_ESCALATED];
    public const CLOSED_STATUSES = [self::STATUS_RESOLVED_PEER, self::STATUS_RESOLVED_ADMIN, self::STATUS_CLOSED];

    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   so.order_type, so.price, so.currency, so.influencer_earning,
                   so.proof_screenshot, so.proof_link, so.proof_notes,
                   ip.username AS influencer_username, ip.page_url,
                   cu.full_name AS customer_name, cu.email AS customer_email,
                   iu.full_name AS influencer_name, iu.email AS influencer_email
            FROM influencer_disputes d
            LEFT JOIN story_orders     so ON so.id = d.order_id
            LEFT JOIN influencer_profiles ip ON ip.id = so.influencer_id
            LEFT JOIN users cu ON cu.id = d.customer_id
            LEFT JOIN users iu ON iu.id = d.influencer_user_id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function findByOrderId(int $orderId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   cu.full_name AS customer_name,
                   iu.full_name AS influencer_name
            FROM influencer_disputes d
            LEFT JOIN users cu ON cu.id = d.customer_id
            LEFT JOIN users iu ON iu.id = d.influencer_user_id
            WHERE d.order_id = ?
            ORDER BY d.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function create(array $d): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO influencer_disputes
            (order_id, customer_id, influencer_user_id, opened_by, reason,
             status, peer_deadline, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'open_peer', ?, NOW(), NOW())
        ");
        $result = $stmt->execute([
            $d['order_id'],
            $d['customer_id'],
            $d['influencer_user_id'],
            $d['opened_by'],
            $d['reason'],
            $d['peer_deadline'],
        ]);
        if (!$result) return null;
        return $this->find((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): bool
    {
        $allowed = [
            'status', 'peer_deadline', 'resolution_note',
            'admin_verdict', 'admin_verdict_note', 'resolved_by',
            'resolved_at', 'refund_percent',
        ];
        $fields = []; $values = [];
        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $values[] = $data[$f];
            }
        }
        if (empty($fields)) return false;
        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        $stmt = $this->db->prepare(
            "UPDATE influencer_disputes SET " . \implode(', ', $fields) . " WHERE id = ?"
        );
        return $stmt->execute($values);
    }

    // ──────────────────────────────────────
    // پیام‌های اختلاف
    // ──────────────────────────────────────

    public function addMessage(int $disputeId, int $userId, string $role, string $message, ?string $attachment = null): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO influencer_dispute_messages
            (dispute_id, user_id, role, message, attachment, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $result = $stmt->execute([$disputeId, $userId, $role, $message, $attachment]);
        if (!$result) return null;
        $msgId = (int) $this->db->lastInsertId();
        return $this->getMessage($msgId);
    }

    public function getMessage(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT m.*, u.full_name AS sender_name
            FROM influencer_dispute_messages m
            LEFT JOIN users u ON u.id = m.user_id
            WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function getMessages(int $disputeId): array
    {
        $stmt = $this->db->prepare("
            SELECT m.*, u.full_name AS sender_name
            FROM influencer_dispute_messages m
            LEFT JOIN users u ON u.id = m.user_id
            WHERE m.dispute_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$disputeId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    // ──────────────────────────────────────
    // لیست ادمین
    // ──────────────────────────────────────

    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status'])) {
            $where[] = "d.status = ?"; $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(cu.full_name LIKE ? OR iu.full_name LIKE ? OR ip.username LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT d.*, so.order_type, so.price, so.currency,
                   ip.username AS influencer_username,
                   cu.full_name AS customer_name,
                   iu.full_name AS influencer_name
            FROM influencer_disputes d
            LEFT JOIN story_orders so ON so.id = d.order_id
            LEFT JOIN influencer_profiles ip ON ip.id = so.influencer_id
            LEFT JOIN users cu ON cu.id = d.customer_id
            LEFT JOIN users iu ON iu.id = d.influencer_user_id
            WHERE {$whereStr}
            ORDER BY d.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit; $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCount(array $filters = []): int
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status'])) {
            $where[] = "d.status = ?"; $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(cu.full_name LIKE ? OR iu.full_name LIKE ? OR ip.username LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM influencer_disputes d
            LEFT JOIN story_orders so ON so.id = d.order_id
            LEFT JOIN influencer_profiles ip ON ip.id = so.influencer_id
            LEFT JOIN users cu ON cu.id = d.customer_id
            LEFT JOIN users iu ON iu.id = d.influencer_user_id
            WHERE {$whereStr}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function statusLabels(): array
    {
        return [
            'open_peer'      => 'گفت‌وگوی طرفین',
            'resolved_peer'  => 'حل دوستانه',
            'escalated'      => 'ارجاع به مدیر',
            'resolved_admin' => 'رأی مدیر صادر شد',
            'closed'         => 'بسته شده',
        ];
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ State Machine Validation
    // └─────────────────────────────────────────────────────────────┘

    /**
     * Valid status transitions
     */
    private const TRANSITIONS = [
        self::STATUS_OPEN_PEER => [self::STATUS_RESOLVED_PEER, self::STATUS_ESCALATED],
        self::STATUS_RESOLVED_PEER => [self::STATUS_CLOSED],
        self::STATUS_ESCALATED => [self::STATUS_RESOLVED_ADMIN],
        self::STATUS_RESOLVED_ADMIN => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [], // Terminal state
    ];

    /**
     * Check if transition is valid
     */
    public function canTransitionTo(string $currentStatus, string $targetStatus): bool
    {
        if (!isset(self::TRANSITIONS[$currentStatus])) {
            return false;
        }
        return \in_array($targetStatus, self::TRANSITIONS[$currentStatus], true);
    }

    /**
     * Get allowed next states
     */
    public function getAllowedTransitions(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * Check if status is terminal
     */
    public function isTerminalStatus(string $status): bool
    {
        return empty(self::TRANSITIONS[$status] ?? []);
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ Ownership & Validation Methods
    // └─────────────────────────────────────────────────────────────┘

    /**
     * Get dispute with null safety
     */
    public function getSafe(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        return $this->find($id);
    }

    /**
     * Check if user is party in dispute (customer or influencer)
     */
    public function isParty(int $disputeId, int $userId): bool
    {
        $dispute = $this->getSafe($disputeId);
        if (!$dispute) {
            return false;
        }
        return $dispute->customer_id === $userId || $dispute->influencer_user_id === $userId;
    }

    /**
     * Check if dispute belongs to order
     */
    public function belongsToOrder(int $disputeId, int $orderId): bool
    {
        $dispute = $this->getSafe($disputeId);
        if (!$dispute) {
            return false;
        }
        return $dispute->order_id === $orderId;
    }

    /**
     * Check if order already has an open dispute
     * Prevents duplicate disputes for same order
     */
    public function hasOpenDispute(int $orderId): bool
    {
        $result = $this->db->prepare(
            "SELECT 1 FROM influencer_disputes 
             WHERE order_id = ? AND status IN (?, ?) LIMIT 1"
        );
        $result->execute([$orderId, self::STATUS_OPEN_PEER, self::STATUS_ESCALATED]);
        return (bool)$result->fetchColumn();
    }

    /**
     * Check if dispute exists
     */
    public function exists(int $disputeId): bool
    {
        $result = $this->db->prepare(
            "SELECT 1 FROM influencer_disputes WHERE id = ? LIMIT 1"
        );
        $result->execute([$disputeId]);
        return (bool)$result->fetchColumn();
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ Business Logic Methods
    // └─────────────────────────────────────────────────────────────┘

    /**
     * Can user send message in dispute
     */
    public function canSendMessage(int $disputeId, int $userId): bool
    {
        // Only open disputes allow messages
        $dispute = $this->getSafe($disputeId);
        if (!$dispute || !isset(self::OPEN_STATUSES[$dispute->status])) {
            return false;
        }
        // Only parties can send messages
        return $this->isParty($disputeId, $userId);
    }

    /**
     * Can resolve dispute (for both peer & admin)
     */
    public function canResolveDispute(int $disputeId): bool
    {
        $dispute = $this->getSafe($disputeId);
        if (!$dispute) {
            return false;
        }
        // Can resolve if open_peer or escalated
        return \in_array($dispute->status, [self::STATUS_OPEN_PEER, self::STATUS_ESCALATED], true);
    }

    /**
     * Can escalate dispute
     */
    public function canEscalate(int $disputeId): bool
    {
        $dispute = $this->getSafe($disputeId);
        if (!$dispute) {
            return false;
        }
        return $dispute->status === self::STATUS_OPEN_PEER;
    }

    /**
     * Get unread message count
     */
    public function getUnreadMessageCount(int $disputeId, int $userId): int
    {
        $result = $this->db->prepare(
            "SELECT COUNT(*) FROM influencer_dispute_messages 
             WHERE dispute_id = ? AND user_id != ? AND is_read = 0"
        );
        $result->execute([$disputeId, $userId]);
        return (int)$result->fetchColumn();
    }
}

