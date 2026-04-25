<?php

namespace App\Services;

use Core\Database;

/**
 * ReferralQualityScoreService
 * 
 * ارزیابی کیفیت رفرال‌های هر معرف
 * بر اساس: نرخ فعال‌سازی، retention، درآمدزایی، رفتار مشکوک
 */
class ReferralQualityScoreService
{
    private const MIN_SCORE = 0;
    private const MAX_SCORE = 100;
    private const INITIAL_SCORE = 50;

    // وزن‌های محاسبه
    private const WEIGHT_ACTIVATION = 25;    // نرخ فعال‌سازی
    private const WEIGHT_RETENTION = 25;     // نرخ ماندگاری
    private const WEIGHT_EARNINGS = 25;      // متوسط درآمدزایی
    private const WEIGHT_LEGITIMACY = 25;    // عدم تقلب

    private Database $db;
    private UserScoreService $scoreService;

    public function __construct(Database $db, UserScoreService $scoreService)
    {
        $this->db = $db;
        $this->scoreService = $scoreService;
    }

    /**
     * دریافت Quality Score فعلی کاربر
     */
    public function getScore(int $userId): float
    {
        $stmt = $this->db->prepare("
            SELECT referral_quality_score FROM users WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$userId]);
        $score = $stmt->fetchColumn();

        if ($score === false || $score === null) {
            return self::INITIAL_SCORE;
        }

