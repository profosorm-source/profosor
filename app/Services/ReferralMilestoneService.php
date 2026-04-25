<?php

namespace App\Services;

use Core\Database;

/**
 * ReferralMilestoneService
 * 
 * مدیریت جوایز Milestone رفرال
 * بررسی خودکار و اعطای جوایز بر اساس دستاوردها
 */
class ReferralMilestoneService
{
    private Database $db;
    private WalletService $walletService;
    private NotificationService $notificationService;

    public function __construct(
        Database $db,
        WalletService $walletService,
        NotificationService $notificationService
    ) {
        $this->db = $db;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
    }

    /**
     * بررسی و اعطای تمام milestone های واجد شرایط
     */
    public function checkAndAwardMilestones(int $userId): array
    {
        $awarded = [];

        // دریافت آمار فعلی کاربر
        $stats = $this->getUserStats($userId);

        // بررسی milestone های تعداد رفرال
        $awarded = array_merge(
            $awarded,
            $this->checkMilestoneType($userId, 'referral_count', $stats->total_referrals)
        );

        // بررسی milestone های کل درآمد
        $awarded = array_merge(
            $awarded,
            $this->checkMilestoneType($userId, 'total_earned', $stats->total_earned_irt)
        );

        // بررسی milestone های زیرمجموعه فعال
        $awarded = array_merge(
            $awarded,
            $this->checkMilestoneType($userId, 'active_referrals', $stats->active_referrals)
        );

        return $awarded;
    }

