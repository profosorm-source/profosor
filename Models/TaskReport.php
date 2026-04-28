<?php

namespace App\Models;

use Core\Model;

class TaskReport extends Model
{
    /**
     * ایجاد گزارش جدید
     */
    public function create(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO task_reports
            (task_id, reporter_id, reason, description, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
        ");

        $result = $stmt->execute([
            $data['task_id'],
            $data['reporter_id'],
            $data['reason'],
            $data['description'],
        ]);

        if (!$result) {
            return null;
        }

        return $this->find((int) $this->db->lastInsertId());
    }

    /**
     * یافتن یک گزارش
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT r.*,
                   ct.title AS task_title,
                   u.full_name AS reporter_name,
                   a.full_name AS admin_name
            FROM task_reports r
            LEFT JOIN custom_tasks ct ON ct.id = r.task_id
            LEFT JOIN users u ON u.id = r.reporter_id
            LEFT JOIN users a ON a.id = r.admin_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /**
     * بررسی گزارش تکراری
     */
    public function hasPendingReport(int $taskId, int $reporterId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_reports
            WHERE task_id = ? AND reporter_id = ? 
            AND status = 'pending'
        ");
        $stmt->execute([$taskId, $reporterId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * به‌روزرسانی وضعیت گزارش
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = ['status', 'admin_id', 'admin_note', 'resolved_at'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $values[] = $id;

        $stmt = $this->db->prepare(
            "UPDATE task_reports SET " . implode(', ', $fields) . " WHERE id = ?"
        );

        return $stmt->execute($values);
    }

    /**
     * لیست گزارش‌ها برای ادمین
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['reason'])) {
            $where[] = "r.reason = ?";
            $params[] = $filters['reason'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT r.*,
                   ct.title AS task_title,
                   u.full_name AS reporter_name
            FROM task_reports r
            LEFT JOIN custom_tasks ct ON ct.id = r.task_id
            LEFT JOIN users u ON u.id = r.reporter_id
            WHERE {$whereStr}
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تعداد گزارش‌ها
     */
    public function adminCount(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['reason'])) {
            $where[] = "r.reason = ?";
            $params[] = $filters['reason'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_reports r WHERE {$whereStr}
        ");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * دریافت تعداد گزارش‌های یک تسک
     */
    public function getTaskReportCount(int $taskId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_reports
            WHERE task_id = ?
        ");
        $stmt->execute([$taskId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * برچسب‌های دلیل گزارش
     */
    public function reasonLabels(): array
    {
        return [
            'spam' => 'اسپم',
            'fraud' => 'تقلب',
            'inappropriate' => 'نامناسب',
            'misleading' => 'گمراه‌کننده',
            'other' => 'سایر',
        ];
    }

    /**
     * برچسب‌های وضعیت
     */
    public function statusLabels(): array
    {
        return [
            'pending' => 'در انتظار',
            'reviewed' => 'بررسی شده',
            'resolved' => 'حل شده',
            'rejected' => 'رد شده',
        ];
    }
}
