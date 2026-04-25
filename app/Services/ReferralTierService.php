<?php

namespace App\Services;

use Core\Database;

/**
 * ReferralTierService
 * 
 * مدیریت سطوح رفرال (Bronze, Silver, Gold, Platinum, Diamond)
 * ارتقای خودکار بر اساس عملکرد معرف
 * محاسبه commission boost برای هر سطح
 */
class ReferralTierService
{
    private Database $db;
    private NotificationService $notificationService;

    public function __construct(
        Database $db,
        NotificationService $notificationService
    ) {
        $this->db = $db;
        $this->notificationService = $notificationService;
    }

    /**
     * دریافت سطح فعلی کاربر
     */
    public function getCurrentTier(int $userId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT rt.*, urt.achieved_at, urt.active_referrals_count, urt.total_earned
            FROM user_referral_tiers urt
            JOIN referral_tiers rt ON rt.id = urt.tier_id
            WHERE urt.user_id = ? AND urt.is_current = TRUE
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $tier = $stmt->fetch(\PDO::FETCH_OBJ);

        // اگر سطحی نداره، سطح Bronze رو بهش بده
        if (!$tier) {
            return $this->assignInitialTier($userId);
        }

        return $tier;
    }

    /**
     * تخصیص سطح اولیه (Bronze) به کاربر جدید
     */
    private function assignInitialTier(int $userId): ?object
    {
        $bronzeTier = $this->getTierBySlug('bronze');
        if (!$bronzeTier) {
            return null;
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO user_referral_tiers 
                (user_id, tier_id, active_referrals_count, total_earned, is_current)
                VALUES (?, ?, 0, 0, TRUE)
            ");
            $stmt->execute([$userId, $bronzeTier->id]);

            $this->db->prepare("
                UPDATE users SET current_referral_tier_id = ? WHERE id = ?
            ")->execute([$bronzeTier->id, $userId]);

            $this->db->commit();

            return $bronzeTier;

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to assign initial tier', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * بررسی و ارتقای سطح کاربر
     */
    public function checkAndUpgrade(int $userId): ?object
    {
        // استفاده از stored procedure موجود در database
        $stmt = $this->db->prepare("CALL sp_update_user_referral_tier(?)");
        $stmt->execute([$userId]);

        // بررسی آیا ارتقا انجام شد
        $newTier = $this->getCurrentTier($userId);

        // اگر tier جدید باشه، نوتیفیکیشن بفرست
        if ($newTier && $newTier->slug !== 'bronze') {
            $this->sendTierUpgradeNotification($userId, $newTier);
        }

        return $newTier;
    }

    /**
     * محاسبه درصد افزایش کمیسیون بر اساس سطح
     */
    public function getCommissionBoost(int $userId): float
    {
        $tier = $this->getCurrentTier($userId);
        return $tier ? (float) $tier->commission_boost_percent : 0;
    }

    /**
     * محاسبه درصد نهایی کمیسیون با اعمال boost
     */
    public function calculateFinalCommissionPercent(
        int $userId,
        float $basePercent
    ): float {
        $boost = $this->getCommissionBoost($userId);
        return $basePercent + $boost;
    }

    /**
     * لیست تمام سطوح فعال
     */
    public function getAllTiers(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM referral_tiers
            WHERE is_active = TRUE
            ORDER BY display_order ASC
        ");

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    /**
     * دریافت سطح بر اساس slug
     */
    public function getTierBySlug(string $slug): ?object
    {
        $stmt = $this->db->prepare("
            SELECT * FROM referral_tiers
            WHERE slug = ? AND is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([$slug]);

        $tier = $stmt->fetch(\PDO::FETCH_OBJ);
        return $tier ?: null;
    }

    /**
     * پیش‌بینی سطح بعدی کاربر و فاصله تا آن
     */
    public function getNextTierProgress(int $userId): ?array
    {
        $currentTier = $this->getCurrentTier($userId);
        if (!$currentTier) {
            return null;
        }

        // پیدا کردن سطح بعدی
        $stmt = $this->db->prepare("
            SELECT * FROM referral_tiers
            WHERE is_active = TRUE
              AND display_order > ?
            ORDER BY display_order ASC
            LIMIT 1
        ");
        $stmt->execute([$currentTier->display_order]);
        $nextTier = $stmt->fetch(\PDO::FETCH_OBJ);

        if (!$nextTier) {
            return [
                'has_next' => false,
                'is_max_tier' => true,
                'current_tier' => $currentTier
            ];
        }

        // آمار فعلی کاربر
        $stats = $this->getUserStats($userId);

        // محاسبه پیشرفت
        $referralsProgress = $stats->active_referrals_count >= $nextTier->min_referrals
            ? 100
            : ($stats->active_referrals_count / $nextTier->min_referrals) * 100;

        $earningsProgress = $stats->total_earned >= $nextTier->min_total_earned
            ? 100
            : ($stats->total_earned / $nextTier->min_total_earned) * 100;

        return [
            'has_next' => true,
            'is_max_tier' => false,
            'current_tier' => $currentTier,
            'next_tier' => $nextTier,
            'current_stats' => $stats,
            'progress' => [
                'referrals' => [
                    'current' => $stats->active_referrals_count,
                    'required' => $nextTier->min_referrals,
                    'remaining' => max(0, $nextTier->min_referrals - $stats->active_referrals_count),
                    'percent' => min(100, round($referralsProgress, 2))
                ],
                'earnings' => [
                    'current' => $stats->total_earned,
                    'required' => $nextTier->min_total_earned,
                    'remaining' => max(0, $nextTier->min_total_earned - $stats->total_earned),
                    'percent' => min(100, round($earningsProgress, 2))
                ],
                'overall_percent' => min(100, round(($referralsProgress + $earningsProgress) / 2, 2))
            ]
        ];
    }

    /**
     * دریافت آمار کاربر برای محاسبه سطح
     */
    private function getUserStats(int $userId): object
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN ref.status = 'active' THEN ref.id END) as active_referrals_count,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned
            FROM users u
            LEFT JOIN users ref ON ref.referred_by = u.id AND ref.deleted_at IS NULL
            LEFT JOIN referral_commissions rc ON rc.referrer_id = u.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);

        return $stmt->fetch(\PDO::FETCH_OBJ) ?: (object)[
            'active_referrals_count' => 0,
            'total_earned' => 0
        ];
    }

    /**
     * ارسال نوتیفیکیشن ارتقای سطح
     */
    private function sendTierUpgradeNotification(int $userId, object $tier): void
    {
        try {
            $message = sprintf(
                '🎉 تبریک! شما به سطح %s ارتقا یافتید. درصد کمیسیون شما %s%% افزایش یافت!',
                $tier->name_fa,
                $tier->commission_boost_percent
            );

            $this->notificationService->create(
                $userId,
                'tier_upgrade',
                'ارتقای سطح رفرال',
                $message,
                [
                    'tier_id' => $tier->id,
                    'tier_slug' => $tier->slug,
                    'tier_name' => $tier->name_fa,
                    'boost_percent' => $tier->commission_boost_percent
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to send tier upgrade notification', [
                'user_id' => $userId,
                'tier_id' => $tier->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * دریافت تاریخچه سطوح کاربر
     */
    public function getUserTierHistory(int $userId, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT urt.*, rt.slug, rt.name_fa, rt.name_en, rt.commission_boost_percent
            FROM user_referral_tiers urt
            JOIN referral_tiers rt ON rt.id = urt.tier_id
            WHERE urt.user_id = ?
            ORDER BY urt.achieved_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * دریافت مزایای ویژه سطح فعلی
     */
    public function getCurrentPerks(int $userId): array
    {
        $tier = $this->getCurrentTier($userId);
        if (!$tier || !$tier->special_perks) {
            return [];
        }

        $perks = json_decode($tier->special_perks, true);
        return $perks['features'] ?? [];
    }

    /**
     * بررسی دسترسی به یک ویژگی خاص
     */
    public function hasFeature(int $userId, string $feature): bool
    {
        $perks = $this->getCurrentPerks($userId);
        return in_array($feature, $perks, true);
    }

    /**
     * آمار کلی سطوح (برای ادمین)
     */
    public function getGlobalTierStats(): array
    {
        $stmt = $this->db->query("
            SELECT 
                rt.slug,
                rt.name_fa,
                rt.display_order,
                COUNT(DISTINCT urt.user_id) as user_count,
                COALESCE(AVG(urt.total_earned), 0) as avg_earnings,
                COALESCE(SUM(urt.total_earned), 0) as total_earnings
            FROM referral_tiers rt
            LEFT JOIN user_referral_tiers urt ON urt.tier_id = rt.id AND urt.is_current = TRUE
            WHERE rt.is_active = TRUE
            GROUP BY rt.id
            ORDER BY rt.display_order ASC
        ");

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }
}
