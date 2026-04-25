<?php

namespace App\Services;

use Core\Database;

/**
 * ReferralMultiTierService
 * 
 * سیستم رفرال چند سطحی (Multi-tier)
 * مثال: سطح 1 = 10%، سطح 2 = 5%، سطح 3 = 2%
 */
class ReferralMultiTierService
{
    private Database $db;
    private WalletService $walletService;
    private ReferralCommissionService $commissionService;

    public function __construct(
        Database $db,
        WalletService $walletService,
        ReferralCommissionService $commissionService
    ) {
        $this->db = $db;
        $this->walletService = $walletService;
        $this->commissionService = $commissionService;
    }

    /**
     * پردازش کمیسیون چند سطحی
     * 
     * وقتی referred_id درآمدی داره، به تمام سطوح بالاتر کمیسیون داده میشه
     */
    public function processMultiTierCommissions(
        int $referredId,
        string $sourceType,
        ?int $sourceId,
        float $sourceAmount,
        string $currency = 'irt'
    ): array {
        if (!$this->isEnabled()) {
            return [];
        }

        $maxTiers = $this->getMaxTierLevel();
        if ($maxTiers < 2) {
            return [];
        }

        $results = [];
        $referralChain = $this->buildReferralChain($referredId, $maxTiers);

        foreach ($referralChain as $tierLevel => $referrerId) {
            if ($tierLevel == 1) {
                // سطح 1 توسط ReferralCommissionService اصلی پردازش میشه
                continue;
            }

            $commission = $this->processCommissionForTier(
                $referrerId,
                $referredId,
                $tierLevel,
                $sourceType,
                $sourceId,
                $sourceAmount,
                $currency
            );

            if ($commission) {
                $results[] = $commission;
            }
        }

        return $results;
    }

