<?php

namespace App\Services;

use Core\Database;
use Core\Cache;

/**
 * سرویس تحلیل و آمار Custom Tasks
 */
class CustomTaskAnalyticsService
{
    private Database $db;
    private Cache $cache;

    public function __construct(Database $db, Cache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * ثبت بازدید تسک
     */
    public function recordView(int $taskId, int $userId): void
    {
        // افزایش شمارنده کلی
        $this->db->prepare("
            UPDATE custom_tasks 
            SET view_count = view_count + 1 
            WHERE id = ?
        ")->execute([$taskId]);

        // ثبت در آمار روزانه
        $this->db->prepare("
            INSERT INTO task_analytics (task_id, date, views)
            VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE views = views + 1
        ")->execute([$taskId]);
    }

    /**
     * ثبت شروع تسک
     */
    public function recordStart(int $taskId): void
    {
        $this->db->prepare("
            INSERT INTO task_analytics (task_id, date, starts)
            VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE starts = starts + 1
        ")->execute([$taskId]);
    }

    /**
     * ثبت ارسال مدرک
     */
    public function recordSubmission(int $taskId): void
    {
        $this->db->prepare("
            INSERT INTO task_analytics (task_id, date, submissions)
            VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE submissions = submissions + 1
        ")->execute([$taskId]);
    }

    /**
     * ثبت تایید
     */
    public function recordApproval(int $taskId): void
    {
        $this->db->prepare("
            INSERT INTO task_analytics (task_id, date, approvals)
            VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE approvals = approvals + 1
        ")->execute([$taskId]);
    }

    /**
     * ثبت رد
     */
    public function recordRejection(int $taskId): void
    {
        $this->db->prepare("
            INSERT INTO task_analytics (task_id, date, rejections)
            VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE rejections = rejections + 1
        ")->execute([$taskId]);
    }

    /**
     * دریافت آمار کامل یک تسک
     */
    public function getTaskStats(int $taskId, int $days = 30): array
    {
        $cacheKey = "task_stats_{$taskId}_{$days}";
        
        return $this->cache->remember($cacheKey, 300, function () use ($taskId, $days) {
            // آمار کلی
            $stmt = $this->db->prepare("
                SELECT * FROM task_stats_view WHERE id = ?
            ");
            $stmt->execute([$taskId]);
            $overall = $stmt->fetch(\PDO::FETCH_ASSOC);

            // آمار روزانه
            $stmt = $this->db->prepare("
                SELECT 
                    date,
                    views,
                    starts,
                    submissions,
                    approvals,
                    rejections,
                    CASE 
                        WHEN submissions > 0 
                        THEN ROUND((approvals / submissions) * 100, 2)
                        ELSE 0 
                    END as approval_rate
                FROM task_analytics
                WHERE task_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                ORDER BY date DESC
            ");
            $stmt->execute([$taskId, $days]);
            $daily = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // نرخ تبدیل (conversion funnel)
            $funnel = [
                'views' => (int) $overall['view_count'],
                'starts' => (int) $overall['unique_workers'],
                'submissions' => (int) ($overall['approved_count'] + $overall['rejected_count']),
                'approvals' => (int) $overall['approved_count'],
            ];

            $funnel['view_to_start'] = $funnel['views'] > 0 
                ? round(($funnel['starts'] / $funnel['views']) * 100, 2) 
                : 0;
            
            $funnel['start_to_submit'] = $funnel['starts'] > 0 
                ? round(($funnel['submissions'] / $funnel['starts']) * 100, 2) 
                : 0;
            
            $funnel['submit_to_approve'] = $funnel['submissions'] > 0 
                ? round(($funnel['approvals'] / $funnel['submissions']) * 100, 2) 
                : 0;

            return [
                'overall' => $overall,
                'daily' => $daily,
                'funnel' => $funnel,
            ];
        });
    }

    /**
     * دریافت آمار داشبورد کاربر (creator)
     */
    public function getCreatorDashboard(int $userId): array
    {
        $cacheKey = "creator_dashboard_{$userId}";
        
        return $this->cache->remember($cacheKey, 600, function () use ($userId) {
            // تعداد تسک‌ها به تفکیک وضعیت
            $stmt = $this->db->prepare("
                SELECT 
                    status,
                    COUNT(*) as count,
                    SUM(total_budget) as total_budget,
                    SUM(spent_budget) as spent_budget
                FROM custom_tasks
                WHERE creator_id = ? AND deleted_at IS NULL
                GROUP BY status
            ");
            $stmt->execute([$userId]);
            $tasksByStatus = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // آمار submission ها
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_submissions,
                    SUM(CASE WHEN s.status = 'submitted' THEN 1 ELSE 0 END) as pending_review,
                    SUM(CASE WHEN s.status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN s.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    AVG(CASE 
                        WHEN s.status = 'approved' 
                        THEN TIMESTAMPDIFF(MINUTE, s.submitted_at, s.reviewed_at)
                        ELSE NULL 
                    END) as avg_review_time_minutes
                FROM custom_task_submissions s
                INNER JOIN custom_tasks t ON t.id = s.task_id
                WHERE t.creator_id = ?
            ");
            $stmt->execute([$userId]);
            $submissions = $stmt->fetch(\PDO::FETCH_ASSOC);

            // میانگین امتیاز دریافتی
            $stmt = $this->db->prepare("
                SELECT 
                    AVG(rating) as avg_rating,
                    COUNT(*) as total_ratings
                FROM task_ratings
                WHERE rated_user_id = ? AND rating_type = 'creator'
            ");
            $stmt->execute([$userId]);
            $rating = $stmt->fetch(\PDO::FETCH_ASSOC);

            return [
                'tasks_by_status' => $tasksByStatus,
                'submissions' => $submissions,
                'rating' => $rating,
            ];
        });
    }

    /**
     * دریافت آمار داشبورد کاربر (worker)
     */
    public function getWorkerDashboard(int $userId): array
    {
        $cacheKey = "worker_dashboard_{$userId}";
        
        return $this->cache->remember($cacheKey, 600, function () use ($userId) {
            // آمار کلی
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_tasks,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status = 'approved' THEN reward_amount ELSE 0 END) as total_earned,
                    AVG(CASE 
                        WHEN status = 'approved' AND completion_time_minutes IS NOT NULL
                        THEN completion_time_minutes
                        ELSE NULL 
                    END) as avg_completion_time
                FROM custom_task_submissions
                WHERE worker_id = ?
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // نرخ تایید
            $stats['approval_rate'] = ($stats['approved'] + $stats['rejected']) > 0
                ? round(($stats['approved'] / ($stats['approved'] + $stats['rejected'])) * 100, 2)
                : 0;

            // میانگین امتیاز دریافتی
            $stmt = $this->db->prepare("
                SELECT 
                    AVG(rating) as avg_rating,
                    COUNT(*) as total_ratings
                FROM task_ratings
                WHERE rated_user_id = ? AND rating_type = 'worker'
            ");
            $stmt->execute([$userId]);
            $rating = $stmt->fetch(\PDO::FETCH_ASSOC);

            // درآمد 30 روز اخیر
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(reviewed_at) as date,
                    SUM(reward_amount) as earned
                FROM custom_task_submissions
                WHERE worker_id = ? 
                AND status = 'approved'
                AND reviewed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(reviewed_at)
                ORDER BY date DESC
            ");
            $stmt->execute([$userId]);
            $earnings = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'stats' => $stats,
                'rating' => $rating,
                'earnings_chart' => $earnings,
            ];
        });
    }

    /**
     * تسک‌های پرطرفدار (محبوب‌ترین)
     */
    public function getTrendingTasks(int $limit = 10): array
    {
        $cacheKey = "trending_tasks_{$limit}";
        
        return $this->cache->remember($cacheKey, 1800, function () use ($limit) {
            $stmt = $this->db->prepare("
                SELECT 
                    t.*,
                    u.full_name AS creator_name,
                    (t.total_quantity - t.completed_count - t.pending_count) AS remaining_count,
                    COALESCE(a.views, 0) as today_views,
                    COALESCE(a.starts, 0) as today_starts
                FROM custom_tasks t
                LEFT JOIN users u ON u.id = t.creator_id
                LEFT JOIN task_analytics a ON a.task_id = t.id AND a.date = CURDATE()
                WHERE t.status = 'active' 
                AND t.deleted_at IS NULL
                AND (t.total_quantity - t.completed_count - t.pending_count) > 0
                ORDER BY 
                    (COALESCE(a.views, 0) * 0.3 + COALESCE(a.starts, 0) * 0.7) DESC,
                    t.average_rating DESC,
                    t.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(\PDO::FETCH_OBJ);
        });
    }

    /**
     * پاک کردن کش آمار
     */
    public function clearCache(int $taskId = null, int $userId = null): void
    {
        if ($taskId) {
            $this->cache->delete("task_stats_{$taskId}_30");
            $this->cache->delete("task_stats_{$taskId}_7");
        }

        if ($userId) {
            $this->cache->delete("creator_dashboard_{$userId}");
            $this->cache->delete("worker_dashboard_{$userId}");
        }

        $this->cache->delete("trending_tasks_10");
    }
}
