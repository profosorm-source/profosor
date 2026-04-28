<?php

namespace App\Models;

use Core\Model;

class TaskRating extends Model
{
    /**
     * یافتن یک رتبه‌بندی
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   u.full_name AS rater_name,
                   ct.title AS task_title
            FROM task_ratings r
            LEFT JOIN users u ON u.id = r.rater_id
            LEFT JOIN custom_tasks ct ON ct.id = r.task_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /**
     * ایجاد رتبه‌بندی جدید
     */
    public function create(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO task_ratings
            (task_id, submission_id, rater_id, rated_user_id, rating_type, 
             rating, review_text, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $result = $stmt->execute([
            $data['task_id'],
            $data['submission_id'],
            $data['rater_id'],
            $data['rated_user_id'],
            $data['rating_type'], // 'worker' or 'creator'
            $data['rating'],
            $data['review_text'] ?? null,
        ]);

        if (!$result) {
            return null;
        }

        return $this->find((int) $this->db->lastInsertId());
    }

    /**
     * بررسی آیا قبلاً امتیاز داده شده
     */
    public function hasRated(int $submissionId, int $raterId, string $ratingType): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM task_ratings
            WHERE submission_id = ? AND rater_id = ? AND rating_type = ?
        ");
        $stmt->execute([$submissionId, $raterId, $ratingType]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * محاسبه میانگین امتیاز یک کاربر
     */
    public function getAverageRating(int $userId, string $ratingType): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_ratings,
                AVG(rating) as average_rating,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
            FROM task_ratings
            WHERE rated_user_id = ? AND rating_type = ?
        ");
        $stmt->execute([$userId, $ratingType]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total' => (int) $result['total_ratings'],
            'average' => round((float) $result['average_rating'], 2),
            'distribution' => [
                5 => (int) $result['five_star'],
                4 => (int) $result['four_star'],
                3 => (int) $result['three_star'],
                2 => (int) $result['two_star'],
                1 => (int) $result['one_star'],
            ]
        ];
    }

    /**
     * دریافت نظرات یک کاربر
     */
    public function getUserRatings(int $userId, string $ratingType, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   u.full_name AS rater_name,
                   ct.title AS task_title
            FROM task_ratings r
            LEFT JOIN users u ON u.id = r.rater_id
            LEFT JOIN custom_tasks ct ON ct.id = r.task_id
            WHERE r.rated_user_id = ? AND r.rating_type = ?
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $ratingType, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت نظرات یک تسک
     */
    public function getTaskRatings(int $taskId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   u.full_name AS rater_name
            FROM task_ratings r
            LEFT JOIN users u ON u.id = r.rater_id
            WHERE r.task_id = ?
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$taskId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * محاسبه امتیاز کیفیت کاربر (برای سیستم اعتماد)
     */
    public function calculateQualityScore(int $userId, string $ratingType): float
    {
        $stats = $this->getAverageRating($userId, $ratingType);
        
        if ($stats['total'] == 0) {
            return 50.0; // امتیاز پیش‌فرض برای کاربران جدید
        }

        // فرمول: (average * 20) با وزن تعداد نظرات
        $baseScore = $stats['average'] * 20;
        
        // اضافه کردن بونوس برای تعداد نظرات بالا
        $reviewBonus = min(10, $stats['total'] / 10);
        
        return min(100, round($baseScore + $reviewBonus, 2));
    }
}