    /**
     * بررسی milestone های یک نوع خاص
     */
    private function checkMilestoneType(
        int $userId,
        string $type,
        float $currentValue
    ): array {
        $awarded = [];

        // پیدا کردن milestone های واجد شرایط که هنوز دریافت نشده
        $stmt = $this->db->prepare("
            SELECT rm.*
            FROM referral_milestones rm
            WHERE rm.milestone_type = ?
              AND rm.threshold_value <= ?
              AND rm.is_active = TRUE
              AND rm.id NOT IN (
                  SELECT milestone_id 
                  FROM user_referral_milestones 
                  WHERE user_id = ?
              )
            ORDER BY rm.threshold_value ASC
        ");
        $stmt->execute([$type, $currentValue, $userId]);
        $milestones = $stmt->fetchAll(\PDO::FETCH_OBJ);

        foreach ($milestones as $milestone) {
            if ($this->awardMilestone($userId, $milestone)) {
                $awarded[] = $milestone;
            }
        }

        return $awarded;
    }

    /**
     * اعطای یک milestone به کاربر
     */
    private function awardMilestone(int $userId, object $milestone): bool
    {
        try {
            $this->db->beginTransaction();

            // ثبت milestone
            $stmt = $this->db->prepare("
                INSERT INTO user_referral_milestones 
                (user_id, milestone_id, metadata)
                VALUES (?, ?, ?)
            ");

            $metadata = json_encode([
                'title' => $milestone->title_fa,
                'reward_type' => $milestone->reward_type,
                'reward_value' => $milestone->reward_value
            ], JSON_UNESCAPED_UNICODE);

            $stmt->execute([$userId, $milestone->id, $metadata]);
            $milestoneRecordId = $this->db->lastInsertId();

            // پرداخت جایزه
            $rewardPaid = false;
            $transactionId = null;

            if ($milestone->reward_type === 'cash' && $milestone->reward_value > 0) {
                $result = $this->walletService->deposit(
                    $userId,
                    $milestone->reward_value,
                    $milestone->reward_currency ?? 'irt',
                    [
                        'type' => 'referral_milestone',
                        'description' => "جایزه Milestone: {$milestone->title_fa}",
                        'milestone_id' => $milestone->id,
                        'ref_id' => $milestoneRecordId,
                        'ref_type' => 'referral_milestone'
                    ]
                );

                if ($result['success']) {
                    $rewardPaid = true;
                    $transactionId = $result['transaction_id'];
                }
            } elseif ($milestone->reward_type === 'bonus_percent') {
                // افزایش موقت درصد کمیسیون (در آینده پیاده می‌شود)
                $rewardPaid = true;
            }

            // بروزرسانی وضعیت پرداخت
            if ($rewardPaid) {
                $this->db->prepare("
                    UPDATE user_referral_milestones
                    SET reward_paid = TRUE, reward_transaction_id = ?
                    WHERE id = ?
                ")->execute([$transactionId, $milestoneRecordId]);
            }

            // ثبت لاگ
            $this->db->prepare("
                INSERT INTO referral_activity_logs 
                (referrer_id, action, metadata)
                VALUES (?, 'milestone_achieved', ?)
            ")->execute([
                $userId,
                json_encode([
                    'milestone_id' => $milestone->id,
                    'title' => $milestone->title_fa,
                    'threshold' => $milestone->threshold_value,
                    'reward_value' => $milestone->reward_value,
                    'reward_paid' => $rewardPaid
                ], JSON_UNESCAPED_UNICODE)
            ]);

            $this->db->commit();

            // ارسال نوتیفیکیشن
            $this->sendMilestoneNotification($userId, $milestone, $rewardPaid);

            $this->logger->info('Milestone awarded', [
                'user_id' => $userId,
                'milestone_id' => $milestone->id,
                'title' => $milestone->title_fa,
                'reward_paid' => $rewardPaid
            ]);

            return true;

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to award milestone', [
                'user_id' => $userId,
                'milestone_id' => $milestone->id ?? null,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت آمار کاربر برای بررسی milestone ها
     */
    private function getUserStats(int $userId): object
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT ref.id) as total_referrals,
                COUNT(DISTINCT CASE WHEN ref.status = 'active' THEN ref.id END) as active_referrals,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned_irt,
                COALESCE(SUM(CASE WHEN rc.currency='usdt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned_usdt
            FROM users u
            LEFT JOIN users ref ON ref.referred_by = u.id AND ref.deleted_at IS NULL
            LEFT JOIN referral_commissions rc ON rc.referrer_id = u.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);

        return $stmt->fetch(\PDO::FETCH_OBJ) ?: (object)[
            'total_referrals' => 0,
            'active_referrals' => 0,
            'total_earned_irt' => 0,
            'total_earned_usdt' => 0
        ];
    }

    /**
     * لیست milestone های دریافت شده کاربر
     */
    public function getUserAchievedMilestones(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT urm.*, rm.title_fa, rm.title_en, rm.description, 
                   rm.badge_icon, rm.reward_type, rm.reward_value
            FROM user_referral_milestones urm
            JOIN referral_milestones rm ON rm.id = urm.milestone_id
            WHERE urm.user_id = ?
            ORDER BY urm.achieved_at DESC
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * لیست milestone های در دسترس (هنوز دریافت نشده)
     */
    public function getAvailableMilestones(int $userId): array
    {
        $stats = $this->getUserStats($userId);

        $stmt = $this->db->prepare("
            SELECT rm.*,
                CASE 
                    WHEN rm.milestone_type = 'referral_count' THEN ?
                    WHEN rm.milestone_type = 'total_earned' THEN ?
                    WHEN rm.milestone_type = 'active_referrals' THEN ?
                    ELSE 0
                END as current_value,
                CASE 
                    WHEN rm.milestone_type = 'referral_count' THEN 
                        CASE WHEN ? >= rm.threshold_value THEN 100 
                        ELSE (? / rm.threshold_value * 100) END
                    WHEN rm.milestone_type = 'total_earned' THEN 
                        CASE WHEN ? >= rm.threshold_value THEN 100 
                        ELSE (? / rm.threshold_value * 100) END
                    WHEN rm.milestone_type = 'active_referrals' THEN 
                        CASE WHEN ? >= rm.threshold_value THEN 100 
                        ELSE (? / rm.threshold_value * 100) END
                    ELSE 0
                END as progress_percent
            FROM referral_milestones rm
            WHERE rm.is_active = TRUE
              AND rm.id NOT IN (
                  SELECT milestone_id 
                  FROM user_referral_milestones 
                  WHERE user_id = ?
              )
            ORDER BY rm.milestone_type ASC, rm.threshold_value ASC
        ");

        $stmt->execute([
            $stats->total_referrals,      // current_value for referral_count
            $stats->total_earned_irt,     // current_value for total_earned
            $stats->active_referrals,     // current_value for active_referrals
            $stats->total_referrals,      // progress calc referral_count
            $stats->total_referrals,
            $stats->total_earned_irt,     // progress calc total_earned
            $stats->total_earned_irt,
            $stats->active_referrals,     // progress calc active_referrals
            $stats->active_referrals,
            $userId
        ]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * دریافت نزدیک‌ترین milestone
     */
    public function getNextMilestone(int $userId): ?object
    {
        $available = $this->getAvailableMilestones($userId);
        
        if (empty($available)) {
            return null;
        }

        // پیدا کردن نزدیک‌ترین milestone (بالاترین progress)
        usort($available, function($a, $b) {
            return $b->progress_percent <=> $a->progress_percent;
        });

        return $available[0];
    }

    /**
     * ارسال نوتیفیکیشن milestone
     */
    private function sendMilestoneNotification(
        int $userId,
        object $milestone,
        bool $rewardPaid
    ): void {
        try {
            $rewardText = '';
            if ($milestone->reward_type === 'cash' && $rewardPaid) {
                $rewardText = sprintf(
                    ' و %s %s جایزه دریافت کردید!',
                    number_format($milestone->reward_value),
                    $milestone->reward_currency === 'usdt' ? 'USDT' : 'تومان'
                );
            }

            $message = sprintf(
                '🎊 تبریک! شما به milestone "%s" دست یافتید%s',
                $milestone->title_fa,
                $rewardText
            );

            $this->notificationService->create(
                $userId,
                'milestone_achieved',
                'دستاورد جدید',
                $message,
                [
                    'milestone_id' => $milestone->id,
                    'title' => $milestone->title_fa,
                    'reward_type' => $milestone->reward_type,
                    'reward_value' => $milestone->reward_value,
                    'reward_paid' => $rewardPaid
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to send milestone notification', [
                'user_id' => $userId,
                'milestone_id' => $milestone->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * آمار کلی milestone ها (برای ادمین)
     */
    public function getGlobalMilestoneStats(): array
    {
        $stmt = $this->db->query("
            SELECT 
                rm.milestone_type,
                rm.title_fa,
                rm.threshold_value,
                COUNT(DISTINCT urm.user_id) as achieved_by_users,
                COUNT(CASE WHEN urm.reward_paid = TRUE THEN 1 END) as rewards_paid,
                COALESCE(SUM(CASE WHEN urm.reward_paid = TRUE THEN rm.reward_value ELSE 0 END), 0) as total_rewards_paid
            FROM referral_milestones rm
            LEFT JOIN user_referral_milestones urm ON urm.milestone_id = rm.id
            WHERE rm.is_active = TRUE
            GROUP BY rm.id
            ORDER BY rm.milestone_type ASC, rm.threshold_value ASC
        ");

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    /**
     * لیست کاربرانی که milestone خاصی را دریافت کرده‌اند
     */
    public function getMilestoneAchievers(int $milestoneId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT urm.*, u.full_name, u.email, u.referral_code
            FROM user_referral_milestones urm
            JOIN users u ON u.id = urm.user_id
            WHERE urm.milestone_id = ?
            ORDER BY urm.achieved_at DESC
            LIMIT ?
        ");
        $stmt->execute([$milestoneId, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }
}
