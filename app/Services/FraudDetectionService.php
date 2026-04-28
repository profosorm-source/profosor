<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;

/**
 * FraudDetectionService - سیستم تشخیص تقلب پیشرفته
 *
 * محاسبه امتیاز تقلب بر اساس:
 * - سن حساب کاربری
 * - امتیاز شهرت
 * - سرعت تراکنش‌ها
 * - ناهنجاری‌های جغرافیایی
 *
 * اقدامات خودکار:
 * - امتیاز > 50: پرچم برای بررسی
 * - امتیاز > 70: نیاز به KYC
 * - امتیاز > 85: بررسی دستی
 * - امتیاز > 95: تعلیق حساب
 */
class FraudDetectionService
{
    private Database $db;
    private Logger   $logger;

    // آستانه‌های امتیاز تقلب
    private const RISK_THRESHOLDS = [
        'flag'     => 50,
        'kyc'      => 70,
        'review'   => 85,
        'suspend'  => 95
    ];

    // وزن عوامل مختلف در محاسبه امتیاز
    private const WEIGHTS = [
        'account_age'     => 0.2,
        'reputation'      => 0.3,
        'velocity'        => 0.3,
        'geographic'      => 0.2
    ];

    public function __construct(Database $db, Logger $logger)
    {
        $this->db     = $db;
        $this->logger = $logger;
    }

    /**
     * محاسبه امتیاز تقلب برای کاربر
     */
    public function calculateFraudScore(int $userId): int
    {
        $factors = $this->gatherRiskFactors($userId);

        $score = 0;
        $score += $this->calculateAccountAgeFactor($factors['account_age']) * self::WEIGHTS['account_age'];
        $score += $this->calculateReputationFactor($factors['reputation']) * self::WEIGHTS['reputation'];
        $score += $this->calculateVelocityFactor($factors['velocity']) * self::WEIGHTS['velocity'];
        $score += $this->calculateGeographicFactor($factors['geographic']) * self::WEIGHTS['geographic'];

        $finalScore = (int) min(100, max(0, round($score)));

        // بروزرسانی امتیاز در دیتابیس
        $this->updateFraudScore($userId, $finalScore);

        // لاگ کردن محاسبه
        $this->logFraudCalculation($userId, $factors, $finalScore);

        return $finalScore;
    }

    /**
     * جمع‌آوری عوامل ریسک
     */
    private function gatherRiskFactors(int $userId): array
    {
        return [
            'account_age' => $this->getAccountAge($userId),
            'reputation'  => $this->getUserReputation($userId),
            'velocity'    => $this->getTransactionVelocity($userId),
            'geographic'  => $this->getGeographicAnomalies($userId)
        ];
    }

    /**
     * محاسبه عامل سن حساب
     */
    private function calculateAccountAgeFactor(int $days): float
    {
        if ($days < 1) return 100; // حساب جدید
        if ($days < 7) return 80;
        if ($days < 30) return 50;
        if ($days < 90) return 20;
        return 0; // حساب قدیمی
    }

    /**
     * محاسبه عامل شهرت
     */
    private function calculateReputationFactor(int $reputation): float
    {
        if ($reputation < 0) return 100; // شهرت منفی
        if ($reputation < 10) return 80;
        if ($reputation < 50) return 50;
        if ($reputation < 100) return 20;
        return 0; // شهرت بالا
    }

    /**
     * محاسبه عامل سرعت تراکنش
     */
    private function calculateVelocityFactor(array $velocity): float
    {
        $score = 0;

        // بررسی تعداد تراکنش‌های روزانه
        if ($velocity['daily_orders'] > 10) $score += 30;
        elseif ($velocity['daily_orders'] > 5) $score += 15;

        // بررسی تعداد تراکنش‌های هفتگی
        if ($velocity['weekly_orders'] > 50) $score += 40;
        elseif ($velocity['weekly_orders'] > 20) $score += 20;

        // بررسی تغییرات ناگهانی
        if ($velocity['sudden_spike']) $score += 30;

        return min(100, $score);
    }

    /**
     * محاسبه عامل جغرافیایی
     */
    private function calculateGeographicFactor(array $geo): float
    {
        $score = 0;

        // تغییرات سریع کشور
        if ($geo['country_changes'] > 3) $score += 40;
        elseif ($geo['country_changes'] > 1) $score += 20;

        // تغییرات سریع شهر
        if ($geo['city_changes'] > 5) $score += 30;
        elseif ($geo['city_changes'] > 2) $score += 15;

        // IP های مشکوک
        if ($geo['suspicious_ips'] > 0) $score += 30;

        return min(100, $score);
    }

    /**
     * گرفتن سن حساب به روز
     */
    private function getAccountAge(int $userId): int
    {
        $user = $this->db->query(
            "SELECT created_at FROM users WHERE id = ?",
            [$userId]
        )->fetch();

        if (!$user) return 0;

        $created = new \DateTime($user['created_at']);
        $now = new \DateTime();
        $interval = $created->diff($now);

        return $interval->days;
    }

