<?php

namespace App\Models;

use Core\Model;

class TaskFavorite extends Model
{
    /**
     * بررسی علاقه‌مندی
     */
    public function isFavorite(int $taskId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_favorites
            WHERE task_id = ? AND user_id = ?
        ");
        $stmt->execute([$taskId, $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * دریافت لیست علاقه‌مندی‌های کاربر
     */
    public function getUserFavorites(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT ct.*, 
                   u.full_name AS creator_name,
                   (ct.total_quantity - ct.completed_count - ct.pending_count) AS remaining_count
            FROM task_favorites tf
            INNER JOIN custom_tasks ct ON ct.id = tf.task_id
            LEFT JOIN users u ON u.id = ct.creator_id
            WHERE tf.user_id = ? AND ct.deleted_at IS NULL
            ORDER BY tf.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تعداد علاقه‌مندی‌های کاربر
     */
    public function countUserFavorites(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM task_favorites tf
            INNER JOIN custom_tasks ct ON ct.id = tf.task_id
            WHERE tf.user_id = ? AND ct.deleted_at IS NULL
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