    /**
     * ساخت زنجیره رفرال (تا N سطح بالاتر)
     */
    private function buildReferralChain(int $userId, int $maxLevels): array
    {
        $chain = [];
        $currentUserId = $userId;
        $level = 1;

        while ($level <= $maxLevels && $currentUserId) {
            $stmt = $this->db->prepare("
                SELECT referred_by FROM users 
                WHERE id = ? AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([$currentUserId]);
            $referredBy = $stmt->fetchColumn();

            if (!$referredBy) {
                break;
            }

            $chain[$level] = (int) $referredBy;
            $currentUserId = (int) $referredBy;
            $level++;
        }

        return $chain;
    }

    /**
     * پردازش کمیسیون برای یک سطح خاص
     */
    private function processCommissionForTier(
        int $referrerId,
        int $referredId,
        int $tierLevel,
        string $sourceType,
        ?int $sourceId,
        float $sourceAmount,
        string $currency
    ): ?object {
        // درصد کمیسیون این سطح
        $percent = $this->getTierCommissionPercent($tierLevel, $sourceType);
        if ($percent <= 0) {
            return null;
        }

        // بررسی وضعیت معرف
        $referrer = $this->db->query(
            "SELECT status, is_silently_blacklisted, fraud_score FROM users WHERE id = ? LIMIT 1",
            [$referrerId]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$referrer) {
            return null;
        }

        // بررسی بن یا بلک لیست
        if (in_array($referrer->status, ['banned', 'suspended']) || $referrer->is_silently_blacklisted) {
            $this->logger->info('Multi-tier commission skipped: referrer banned/blacklisted', [
                'referrer_id' => $referrerId,
                'tier_level' => $tierLevel
            ]);
            return null;
        }

        // بررسی Fraud Score
        if ($referrer->fraud_score >= 70) {
            $this->logger->info('Multi-tier commission skipped: high fraud score', [
                'referrer_id' => $referrerId,
                'fraud_score' => $referrer->fraud_score,
                'tier_level' => $tierLevel
            ]);
            return null;
        }

        // محاسبه مبلغ
        $commissionAmount = round($sourceAmount * ($percent / 100), 2);
        if ($commissionAmount <= 0) {
            return null;
        }

        // ساخت idempotency key
        $idempotencyKey = "ref_tier_{$referrerId}_{$referredId}_{$tierLevel}_{$sourceType}_{$sourceId}_{$currency}";

        // بررسی تکراری نبودن
        $existing = $this->db->query(
            "SELECT id FROM referral_commissions WHERE idempotency_key = ? LIMIT 1",
            [$idempotencyKey]
        )->fetch(\PDO::FETCH_OBJ);

        if ($existing) {
            $this->logger->warning('Duplicate multi-tier commission prevented', [
                'idempotency_key' => $idempotencyKey,
                'existing_id' => $existing->id
            ]);
            return $existing;
        }

        try {
            $this->db->beginTransaction();

            // ثبت کمیسیون
            $stmt = $this->db->prepare("
                INSERT INTO referral_commissions
                (referrer_id, referred_id, source_type, source_id, source_amount,
                 commission_percent, commission_amount, currency, status, tier_level,
                 idempotency_key, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW())
            ");

            $metadata = json_encode([
                'tier_level' => $tierLevel,
                'source_type_label' => $this->commissionService->getSourceLabel($sourceType),
                'multi_tier' => true
            ], JSON_UNESCAPED_UNICODE);

            $stmt->execute([
                $referrerId,
                $referredId,
                $sourceType,
                $sourceId,
                $sourceAmount,
                $percent,
                $commissionAmount,
                $currency,
                $tierLevel,
                $idempotencyKey,
                $metadata
            ]);

            $commissionId = $this->db->lastInsertId();

            // پرداخت خودکار اگر فعال باشد
            if ($this->isAutoPayEnabled()) {
                $txId = $this->payCommission($commissionId, $referrerId, $commissionAmount, $currency);
                
                if ($txId) {
                    $this->db->query(
                        "UPDATE referral_commissions SET status = 'paid', paid_at = NOW(), transaction_id = ? WHERE id = ?",
                        [$txId, $commissionId]
                    );
                }
            }

            // لاگ
            $this->db->prepare("
                INSERT INTO referral_activity_logs (referrer_id, action, metadata, created_at)
                VALUES (?, 'commission_earned', ?, NOW())
            ")->execute([
                $referrerId,
                json_encode([
                    'commission_id' => $commissionId,
                    'tier_level' => $tierLevel,
                    'amount' => $commissionAmount,
                    'currency' => $currency,
                    'source' => $sourceType
                ], JSON_UNESCAPED_UNICODE)
            ]);

            $this->db->commit();

            $this->logger->info('Multi-tier commission processed', [
                'commission_id' => $commissionId,
                'referrer_id' => $referrerId,
                'referred_id' => $referredId,
                'tier_level' => $tierLevel,
                'amount' => $commissionAmount,
                'currency' => $currency
            ]);

            // دریافت رکورد کامل
            return $this->db->query(
                "SELECT * FROM referral_commissions WHERE id = ? LIMIT 1",
                [$commissionId]
            )->fetch(\PDO::FETCH_OBJ);

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Multi-tier commission processing failed', [
                'error' => $e->getMessage(),
                'referrer_id' => $referrerId,
                'referred_id' => $referredId,
                'tier_level' => $tierLevel
            ]);
            return null;
        }
    }

    /**
     * پرداخت کمیسیون
     */
    private function payCommission(int $commissionId, int $referrerId, float $amount, string $currency): ?string
    {
        try {
            $result = $this->walletService->deposit(
                $referrerId,
                $amount,
                $currency,
                [
                    'type' => 'referral_commission_tier',
                    'description' => "کمیسیون غیرمستقیم - شماره {$commissionId}",
                    'ref_id' => $commissionId,
                    'ref_type' => 'referral_commission'
                ]
            );

            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'Wallet deposit failed');
            }

            return $result['transaction_id'];

        } catch (\Exception $e) {
            $this->logger->error('Multi-tier commission payment failed', [
                'commission_id' => $commissionId,
                'referrer_id' => $referrerId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * دریافت درصد کمیسیون برای سطح خاص
     */
    private function getTierCommissionPercent(int $tierLevel, string $sourceType): float
    {
        $key = "referral_commission_{$sourceType}_tier{$tierLevel}_percent";
        $percent = (float) setting($key, 0);

        // اگر setting خاصی نداره، از درصدهای پیش‌فرض استفاده کن
        if ($percent == 0 && $tierLevel <= 3) {
            $defaults = [
                2 => 0.5, // سطح 2: نصف سطح 1
                3 => 0.2  // سطح 3: یک پنجم سطح 1
            ];

            $basePercent = (float) setting("referral_commission_{$sourceType}_percent", 0);
            $multiplier = $defaults[$tierLevel] ?? 0;
            
            $percent = $basePercent * $multiplier;
        }

        return $percent;
    }

    /**
     * حداکثر سطح فعال
     */
    private function getMaxTierLevel(): int
    {
        return (int) setting('referral_max_tier_level', 1);
    }

    /**
     * بررسی فعال بودن Multi-tier
     */
    private function isEnabled(): bool
    {
        return (bool) setting('referral_multi_tier_enabled', 0);
    }

    /**
     * بررسی پرداخت خودکار
     */
    private function isAutoPayEnabled(): bool
    {
        return (bool) setting('referral_commission_auto_pay', 1);
    }

    /**
     * آمار کمیسیون‌های غیرمستقیم یک کاربر
     */
    public function getIndirectEarnings(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                tier_level,
                COUNT(*) as count,
                SUM(CASE WHEN currency='irt' AND status='paid' THEN commission_amount ELSE 0 END) as earned_irt,
                SUM(CASE WHEN currency='usdt' AND status='paid' THEN commission_amount ELSE 0 END) as earned_usdt,
                SUM(CASE WHEN currency='irt' AND status='pending' THEN commission_amount ELSE 0 END) as pending_irt,
                SUM(CASE WHEN currency='usdt' AND status='pending' THEN commission_amount ELSE 0 END) as pending_usdt
            FROM referral_commissions
            WHERE referrer_id = ? AND tier_level > 1
            GROUP BY tier_level
            ORDER BY tier_level ASC
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * نمودار درآمد تفکیک شده به سطح
     */
    public function getEarningsByTier(int $userId): array
    {
        $direct = $this->db->query(
            "SELECT 
                COALESCE(SUM(CASE WHEN currency='irt' AND status='paid' THEN commission_amount ELSE 0 END), 0) as earned_irt,
                COALESCE(SUM(CASE WHEN currency='usdt' AND status='paid' THEN commission_amount ELSE 0 END), 0) as earned_usdt
             FROM referral_commissions
             WHERE referrer_id = ? AND (tier_level = 1 OR tier_level IS NULL)",
            [$userId]
        )->fetch(\PDO::FETCH_OBJ);

        $indirect = $this->getIndirectEarnings($userId);

        return [
            'direct' => [
                'tier_level' => 1,
                'earned_irt' => $direct->earned_irt ?? 0,
                'earned_usdt' => $direct->earned_usdt ?? 0,
                'label' => 'مستقیم'
            ],
            'indirect' => array_map(function($tier) {
                return [
                    'tier_level' => $tier->tier_level,
                    'earned_irt' => $tier->earned_irt,
                    'earned_usdt' => $tier->earned_usdt,
                    'label' => "سطح {$tier->tier_level}"
                ];
            }, $indirect)
        ];
    }

    /**
     * شبکه رفرال کاربر (نمایش درختی)
     */
    public function getReferralNetwork(int $userId, int $maxDepth = 3): array
    {
        return $this->buildNetworkTree($userId, 1, $maxDepth);
    }

    /**
     * ساخت درخت شبکه به صورت بازگشتی
     */
    private function buildNetworkTree(int $userId, int $currentDepth, int $maxDepth): array
    {
        if ($currentDepth > $maxDepth) {
            return [];
        }

        // دریافت زیرمجموعه‌های مستقیم
        $stmt = $this->db->prepare("
            SELECT 
                u.id,
                u.full_name,
                u.email,
                u.status,
                u.created_at,
                COUNT(DISTINCT ref.id) as direct_referrals_count,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned
            FROM users u
            LEFT JOIN users ref ON ref.referred_by = u.id
            LEFT JOIN referral_commissions rc ON rc.referred_id = u.id
            WHERE u.referred_by = ? AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$userId]);
        $children = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $network = [];
        foreach ($children as $child) {
            $network[] = [
                'user' => $child,
                'depth' => $currentDepth,
                'children' => $this->buildNetworkTree($child->id, $currentDepth + 1, $maxDepth)
            ];
        }

        return $network;
    }

    /**
     * خلاصه آمار شبکه
     */
    public function getNetworkStats(int $userId): array
    {
        $maxTiers = $this->getMaxTierLevel();
        $stats = [
            'total_network_size' => 0,
            'by_tier' => []
        ];

        for ($tier = 1; $tier <= $maxTiers; $tier++) {
            $count = $this->countReferralsAtTier($userId, $tier);
            $stats['by_tier'][$tier] = $count;
            $stats['total_network_size'] += $count;
        }

        return $stats;
    }

    /**
     * شمارش تعداد رفرال در یک سطح خاص
     */
    private function countReferralsAtTier(int $userId, int $tier): int
    {
        if ($tier == 1) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM users 
                WHERE referred_by = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        }

        // برای سطوح بالاتر، باید بازگشتی شمارش کنیم
        $chain = $this->buildReferralChain($userId, $tier - 1);
        
        if (empty($chain)) {
            return 0;
        }

        // کاربرانی که در سطح N هستند
        $referrersAtPreviousTier = $this->getReferralsAtDepth($userId, $tier - 1);
        
        if (empty($referrersAtPreviousTier)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($referrersAtPreviousTier), '?'));
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM users 
            WHERE referred_by IN ($placeholders) AND deleted_at IS NULL
        ");
        $stmt->execute($referrersAtPreviousTier);
        
        return (int) $stmt->fetchColumn();
    }

    /**
     * دریافت لیست کاربران در عمق خاص
     */
    private function getReferralsAtDepth(int $userId, int $depth): array
    {
        if ($depth == 0) {
            return [$userId];
        }

        if ($depth == 1) {
            $stmt = $this->db->prepare("
                SELECT id FROM users 
                WHERE referred_by = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        // بازگشتی برای عمق بیشتر
        $previousDepth = $this->getReferralsAtDepth($userId, $depth - 1);
        
        if (empty($previousDepth)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($previousDepth), '?'));
        $stmt = $this->db->prepare("
            SELECT id FROM users 
            WHERE referred_by IN ($placeholders) AND deleted_at IS NULL
        ");
        $stmt->execute($previousDepth);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