        return $this->clamp((float) $score);
    }

    /**
     * محاسبه و بروزرسانی Quality Score
     */
    public function calculate(int $userId): float
    {
        $metrics = $this->gatherMetrics($userId);

        // محاسبه هر بخش
        $activationScore = $this->calculateActivationScore($metrics);
        $retentionScore = $this->calculateRetentionScore($metrics);
        $earningsScore = $this->calculateEarningsScore($metrics);
        $legitimacyScore = $this->calculateLegitimacyScore($userId, $metrics);

        // محاسبه امتیاز نهایی (weighted average)
        $finalScore = (
            ($activationScore * self::WEIGHT_ACTIVATION) +
            ($retentionScore * self::WEIGHT_RETENTION) +
            ($earningsScore * self::WEIGHT_EARNINGS) +
            ($legitimacyScore * self::WEIGHT_LEGITIMACY)
        ) / 100;

        $finalScore = $this->clamp($finalScore);

        // بروزرسانی در دیتابیس
        $this->updateScore($userId, $finalScore);

        // ثبت event در UserScoreService
        $delta = $finalScore - $this->getScore($userId);
        if (abs($delta) > 0.1) {
            $this->scoreService->applyEventDelta(
                $userId,
                'referral_quality',
                $delta,
                'quality_recalculation',
                [
                    'activation' => round($activationScore, 2),
                    'retention' => round($retentionScore, 2),
                    'earnings' => round($earningsScore, 2),
                    'legitimacy' => round($legitimacyScore, 2),
                    'final' => round($finalScore, 2)
                ]
            );
        }

        $this->logger->info('Referral quality score calculated', [
            'user_id' => $userId,
            'score' => round($finalScore, 2),
            'breakdown' => [
                'activation' => round($activationScore, 2),
                'retention' => round($retentionScore, 2),
                'earnings' => round($earningsScore, 2),
                'legitimacy' => round($legitimacyScore, 2)
            ]
        ]);

        return $finalScore;
    }

    /**
     * جمع‌آوری metrics برای محاسبه
     */
    private function gatherMetrics(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT u.id) as total_referrals,
                COUNT(DISTINCT CASE WHEN u.status = 'active' THEN u.id END) as active_referrals,
                COUNT(DISTINCT CASE WHEN u.completed_tasks_count > 0 THEN u.id END) as activated_referrals,
                COUNT(DISTINCT CASE WHEN u.last_active_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN u.id END) as active_last_30d,
                COALESCE(AVG(u.completed_tasks_count), 0) as avg_tasks_per_referral,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earnings,
                COUNT(DISTINCT CASE WHEN u.fraud_score >= 50 THEN u.id END) as suspicious_referrals,
                COUNT(DISTINCT CASE WHEN u.status = 'banned' OR u.is_blacklisted = 1 THEN u.id END) as banned_referrals
            FROM users u
            LEFT JOIN referral_commissions rc ON rc.referred_id = u.id
            WHERE u.referred_by = ? AND u.deleted_at IS NULL
        ");
        $stmt->execute([$userId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$data || $data['total_referrals'] == 0) {
            return [
                'total_referrals' => 0,
                'active_referrals' => 0,
                'activated_referrals' => 0,
                'active_last_30d' => 0,
                'avg_tasks_per_referral' => 0,
                'total_earnings' => 0,
                'suspicious_referrals' => 0,
                'banned_referrals' => 0
            ];
        }

        return $data;
    }

    /**
     * امتیاز نرخ فعال‌سازی (چند نفر حداقل 1 تسک انجام دادند)
     */
    private function calculateActivationScore(array $metrics): float
    {
        if ($metrics['total_referrals'] == 0) {
            return self::INITIAL_SCORE;
        }

        $activationRate = ($metrics['activated_referrals'] / $metrics['total_referrals']) * 100;

        // نرخ بالای 80% = امتیاز 100
        // نرخ 50% = امتیاز 62.5
        // نرخ 20% = امتیاز 25
        return min(100, $activationRate * 1.25);
    }

    /**
     * امتیاز ماندگاری (چند نفر هنوز فعال هستند)
     */
    private function calculateRetentionScore(array $metrics): float
    {
        if ($metrics['total_referrals'] == 0) {
            return self::INITIAL_SCORE;
        }

        $retentionRate = ($metrics['active_last_30d'] / $metrics['total_referrals']) * 100;

        // نرخ بالای 70% = امتیاز 100
        // نرخ 35% = امتیاز 50
        return min(100, $retentionRate * 1.43);
    }

    /**
     * امتیاز درآمدزایی (میانگین کمیسیون هر رفرال)
     */
    private function calculateEarningsScore(array $metrics): float
    {
        if ($metrics['total_referrals'] == 0) {
            return self::INITIAL_SCORE;
        }

        $avgEarnings = $metrics['total_earnings'] / $metrics['total_referrals'];

        // میانگین بالای 50,000 تومان = امتیاز 100
        // میانگین 25,000 تومان = امتیاز 50
        $threshold = (float) setting('referral_quality_earnings_threshold', 50000);
        
        return min(100, ($avgEarnings / $threshold) * 100);
    }

    /**
     * امتیاز legitimacy (عدم تقلب)
     */
    private function calculateLegitimacyScore(int $userId, array $metrics): float
    {
        $score = 100;

        // کسر امتیاز بابت رفرال‌های مشکوک
        if ($metrics['total_referrals'] > 0) {
            $suspiciousRate = ($metrics['suspicious_referrals'] / $metrics['total_referrals']) * 100;
            $score -= ($suspiciousRate * 0.5); // هر 1% مشکوک = -0.5 امتیاز
        }

        // کسر امتیاز بابت رفرال‌های بن شده
        if ($metrics['total_referrals'] > 0) {
            $bannedRate = ($metrics['banned_referrals'] / $metrics['total_referrals']) * 100;
            $score -= ($bannedRate * 2); // هر 1% بن شده = -2 امتیاز
        }

        // کسر امتیاز بابت Fraud Score خود معرف
        $userFraudScore = $this->scoreService->getFraudScore($userId);
        $score -= ($userFraudScore * 0.3); // هر 1 امتیاز fraud = -0.3 امتیاز

        // بررسی Anti-Farming violations
        $farmingCount = $this->countFarmingViolations($userId);
        $score -= ($farmingCount * 5); // هر تخلف = -5 امتیاز

        return $this->clamp($score);
    }

    /**
     * شمارش تخلفات farming
     */
    private function countFarmingViolations(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM referral_activity_logs
            WHERE referrer_id = ?
              AND action = 'farming_detected'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        ");
        $stmt->execute([$userId]);
        
        return (int) $stmt->fetchColumn();
    }

    /**
     * بروزرسانی امتیاز در دیتابیس
     */
    private function updateScore(int $userId, float $score): void
    {
        $stmt = $this->db->prepare("
            UPDATE users SET referral_quality_score = ? WHERE id = ?
        ");
        $stmt->execute([$score, $userId]);
    }

    /**
     * جریمه کاهش امتیاز (برای رفتارهای بد)
     */
    public function penalize(int $userId, float $amount, string $reason): float
    {
        $currentScore = $this->getScore($userId);
        $newScore = $this->clamp($currentScore - $amount);
        
        $this->updateScore($userId, $newScore);

        $this->scoreService->applyEventDelta(
            $userId,
            'referral_quality',
            -$amount,
            'penalty',
            ['reason' => $reason, 'old_score' => $currentScore, 'new_score' => $newScore]
        );

        $this->logger->warning('Referral quality score penalized', [
            'user_id' => $userId,
            'amount' => $amount,
            'reason' => $reason,
            'old_score' => $currentScore,
            'new_score' => $newScore
        ]);

        return $newScore;
    }

    /**
     * پاداش افزایش امتیاز (برای رفتارهای خوب)
     */
    public function reward(int $userId, float $amount, string $reason): float
    {
        $currentScore = $this->getScore($userId);
        $newScore = $this->clamp($currentScore + $amount);
        
        $this->updateScore($userId, $newScore);

        $this->scoreService->applyEventDelta(
            $userId,
            'referral_quality',
            $amount,
            'reward',
            ['reason' => $reason, 'old_score' => $currentScore, 'new_score' => $newScore]
        );

        return $newScore;
    }

    /**
     * دریافت تفسیر امتیاز
     */
    public function getScoreInterpretation(float $score): array
    {
        if ($score >= 80) {
            return [
                'level' => 'excellent',
                'label' => 'عالی',
                'color' => 'green',
                'description' => 'رفرال‌های شما کیفیت بسیار بالایی دارند'
            ];
        } elseif ($score >= 60) {
            return [
                'level' => 'good',
                'label' => 'خوب',
                'color' => 'blue',
                'description' => 'رفرال‌های شما کیفیت خوبی دارند'
            ];
        } elseif ($score >= 40) {
            return [
                'level' => 'average',
                'label' => 'متوسط',
                'color' => 'yellow',
                'description' => 'کیفیت رفرال‌ها قابل بهبود است'
            ];
        } elseif ($score >= 20) {
            return [
                'level' => 'poor',
                'label' => 'ضعیف',
                'color' => 'orange',
                'description' => 'کیفیت رفرال‌ها نیاز به توجه دارد'
            ];
        } else {
            return [
                'level' => 'critical',
                'label' => 'بحرانی',
                'color' => 'red',
                'description' => 'کیفیت رفرال‌ها بسیار پایین است'
            ];
        }
    }

    /**
     * دریافت پیشنهادات بهبود
     */
    public function getImprovementSuggestions(int $userId): array
    {
        $metrics = $this->gatherMetrics($userId);
        $suggestions = [];

        if ($metrics['total_referrals'] == 0) {
            $suggestions[] = [
                'type' => 'info',
                'message' => 'هنوز رفرالی ندارید. با معرفی کاربران جدید شروع کنید'
            ];
            return $suggestions;
        }

        // نرخ فعال‌سازی پایین
        $activationRate = ($metrics['activated_referrals'] / $metrics['total_referrals']) * 100;
        if ($activationRate < 50) {
            $suggestions[] = [
                'type' => 'activation',
                'message' => 'تنها ' . round($activationRate, 1) . '% از رفرال‌های شما فعال شده‌اند. سعی کنید کاربران واقعا علاقه‌مند را دعوت کنید',
                'action' => 'راهنمای کاربران جدید را به اشتراک بگذارید'
            ];
        }

        // نرخ ماندگاری پایین
        $retentionRate = ($metrics['active_last_30d'] / $metrics['total_referrals']) * 100;
        if ($retentionRate < 40) {
            $suggestions[] = [
                'type' => 'retention',
                'message' => 'فقط ' . round($retentionRate, 1) . '% از رفرال‌ها در ماه اخیر فعال بوده‌اند',
                'action' => 'با رفرال‌های خود در ارتباط باشید و آن‌ها را تشویق کنید'
            ];
        }

        // درآمدزایی پایین
        $avgEarnings = $metrics['total_referrals'] > 0 ? $metrics['total_earnings'] / $metrics['total_referrals'] : 0;
        if ($avgEarnings < 20000) {
            $suggestions[] = [
                'type' => 'earnings',
                'message' => 'میانگین درآمد هر رفرال ' . number_format($avgEarnings) . ' تومان است',
                'action' => 'رفرال‌های خود را به فعالیت‌های پردرآمدتر راهنمایی کنید'
            ];
        }

        // رفرال‌های مشکوک
        if ($metrics['suspicious_referrals'] > 0) {
            $suggestions[] = [
                'type' => 'warning',
                'message' => $metrics['suspicious_referrals'] . ' نفر از رفرال‌های شما فعالیت مشکوکی دارند',
                'action' => 'از دعوت کاربران غیرواقعی یا متقلب خودداری کنید'
            ];
        }

        if (empty($suggestions)) {
            $suggestions[] = [
                'type' => 'success',
                'message' => 'عملکرد شما عالی است! به همین روند ادامه دهید'
            ];
        }

        return $suggestions;
    }

    /**
     * محدود کردن امتیاز به بازه مجاز
     */
    private function clamp(float $value): float
    {
        return max(self::MIN_SCORE, min(self::MAX_SCORE, $value));
    }

    /**
     * بروزرسانی خودکار برای همه کاربران (Cron Job)
     */
    public function batchRecalculate(int $limit = 100): int
    {
        // کاربرانی که حداقل 1 رفرال دارند
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.id
            FROM users u
            INNER JOIN users ref ON ref.referred_by = u.id AND ref.deleted_at IS NULL
            WHERE u.deleted_at IS NULL
            ORDER BY u.id ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $users = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($users as $userId) {
            try {
                $this->calculate($userId);
                $count++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to calculate quality score', [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Batch quality score calculation completed', [
            'processed' => $count,
            'total' => count($users)
        ]);

        return $count;
    }
}
