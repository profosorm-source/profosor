<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;
use DateTime;

/**
 * AnalyticsService
 * سیستم تحلیل‌ها و داشبورد
 * 
 * Methods:
 * - User metrics (total, active, new, by level)
 * - Transaction metrics (volume, revenue, growth)
 * - Task metrics (social tasks, custom tasks)
 * - Rating metrics (average, distribution)
 * - Revenue analytics (income, expenses, net)
 * - Performance metrics (conversion, retention)
 */
class AnalyticsService
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db     = $db;
        $this->logger = $logger;
    }

    // ─────────────────────────────────────────────────────────────
    // User Analytics
    // ─────────────────────────────────────────────────────────────

    public function getUserMetrics(string $period = 'month'): array
    {
        $dateFilter = $this->getDateFilter($period);

        // Total users
        $totalUsers = $this->db->fetch(
            "SELECT COUNT(*) AS total FROM users"
        )->total ?? 0;

        // Active users (logged in last 7 days)
        $activeUsers = $this->db->fetch(
            "SELECT COUNT(DISTINCT user_id) AS total FROM activity_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->total ?? 0;

        // New users in period
        $newUsers = $this->db->fetch(
            "SELECT COUNT(*) AS total FROM users
             WHERE created_at >= ?",
            [$dateFilter['start']]
        )->total ?? 0;

        // Users by level
        $usersByLevel = $this->db->fetchAll(
            "SELECT level_id, level_name, COUNT(*) AS count
             FROM users u
             LEFT JOIN user_levels ul ON u.level_id = ul.id
             GROUP BY u.level_id, level_name
             ORDER BY count DESC"
        ) ?? [];

        // KYC status distribution
        $kycStatus = $this->db->fetch(
            "SELECT
                SUM(kyc_status = 'verified') AS verified,
                SUM(kyc_status = 'pending') AS pending,
                SUM(kyc_status = 'rejected') AS rejected,
                SUM(kyc_status IS NULL) AS not_submitted
             FROM users"
        );

        return [
            'total_users'     => (int)$totalUsers,
            'active_users'    => (int)$activeUsers,
            'new_users'       => (int)$newUsers,
            'users_by_level'  => $usersByLevel,
            'kyc_verified'    => (int)($kycStatus->verified ?? 0),
            'kyc_pending'     => (int)($kycStatus->pending ?? 0),
            'kyc_rejected'    => (int)($kycStatus->rejected ?? 0),
            'kyc_not_submitted' => (int)($kycStatus->not_submitted ?? 0),
        ];
    }

    public function getUserGrowthChart(int $days = 30): array
    {
        $data = $this->db->fetchAll(
            "SELECT
                DATE(created_at) AS date,
                COUNT(*) AS new_users,
                (SELECT COUNT(*) FROM users WHERE DATE(created_at) <= DATE(u.created_at)) AS cumulative
             FROM users u
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        ) ?? [];

        return $data;
    }

    // ─────────────────────────────────────────────────────────────
    // Transaction Analytics
    // ─────────────────────────────────────────────────────────────

    public function getTransactionMetrics(string $period = 'month'): array
    {
        $dateFilter = $this->getDateFilter($period);

        // Deposits
        $deposits = $this->db->fetch(
            "SELECT
                COUNT(*) AS count,
                SUM(amount) AS total
             FROM deposits
             WHERE status = 'completed' AND created_at >= ?",
            [$dateFilter['start']]
        );

        // Withdrawals
        $withdrawals = $this->db->fetch(
            "SELECT
                COUNT(*) AS count,
                SUM(amount) AS total
             FROM withdrawals
             WHERE status = 'completed' AND created_at >= ?",
            [$dateFilter['start']]
        );

        // Payments (internal transactions)
        $payments = $this->db->fetch(
            "SELECT
                COUNT(*) AS count,
                SUM(amount) AS total
             FROM payments
             WHERE status = 'completed' AND created_at >= ?",
            [$dateFilter['start']]
        );

        // Platform fee earnings
        $platformFee = $this->db->fetch(
            "SELECT SUM(platform_fee) AS total FROM payments
             WHERE status = 'completed' AND created_at >= ?",
            [$dateFilter['start']]
        );

        return [
            'deposits'  => [
                'count'  => (int)($deposits->count ?? 0),
                'amount' => (float)($deposits->total ?? 0),
            ],
            'withdrawals' => [
                'count'  => (int)($withdrawals->count ?? 0),
                'amount' => (float)($withdrawals->total ?? 0),
            ],
            'payments'  => [
                'count'  => (int)($payments->count ?? 0),
                'amount' => (float)($payments->total ?? 0),
            ],
            'platform_fee' => (float)($platformFee->total ?? 0),
            'net_flow'    => (float)(($deposits->total ?? 0) - ($withdrawals->total ?? 0)),
        ];
    }

    public function getTransactionVolumeChart(int $days = 30): array
    {
        return $this->db->fetchAll(
            "SELECT
                DATE(created_at) AS date,
                SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) AS deposits,
                SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) AS withdrawals,
                COUNT(*) AS transactions
             FROM (
                SELECT created_at, 'deposit' AS type, amount FROM deposits WHERE status = 'completed'
                UNION ALL
                SELECT created_at, 'withdrawal' AS type, amount FROM withdrawals WHERE status = 'completed'
                UNION ALL
                SELECT created_at, 'payment' AS type, amount FROM payments WHERE status = 'completed'
             ) transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        ) ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    // Social Task Analytics
    // ─────────────────────────────────────────────────────────────

    public function getSocialTaskMetrics(string $period = 'month'): array
    {
        $dateFilter = $this->getDateFilter($period);

        // Ad statistics
        $ads = $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(max_slots) AS total_slots,
                SUM(reward * max_slots) AS total_budget,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active
             FROM social_ads
             WHERE created_at >= ?",
            [$dateFilter['start']]
        );

        // Execution statistics
        $executions = $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN decision = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN decision = 'soft_approved' THEN 1 ELSE 0 END) AS soft_approved,
                SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN decision = 'pending' THEN 1 ELSE 0 END) AS pending,
                AVG(task_score) AS avg_score,
                SUM(CASE WHEN decision IN ('approved', 'soft_approved') THEN 1 ELSE 0 END) AS successful
             FROM social_task_executions
             WHERE created_at >= ?",
            [$dateFilter['start']]
        );

        // Platform statistics
        $platforms = $this->db->fetchAll(
            "SELECT
                platform,
                COUNT(*) AS count,
                AVG(reward) AS avg_reward
             FROM social_ads
             WHERE created_at >= ?
             GROUP BY platform",
            [$dateFilter['start']]
        ) ?? [];

        $approvalRate = $executions->total > 0 
            ? (($executions->approved + $executions->soft_approved) / $executions->total * 100)
            : 0;

        return [
            'ads' => [
                'total'         => (int)($ads->total ?? 0),
                'total_slots'   => (int)($ads->total_slots ?? 0),
                'total_budget'  => (float)($ads->total_budget ?? 0),
                'active'        => (int)($ads->active ?? 0),
            ],
            'executions' => [
                'total'      => (int)($executions->total ?? 0),
                'approved'   => (int)($executions->approved ?? 0),
                'soft_approved' => (int)($executions->soft_approved ?? 0),
                'rejected'   => (int)($executions->rejected ?? 0),
                'pending'    => (int)($executions->pending ?? 0),
                'avg_score'  => (float)($executions->avg_score ?? 0),
                'approval_rate' => round($approvalRate, 2),
            ],
            'platforms' => $platforms,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Rating Analytics
    // ─────────────────────────────────────────────────────────────

    public function getRatingMetrics(string $period = 'month'): array
    {
        $dateFilter = $this->getDateFilter($period);

        // Rating statistics
        $ratings = $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                AVG(stars) AS avg_stars,
                SUM(CASE WHEN stars = 5 THEN 1 ELSE 0 END) AS five_star,
                SUM(CASE WHEN stars = 4 THEN 1 ELSE 0 END) AS four_star,
                SUM(CASE WHEN stars = 3 THEN 1 ELSE 0 END) AS three_star,
                SUM(CASE WHEN stars = 2 THEN 1 ELSE 0 END) AS two_star,
                SUM(CASE WHEN stars = 1 THEN 1 ELSE 0 END) AS one_star,
                SUM(status = 'approved') AS approved,
                SUM(status = 'pending') AS pending,
                SUM(status = 'rejected') AS rejected
             FROM social_ratings
             WHERE created_at >= ?",
            [$dateFilter['start']]
        );

        // User with highest rating
        $topRated = $this->db->fetch(
            "SELECT u.id, u.full_name, AVG(sr.stars) AS avg_rating, COUNT(*) AS rating_count
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rated_id
             WHERE sr.created_at >= ?
             GROUP BY u.id, u.full_name
             ORDER BY avg_rating DESC
             LIMIT 1",
            [$dateFilter['start']]
        );

        return [
            'total_ratings'  => (int)($ratings->total ?? 0),
            'average_rating' => round((float)($ratings->avg_stars ?? 0), 2),
            'distribution'   => [
                '5_star' => (int)($ratings->five_star ?? 0),
                '4_star' => (int)($ratings->four_star ?? 0),
                '3_star' => (int)($ratings->three_star ?? 0),
                '2_star' => (int)($ratings->two_star ?? 0),
                '1_star' => (int)($ratings->one_star ?? 0),
            ],
            'moderation_status' => [
                'approved' => (int)($ratings->approved ?? 0),
                'pending'  => (int)($ratings->pending ?? 0),
                'rejected' => (int)($ratings->rejected ?? 0),
            ],
            'top_rated_user' => $topRated ? [
                'id'           => $topRated->id,
                'name'         => $topRated->full_name,
                'rating'       => round((float)$topRated->avg_rating, 2),
                'rating_count' => (int)$topRated->rating_count,
            ] : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Custom Task Analytics
    // ─────────────────────────────────────────────────────────────

    public function getCustomTaskMetrics(string $period = 'month'): array
    {
        $dateFilter = $this->getDateFilter($period);

        // Task statistics
        $tasks = $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(max_submissions) AS total_submissions,
                AVG(reward) AS avg_reward,
                SUM(reward * max_submissions) AS total_budget
             FROM custom_tasks
             WHERE created_at >= ?",
            [$dateFilter['start']]
        );

        // Submission statistics
        $submissions = $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(decision = 'approved') AS approved,
                SUM(decision = 'rejected') AS rejected,
                SUM(decision = 'pending') AS pending
             FROM custom_task_submissions
             WHERE created_at >= ?",
            [$dateFilter['start']]
        );

        return [
            'tasks' => [
                'total'              => (int)($tasks->total ?? 0),
                'active'             => (int)($tasks->active ?? 0),
                'total_submissions'  => (int)($tasks->total_submissions ?? 0),
                'avg_reward'         => (float)($tasks->avg_reward ?? 0),
                'total_budget'       => (float)($tasks->total_budget ?? 0),
            ],
            'submissions' => [
                'total'    => (int)($submissions->total ?? 0),
                'approved' => (int)($submissions->approved ?? 0),
                'rejected' => (int)($submissions->rejected ?? 0),
                'pending'  => (int)($submissions->pending ?? 0),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // System Health & Performance
    // ─────────────────────────────────────────────────────────────

    public function getSystemHealth(): array
    {
        // Database size (rough estimation)
        $dbSize = $this->db->fetch(
            "SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()"
        );

        // Recent errors
        $recentErrors = $this->db->fetchAll(
            "SELECT type, COUNT(*) AS count FROM activity_logs
             WHERE level = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY type
             ORDER BY count DESC
             LIMIT 5"
        ) ?? [];

        // API rate limit hits
        $rateLimitHits = $this->db->fetch(
            "SELECT COUNT(*) AS count FROM rate_limits
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND exceeded = 1"
        );

        return [
            'database_size_mb' => (float)($dbSize->size_mb ?? 0),
            'recent_errors'    => $recentErrors,
            'rate_limit_hits'  => (int)($rateLimitHits->count ?? 0),
            'timestamp'        => date('Y-m-d H:i:s'),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Revenue Analytics
    // ─────────────────────────────────────────────────────────────

    public function getRevenueBreakdown(string $period = 'month'): array
    {
        $dateFilter = $this->getDateFilter($period);

        // Platform fees from payments
        $paymentFees = $this->db->fetch(
            "SELECT SUM(platform_fee) AS total FROM payments
             WHERE status = 'completed' AND created_at >= ?",
            [$dateFilter['start']]
        );

        // Referral commission expenses
        $referralCosts = $this->db->fetch(
            "SELECT SUM(amount) AS total FROM referral_commissions
             WHERE status = 'paid' AND created_at >= ?",
            [$dateFilter['start']]
        );

        // Withdrawal fees
        $withdrawalFees = $this->db->fetch(
            "SELECT SUM(fee) AS total FROM withdrawals
             WHERE status = 'completed' AND created_at >= ?",
            [$dateFilter['start']]
        );

        // Investment returns
        $investmentReturns = $this->db->fetch(
            "SELECT SUM(profit) AS total FROM investments
             WHERE status = 'completed' AND created_at >= ?",
            [$dateFilter['start']]
        );

        $totalIncome = ($paymentFees->total ?? 0) + ($withdrawalFees->total ?? 0);
        $totalExpense = ($referralCosts->total ?? 0);
        $netProfit = $totalIncome - $totalExpense;

        return [
            'income' => [
                'payment_fees'     => (float)($paymentFees->total ?? 0),
                'withdrawal_fees'  => (float)($withdrawalFees->total ?? 0),
                'total'            => (float)$totalIncome,
            ],
            'expenses' => [
                'referral_commissions' => (float)($referralCosts->total ?? 0),
                'total'                => (float)$totalExpense,
            ],
            'net_profit' => (float)$netProfit,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Helper Methods
    // ─────────────────────────────────────────────────────────────

    private function getDateFilter(string $period): array
    {
        $end   = new DateTime();
        $start = clone $end;

        match($period) {
            'day'   => $start->modify('-1 day'),
            'week'  => $start->modify('-7 days'),
            'month' => $start->modify('-30 days'),
            'year'  => $start->modify('-365 days'),
            default => $start->modify('-30 days'),
        };

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end'   => $end->format('Y-m-d H:i:s'),
        ];
    }

    public function getComprehensiveDashboard(string $period = 'month'): array
    {
        return [
            'period'           => $period,
            'users'            => $this->getUserMetrics($period),
            'transactions'     => $this->getTransactionMetrics($period),
            'social_tasks'     => $this->getSocialTaskMetrics($period),
            'custom_tasks'     => $this->getCustomTaskMetrics($period),
            'ratings'          => $this->getRatingMetrics($period),
            'revenue'          => $this->getRevenueBreakdown($period),
            'system_health'    => $this->getSystemHealth(),
            'generated_at'     => date('Y-m-d H:i:s'),
        ];
    }
}
