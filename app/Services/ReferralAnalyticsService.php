<?php

namespace App\Services;

use Core\Database;
use Core\Cache;

/**
 * ReferralAnalyticsService
 * 
 * تحلیل‌های پیشرفته برای سیستم رفرال
 * استفاده از AdvancedAnalyticsService برای تحلیل‌های عمومی
 */
class ReferralAnalyticsService
{
    private Database $db;
    private Cache $cache;
    private AdvancedAnalyticsService $analyticsService;

    public function __construct(
        Database $db,
        Cache $cache,
        AdvancedAnalyticsService $analyticsService
    ) {
        $this->db = $db;
        $this->cache = $cache;
        $this->analyticsService = $analyticsService;
    }

    /**
     * روند رفرال در N روز گذشته
     */
    public function getReferralTrend(int $days = 30): array
    {
        return $this->analyticsService->getTrend(
            'users',
            'created_at',
            $days,
            ['referred_by' => null], // فقط کاربرانی که رفرال شدند (NOT NULL)
            []
        );
    }

    /**
     * روند کمیسیون‌ها در N روز گذشته
     */
    public function getCommissionTrend(int $days = 30, ?string $currency = null): array
    {
        $conditions = $currency ? ['currency' => $currency] : [];
        
        return $this->analyticsService->getTrend(
            'referral_commissions',
            'created_at',
            $days,
            $conditions,
            ['status']
        );
    }