    /**
     * گرفتن امتیاز شهرت کاربر
     */
    private function getUserReputation(int $userId): int
    {
        $reputation = $this->db->query(
            "SELECT COALESCE(SUM(points), 0) as total FROM influencer_reputations WHERE user_id = ?",
            [$userId]
        )->fetch()['total'];

        return (int) $reputation;
    }

    /**
     * گرفتن سرعت تراکنش‌ها
     */
    private function getTransactionVelocity(int $userId): array
    {
        $now = date('Y-m-d H:i:s');
        $dayAgo = date('Y-m-d H:i:s', strtotime('-1 day'));
        $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        // تعداد سفارشات روزانه
        $dailyOrders = $this->db->query(
            "SELECT COUNT(*) as count FROM (
                SELECT id FROM social_task_orders WHERE buyer_id = ? AND created_at >= ?
                UNION ALL
                SELECT id FROM story_orders WHERE buyer_id = ? AND created_at >= ?
                UNION ALL
                SELECT id FROM vitrine_orders WHERE buyer_id = ? AND created_at >= ?
            ) as orders",
            [$userId, $dayAgo, $userId, $dayAgo, $userId, $dayAgo]
        )->fetch()['count'];

        // تعداد سفارشات هفتگی
        $weeklyOrders = $this->db->query(
            "SELECT COUNT(*) as count FROM (
                SELECT id FROM social_task_orders WHERE buyer_id = ? AND created_at >= ?
                UNION ALL
                SELECT id FROM story_orders WHERE buyer_id = ? AND created_at >= ?
                UNION ALL
                SELECT id FROM vitrine_orders WHERE buyer_id = ? AND created_at >= ?
            ) as orders",
            [$userId, $weekAgo, $userId, $weekAgo, $userId, $weekAgo]
        )->fetch()['count'];

        // بررسی تغییرات ناگهانی (مقایسه با هفته قبل)
        $prevWeekStart = date('Y-m-d H:i:s', strtotime('-14 days'));
        $prevWeekEnd = $weekAgo;
        $prevWeeklyOrders = $this->db->query(
            "SELECT COUNT(*) as count FROM (
                SELECT id FROM social_task_orders WHERE buyer_id = ? AND created_at BETWEEN ? AND ?
                UNION ALL
                SELECT id FROM story_orders WHERE buyer_id = ? AND created_at BETWEEN ? AND ?
                UNION ALL
                SELECT id FROM vitrine_orders WHERE buyer_id = ? AND created_at BETWEEN ? AND ?
            ) as orders",
            [$userId, $prevWeekStart, $prevWeekEnd, $userId, $prevWeekStart, $prevWeekEnd, $userId, $prevWeekStart, $prevWeekEnd]
        )->fetch()['count'];

        $suddenSpike = ($weeklyOrders > $prevWeeklyOrders * 2);

        return [
            'daily_orders'  => (int) $dailyOrders,
            'weekly_orders' => (int) $weeklyOrders,
            'sudden_spike'  => $suddenSpike
        ];
    }

    /**
     * گرفتن ناهنجاری‌های جغرافیایی
     */
    private function getGeographicAnomalies(int $userId): array
    {
        $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        // تغییرات کشور
        $countryChanges = $this->db->query(
            "SELECT COUNT(DISTINCT country) as changes 
             FROM user_sessions 
             WHERE user_id = ? AND created_at >= ? AND country IS NOT NULL",
            [$userId, $weekAgo]
        )->fetch()['changes'];

        // تغییرات شهر
        $cityChanges = $this->db->query(
            "SELECT COUNT(DISTINCT city) as changes 
             FROM user_sessions 
             WHERE user_id = ? AND created_at >= ? AND city IS NOT NULL",
            [$userId, $weekAgo]
        )->fetch()['changes'];

        // IP های مشکوک (بررسی در لیست سیاه)
        $suspiciousIps = $this->db->query(
            "SELECT COUNT(*) as count 
             FROM user_sessions us
             JOIN ip_blacklist ib ON us.ip_address = ib.ip_address
             WHERE us.user_id = ? AND us.created_at >= ?",
            [$userId, $weekAgo]
        )->fetch()['count'];

        return [
            'country_changes' => (int) $countryChanges,
            'city_changes'    => (int) $cityChanges,
            'suspicious_ips'  => (int) $suspiciousIps
        ];
    }

    /**
     * بروزرسانی امتیاز تقلب در دیتابیس
     */
    private function updateFraudScore(int $userId, int $score): void
    {
        $this->db->query(
            "UPDATE users SET fraud_score = ?, updated_at = NOW() WHERE id = ?",
            [$score, $userId]
        );
    }

    /**
     * لاگ کردن محاسبه امتیاز تقلب
     */
    private function logFraudCalculation(int $userId, array $factors, int $finalScore): void
    {
        $this->db->query(
            "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, created_at) 
             VALUES (?, 'score_calculation', ?, ?, NOW())",
            [
                $userId,
                $finalScore,
                json_encode($factors, JSON_UNESCAPED_UNICODE)
            ]
        );
    }

    /**
     * اجرای اقدامات خودکار بر اساس امتیاز
     */
    public function executeAutomatedActions(int $userId): array
    {
        $score = $this->calculateFraudScore($userId);
        $actions = [];

        if ($score >= self::RISK_THRESHOLDS['suspend']) {
            $actions[] = $this->suspendAccount($userId, 'High fraud risk score: ' . $score);
        } elseif ($score >= self::RISK_THRESHOLDS['review']) {
            $actions[] = $this->flagForManualReview($userId, $score);
        } elseif ($score >= self::RISK_THRESHOLDS['kyc']) {
            $actions[] = $this->requireKYC($userId, $score);
        } elseif ($score >= self::RISK_THRESHOLDS['flag']) {
            $actions[] = $this->flagForReview($userId, $score);
        }

        return $actions;
    }

    /**
     * پرچم‌گذاری برای بررسی
     */
    private function flagForReview(int $userId, int $score): string
    {
        $this->db->query(
            "UPDATE users SET requires_review = 1, review_reason = ? WHERE id = ?",
            ['Fraud score: ' . $score, $userId]
        );

        $this->logFraudAction($userId, 'flag_for_review', $score, 'User flagged for review due to fraud score');

        return 'flagged_for_review';
    }

    /**
     * نیاز به KYC
     */
    private function requireKYC(int $userId, int $score): string
    {
        $this->db->query(
            "UPDATE users SET requires_kyc = 1, kyc_reason = ? WHERE id = ?",
            ['Fraud score: ' . $score, $userId]
        );

        $this->logFraudAction($userId, 'require_kyc', $score, 'KYC required due to fraud score');

        return 'kyc_required';
    }

    /**
     * پرچم‌گذاری برای بررسی دستی
     */
    private function flagForManualReview(int $userId, int $score): string
    {
        $this->db->query(
            "UPDATE users SET requires_manual_review = 1, manual_review_reason = ? WHERE id = ?",
            ['High fraud score: ' . $score, $userId]
        );

        $this->logFraudAction($userId, 'manual_review', $score, 'Manual review required due to high fraud score');

        return 'manual_review_required';
    }

    /**
     * تعلیق حساب
     */
    private function suspendAccount(int $userId, string $reason): string
    {
        $this->db->query(
            "UPDATE users SET 
             is_blacklisted = 1, 
             blacklist_reason = ?, 
             blacklisted_at = NOW() 
             WHERE id = ?",
            [$reason, $userId]
        );

        $this->logFraudAction($userId, 'account_suspended', 100, $reason);

        return 'account_suspended';
    }

    /**
     * لاگ کردن اقدامات تقلب
     */
    private function logFraudAction(int $userId, string $action, int $score, string $details): void
    {
        $this->db->query(
            "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, action_taken, details, created_at) 
             VALUES (?, 'automated_action', ?, ?, ?, NOW())",
            [$userId, $score, $action, $details]
        );
    }

    /**
     * بررسی اینکه آیا کاربر نیاز به بررسی دارد
     */
    public function requiresReview(int $userId): bool
    {
        $user = $this->db->query(
            "SELECT requires_review, requires_kyc, requires_manual_review, is_blacklisted 
             FROM users WHERE id = ?",
            [$userId]
        )->fetch();

        return $user && (
            $user['requires_review'] || 
            $user['requires_kyc'] || 
            $user['requires_manual_review'] || 
            $user['is_blacklisted']
        );
    }

    /**
     * گرفتن گزارش ریسک کاربر
     */
    public function getRiskReport(int $userId): array
    {
        $score = $this->calculateFraudScore($userId);
        $factors = $this->gatherRiskFactors($userId);

        $user = $this->db->query(
            "SELECT requires_review, requires_kyc, requires_manual_review, is_blacklisted, blacklist_reason 
             FROM users WHERE id = ?",
            [$userId]
        )->fetch();

        return [
            'user_id' => $userId,
            'fraud_score' => $score,
            'risk_factors' => $factors,
            'flags' => [
                'requires_review' => (bool) $user['requires_review'],
                'requires_kyc' => (bool) $user['requires_kyc'],
                'requires_manual_review' => (bool) $user['requires_manual_review'],
                'is_blacklisted' => (bool) $user['is_blacklisted'],
                'blacklist_reason' => $user['blacklist_reason']
            ],
            'thresholds' => self::RISK_THRESHOLDS
        ];
    }
}