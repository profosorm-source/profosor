<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * ActivityLog Model — Data Access Layer
 * 
 * فقط CRUD خام - بدون منطق بیزینسی
 * همه منطق در LogService است
 */
class ActivityLog extends Model
{
    protected static string $table = 'activity_logs';

    /**
     * ایجاد رکورد جدید
     */
    public function create(array $data): bool
    {
        try {
            $stmt = $this->db->query(
                "INSERT INTO activity_logs 
                (user_id, action, description, model, model_id, ip_address, user_agent, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $data['user_id'] ?? null,
                    $data['action'] ?? null,
                    $data['description'] ?? null,
                    $data['model'] ?? null,
                    $data['model_id'] ?? null,
                    $data['ip_address'] ?? null,
                    $data['user_agent'] ?? null,
                    $data['metadata'] ?? null,
                ]
            );

            return (bool) $stmt;
        } catch (\Throwable $e) {
            $this->logger->error('model.activity_log.create.failed', [
    'channel' => 'model',
    'error' => $e->getMessage(),
]);
            return false;
        }
    }

    /**
     * دریافت لاگ‌های اخیر
     */
    public function getRecent(int $limit = 50, ?int $userId = null, ?string $action = null): array
    {
        $limit = max(1, min(500, $limit));
        
        $where = ['al.deleted_at IS NULL'];
        $params = [];

        if ($userId !== null) {
            $where[] = 'al.user_id = ?';
            $params[] = $userId;
        }
        if ($action !== null) {
            $where[] = 'al.action = ?';
            $params[] = $action;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->query(
            "SELECT al.*, u.full_name, u.email
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE {$whereClause}
             ORDER BY al.created_at DESC
             LIMIT ?",
            [...$params, $limit]
        );
        
        return $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /**
     * دریافت با صفحه‌بندی
     */
    public function getPaginated(
        int $page = 1,
        int $perPage = 20,
        ?int $userId = null,
        ?string $action = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['al.deleted_at IS NULL'];
        $params = [];

        if ($userId !== null) {
            $where[] = 'al.user_id = ?';
            $params[] = $userId;
        }
        if ($action !== null) {
            $where[] = 'al.action = ?';
            $params[] = $action;
        }
        if ($search !== null) {
            $like = "%{$search}%";
            $where[] = '(al.description LIKE ? OR al.action LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($dateFrom !== null) {
            $where[] = 'al.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $where[] = 'al.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $where);

        // Count
        $countStmt = $this->db->query(
            "SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id WHERE {$whereClause}",
            $params
        );
        $total = $countStmt instanceof \PDOStatement ? (int) $countStmt->fetchColumn() : 0;

        // Data
        $dataStmt = $this->db->query(
            "SELECT al.*, u.full_name, u.email, u.avatar
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE {$whereClause}
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        $rows = $dataStmt instanceof \PDOStatement ? $dataStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * دریافت یک رکورد با ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->query(
            "SELECT al.*, u.full_name, u.email
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE al.id = ? AND al.deleted_at IS NULL",
            [$id]
        );

        return $stmt instanceof \PDOStatement ? ($stmt->fetch(\PDO::FETCH_ASSOC) ?: null) : null;
    }

    /**
     * Soft Delete (علامت‌گذاری برای حذف)
     */
    public function softDelete(int $id): bool
    {
        try {
            $stmt = $this->db->query(
                "UPDATE activity_logs SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );
            return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->logger->error('model.activity_log.soft_delete.failed', [
    'channel' => 'model',
    'error' => $e->getMessage(),
]);
            return false;
        }
    }

    /**
     * Soft Delete لاگ‌های قدیمی
     */
    public function softDeleteOlderThan(int $days = 90): int
    {
        try {
            $stmt = $this->db->query(
                "UPDATE activity_logs SET deleted_at = NOW()
                 WHERE deleted_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );
            return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
        } catch (\Throwable $e) {
            $this->logger->error('model.activity_log.soft_delete_older_than.failed', [
    'channel' => 'model',
    'error' => $e->getMessage(),
]);
            return 0;
        }
    }

   /**
 * حذف فیزیکی
 */
public function delete(int $id): bool
{
    try {
        $stmt = $this->db->query("DELETE FROM activity_logs WHERE id = ?", [$id]);
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
        try {
            $this->logger->error('activity_log.delete.failed', [
                'channel' => 'activity_log',
                'id' => $id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (\Throwable $ignore) {
            // fail-safe: خطای لاگ نباید جریان برنامه را بشکند
        }
        return false;
    }
}

/**
 * حذف فیزیکی لاگ‌های قدیمی
 */
public function deleteOlderThan(int $days = 90): int
{
    try {
        $stmt = $this->db->query(
            "DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    } catch (\Throwable $e) {
        try {
            $this->logger->error('activity_log.delete_older_than.failed', [
                'channel' => 'activity_log',
                'days' => $days,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (\Throwable $ignore) {
            // fail-safe
        }
        return 0;
    }
}

    /**
     * شمارش کل رکوردها
     */
    public function count(?int $userId = null, ?string $action = null): int
    {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if ($userId !== null) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
        }
        if ($action !== null) {
            $where[] = 'action = ?';
            $params[] = $action;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->query("SELECT COUNT(*) FROM activity_logs WHERE {$whereClause}", $params);
        return $stmt instanceof \PDOStatement ? (int) $stmt->fetchColumn() : 0;
    }

    /**
     * دریافت لیست action های یونیک
     */
    public function getUniqueActions(): array
    {
        $stmt = $this->db->query(
            "SELECT DISTINCT action FROM activity_logs 
             WHERE deleted_at IS NULL 
             ORDER BY action ASC"
        );

        return $stmt instanceof \PDOStatement 
            ? array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'action') 
            : [];
    }
}