    /**
     * نرخ تبدیل رفرال (Conversion Rate)
     * 
     * چند درصد از لینک‌های کلیک شده منجر به ثبت‌نام شده؟
     */
    public function getConversionRate(int $userId, int $days = 30): array
    {
        $cacheKey = "referral:conversion:{$userId}:{$days}";
        
        return $this->cache->remember($cacheKey, 300, function() use ($userId, $days) {
            // تعداد کلیک‌ها (از لاگ activity)
            $clicksStmt = $this->db->prepare("
                SELECT COUNT(*) as clicks
                FROM referral_activity_logs
                WHERE referrer_id = ?
                  AND action = 'link_click'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $clicksStmt->execute([$userId, $days]);
            $clicks = (int) $clicksStmt->fetchColumn();

            // تعداد ثبت‌نام‌ها
            $signupsStmt = $this->db->prepare("
                SELECT COUNT(*) as signups
                FROM users
                WHERE referred_by = ?
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND deleted_at IS NULL
            ");
            $signupsStmt->execute([$userId, $days]);
            $signups = (int) $signupsStmt->fetchColumn();

            // تعداد کاربران فعال (حداقل 1 تسک انجام داده)
            $activeStmt = $this->db->prepare("
                SELECT COUNT(DISTINCT u.id) as active_users
                FROM users u
                WHERE u.referred_by = ?
                  AND u.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND u.completed_tasks_count > 0
                  AND u.deleted_at IS NULL
            ");
            $activeStmt->execute([$userId, $days]);
            $activeUsers = (int) $activeStmt->fetchColumn();

            return [
                'period_days' => $days,
                'clicks' => $clicks,
                'signups' => $signups,
                'active_users' => $activeUsers,
                'click_to_signup_rate' => $clicks > 0 ? round(($signups / $clicks) * 100, 2) : 0,
                'signup_to_active_rate' => $signups > 0 ? round(($activeUsers / $signups) * 100, 2) : 0,
                'overall_conversion_rate' => $clicks > 0 ? round(($activeUsers / $clicks) * 100, 2) : 0
            ];
        });
    }

    /**
     * تحلیل منابع کمیسیون
     * 
     * کدام منبع بیشترین درآمد را داشته؟
     */
    public function getSourceBreakdown(int $userId): array
    {
        $cacheKey = "referral:sources:{$userId}";
        
        return $this->cache->remember($cacheKey, 600, function() use ($userId) {
            $stmt = $this->db->prepare("
                SELECT 
                    source_type,
                    COUNT(*) as count,
                    SUM(CASE WHEN currency='irt' THEN commission_amount ELSE 0 END) as total_irt,
                    SUM(CASE WHEN currency='usdt' THEN commission_amount ELSE 0 END) as total_usdt,
                    AVG(commission_percent) as avg_commission_percent
                FROM referral_commissions
                WHERE referrer_id = ? AND status = 'paid'
                GROUP BY source_type
                ORDER BY total_irt DESC
            ");
            $stmt->execute([$userId]);

            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        });
    }

    /**
     * تحلیل عملکرد زیرمجموعه‌ها
     * 
     * کدام زیرمجموعه‌ها بهترین عملکرد را دارند؟
     */
    public function getTopPerformingReferrals(int $userId, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                u.id,
                u.full_name,
                u.email,
                u.created_at as joined_at,
                u.status,
                u.completed_tasks_count,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_commission_irt,
                COALESCE(SUM(CASE WHEN rc.currency='usdt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_commission_usdt,
                COUNT(rc.id) as commission_count
            FROM users u
            LEFT JOIN referral_commissions rc ON rc.referred_id = u.id
            WHERE u.referred_by = ? AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY total_commission_irt DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * متوسط ارزش طول عمر (Lifetime Value) هر رفرال
     */
    public function getAverageLTV(int $userId): array
    {
        $cacheKey = "referral:ltv:{$userId}";
        
        return $this->cache->remember($cacheKey, 600, function() use ($userId) {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(DISTINCT u.id) as total_referrals,
                    COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned_irt,
                    COALESCE(SUM(CASE WHEN rc.currency='usdt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned_usdt
                FROM users u
                LEFT JOIN referral_commissions rc ON rc.referred_id = u.id AND rc.referrer_id = ?
                WHERE u.referred_by = ? AND u.deleted_at IS NULL
            ");
            $stmt->execute([$userId, $userId]);
            $data = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$data || $data->total_referrals == 0) {
                return [
                    'total_referrals' => 0,
                    'ltv_irt' => 0,
                    'ltv_usdt' => 0
                ];
            }

            return [
                'total_referrals' => (int) $data->total_referrals,
                'total_earned_irt' => (float) $data->total_earned_irt,
                'total_earned_usdt' => (float) $data->total_earned_usdt,
                'ltv_irt' => round($data->total_earned_irt / $data->total_referrals, 2),
                'ltv_usdt' => round($data->total_earned_usdt / $data->total_referrals, 4)
            ];
        });
    }

    /**
     * نرخ حفظ (Retention Rate)
     * 
     * چند درصد از رفرال‌ها بعد از X روز هنوز فعال هستند؟
     */
    public function getRetentionRate(int $userId, int $afterDays = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN u.last_active_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as still_active
            FROM users u
            WHERE u.referred_by = ?
              AND u.created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND u.deleted_at IS NULL
        ");
        $stmt->execute([$userId, $afterDays]);
        $data = $stmt->fetch(\PDO::FETCH_OBJ);

        $total = $data ? (int) $data->total : 0;
        $stillActive = $data ? (int) $data->still_active : 0;

        return [
            'after_days' => $afterDays,
            'total_referrals' => $total,
            'still_active' => $stillActive,
            'retention_rate' => $total > 0 ? round(($stillActive / $total) * 100, 2) : 0
        ];
    }

    /**
     * نمودار عملکرد ماهانه
     */
    public function getMonthlyPerformance(int $userId, int $months = 6): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(DISTINCT CASE WHEN status='paid' THEN id END) as paid_commissions,
                COUNT(DISTINCT CASE WHEN status='pending' THEN id END) as pending_commissions,
                SUM(CASE WHEN currency='irt' AND status='paid' THEN commission_amount ELSE 0 END) as earned_irt,
                SUM(CASE WHEN currency='usdt' AND status='paid' THEN commission_amount ELSE 0 END) as earned_usdt
            FROM referral_commissions
            WHERE referrer_id = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
            GROUP BY month
            ORDER BY month ASC
        ");
        $stmt->execute([$userId, $months]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * پیش‌بینی درآمد ماه آینده
     * 
     * بر اساس میانگین ۳ ماه گذشته
     */
    public function predictNextMonthEarnings(int $userId): array
    {
        $monthlyData = $this->getMonthlyPerformance($userId, 3);
        
        if (count($monthlyData) < 2) {
            return [
                'predicted_irt' => 0,
                'predicted_usdt' => 0,
                'confidence' => 'low',
                'based_on_months' => count($monthlyData)
            ];
        }

        $totalIrt = 0;
        $totalUsdt = 0;
        
        foreach ($monthlyData as $month) {
            $totalIrt += $month->earned_irt;
            $totalUsdt += $month->earned_usdt;
        }

        $count = count($monthlyData);

        return [
            'predicted_irt' => round($totalIrt / $count, 2),
            'predicted_usdt' => round($totalUsdt / $count, 4),
            'confidence' => $count >= 3 ? 'high' : 'medium',
            'based_on_months' => $count,
            'trend' => $this->calculateTrend($monthlyData)
        ];
    }

    /**
     * محاسبه روند (افزایشی/کاهشی/ثابت)
     */
    private function calculateTrend(array $monthlyData): string
    {
        if (count($monthlyData) < 2) {
            return 'insufficient_data';
        }

        $values = array_map(fn($m) => $m->earned_irt, $monthlyData);
        $first = array_slice($values, 0, ceil(count($values) / 2));
        $second = array_slice($values, ceil(count($values) / 2));

        $avgFirst = array_sum($first) / count($first);
        $avgSecond = array_sum($second) / count($second);

        if ($avgSecond > $avgFirst * 1.1) {
            return 'increasing';
        } elseif ($avgSecond < $avgFirst * 0.9) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }

    /**
     * Dashboard کامل برای معرف
     */
    public function getReferrerDashboard(int $userId): array
    {
        return [
            'conversion' => $this->getConversionRate($userId, 30),
            'source_breakdown' => $this->getSourceBreakdown($userId),
            'top_referrals' => $this->getTopPerformingReferrals($userId, 5),
            'ltv' => $this->getAverageLTV($userId),
            'retention' => $this->getRetentionRate($userId, 30),
            'monthly_performance' => $this->getMonthlyPerformance($userId, 6),
            'prediction' => $this->predictNextMonthEarnings($userId)
        ];
    }

    /**
     * مقایسه عملکرد با میانگین کل سیستم
     */
    public function compareWithAverage(int $userId): array
    {
        // آمار کاربر
        $userLtv = $this->getAverageLTV($userId);
        $userConversion = $this->getConversionRate($userId, 30);

        // میانگین سیستم
        $systemStmt = $this->db->query("
            SELECT 
                AVG(total_referral_earned_irt) as avg_earnings,
                AVG(active_referrals_count) as avg_referrals
            FROM users
            WHERE total_referral_earned_irt > 0
        ");
        $systemAvg = $systemStmt->fetch(\PDO::FETCH_OBJ);

        return [
            'user' => [
                'ltv_irt' => $userLtv['ltv_irt'] ?? 0,
                'conversion_rate' => $userConversion['overall_conversion_rate'] ?? 0
            ],
            'system_average' => [
                'avg_earnings' => $systemAvg ? round($systemAvg->avg_earnings, 2) : 0,
                'avg_referrals' => $systemAvg ? round($systemAvg->avg_referrals, 2) : 0
            ],
            'performance_vs_average' => [
                'earnings' => $this->calculatePerformancePercent(
                    $userLtv['total_earned_irt'] ?? 0,
                    $systemAvg ? $systemAvg->avg_earnings : 0
                ),
                'referrals' => $this->calculatePerformancePercent(
                    $userLtv['total_referrals'] ?? 0,
                    $systemAvg ? $systemAvg->avg_referrals : 0
                )
            ]
        ];
    }

    /**
     * محاسبه درصد عملکرد نسبت به میانگین
     */
    private function calculatePerformancePercent(float $userValue, float $avgValue): float
    {
        if ($avgValue == 0) {
            return 0;
        }

        return round((($userValue - $avgValue) / $avgValue) * 100, 2);
    }
}
