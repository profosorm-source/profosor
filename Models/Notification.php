<?php

namespace App\Models;

use Core\Model;

class Notification extends Model
{
    protected static string $table = 'notifications';

    // ─── انواع نوتیفیکیشن ────────────────────────────────────────────────────
    public const TYPE_SYSTEM     = 'system';
    public const TYPE_DEPOSIT    = 'deposit';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_TASK       = 'task';
    public const TYPE_KYC        = 'kyc';
    public const TYPE_LOTTERY    = 'lottery';
    public const TYPE_REFERRAL   = 'referral';
    public const TYPE_SECURITY   = 'security';
    public const TYPE_INVESTMENT = 'investment';
    public const TYPE_INFO       = 'info';
    public const TYPE_MARKETING  = 'marketing';

    // ─── کانال‌های ارسال ──────────────────────────────────────────────────────
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_PUSH   = 'push';
    public const CHANNEL_EMAIL  = 'email';
    public const CHANNEL_SMS    = 'sms';

    // ─── اولویت‌ها ────────────────────────────────────────────────────────────
    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    /**
     * ایجاد نوتیفیکیشن
     */
    public function create(array $data): int|false
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->query(
            "INSERT INTO notifications
                (user_id, type, title, message, data, action_url, action_text,
                 image_url, icon, priority, group_key, channel,
                 scheduled_at, expires_at, is_read, is_archived, is_deleted,
                 created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)",
            [
                $data['user_id']      ?? null,
                $data['type']         ?? self::TYPE_SYSTEM,
                $data['title']        ?? '',
                $data['message']      ?? '',
                isset($data['data'])
                    ? (is_array($data['data']) ? json_encode($data['data'], JSON_UNESCAPED_UNICODE) : $data['data'])
                    : null,
                $data['action_url']   ?? null,
                $data['action_text']  ?? null,
                $data['image_url']    ?? null,
                $data['icon']         ?? null,
                $data['priority']     ?? self::PRIORITY_NORMAL,
                $data['group_key']    ?? null,
                $data['channel']      ?? self::CHANNEL_IN_APP,
                $data['scheduled_at'] ?? null,
                $data['expires_at']   ?? null,
                $now,
                $now,
            ]
        );

        if (!$stmt) {
            return false;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : false;
    }

    /**
     * آخرین نوتیفیکیشن‌های کاربر
     */
    public function getLatestForUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->query(
            "SELECT *
             FROM notifications
             WHERE user_id     = ?
               AND is_archived = 0
               AND is_deleted  = 0
               AND channel     = 'in_app'
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
               AND (expires_at  IS NULL OR expires_at  >  NOW())
             ORDER BY id DESC
             LIMIT {$limit}",
            [$userId]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * نوتیفیکیشن‌های کاربر با فیلتر، مرتب‌سازی اولویت و pagination
     */
    public function getUserNotifications(
        int  $userId,
        bool $onlyUnread = false,
        int  $limit      = 20,
        int  $offset     = 0
    ): array {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $sql    = "SELECT *
                   FROM notifications
                   WHERE user_id     = ?
                     AND is_archived = 0
                     AND is_deleted  = 0
                     AND channel     = 'in_app'
                     AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                     AND (expires_at  IS NULL OR expires_at  >  NOW())";
        $params = [$userId];

        if ($onlyUnread) {
            $sql .= " AND is_read = 0";
        }

        $sql .= " ORDER BY
                    CASE priority
                      WHEN 'urgent' THEN 4
                      WHEN 'high'   THEN 3
                      WHEN 'normal' THEN 2
                      WHEN 'low'    THEN 1
                      ELSE 0
                    END DESC,
                    created_at DESC
                  LIMIT {$limit} OFFSET {$offset}";

        return $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تعداد کل (برای pagination)
     */
    public function countUserNotifications(int $userId, bool $onlyUnread = false): int
    {
        $sql    = "SELECT COUNT(*) AS total
                   FROM notifications
                   WHERE user_id     = ?
                     AND is_archived = 0
                     AND is_deleted  = 0
                     AND channel     = 'in_app'
                     AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                     AND (expires_at  IS NULL OR expires_at  >  NOW())";
        $params = [$userId];

        if ($onlyUnread) {
            $sql .= " AND is_read = 0";
        }

        $row = $this->db->query($sql, $params)->fetch(\PDO::FETCH_OBJ);
        return (int)($row->total ?? 0);
    }

    /**
     * تعداد خوانده‌نشده
     */
    public function countUnread(int $userId): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total
             FROM notifications
             WHERE user_id     = ?
               AND is_read     = 0
               AND is_archived = 0
               AND is_deleted  = 0
               AND channel     = 'in_app'
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
               AND (expires_at  IS NULL OR expires_at  >  NOW())",
            [$userId]
        )->fetch(\PDO::FETCH_OBJ);

        return (int)($row->total ?? 0);
    }

    /** alias */
    public function getUnreadCount(int $userId): int
    {
        return $this->countUnread($userId);
    }

    /**
     * علامت خواندن یک نوتیفیکیشن
     */
    public function markAsRead(int $id, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?, updated_at = ?
             WHERE id = ? AND user_id = ? AND is_deleted = 0",
            [$now, $now, $id, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * علامت خواندن همه → bool
     */
    public function markAllAsRead(int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?, updated_at = ?
             WHERE user_id = ? AND is_read = 0 AND is_deleted = 0",
            [$now, $now, $userId]
        );
        return $stmt instanceof \PDOStatement;
    }

    /**
     * تعداد رکوردهای markAllAsRead
     */
    public function markAllAsReadCount(int $userId): int
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?, updated_at = ?
             WHERE user_id = ? AND is_read = 0 AND is_deleted = 0",
            [$now, $now, $userId]
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /**
     * ثبت کلیک (analytics)
     */
    public function recordClick(int $id, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET clicked_at = COALESCE(clicked_at, ?), updated_at = ?
             WHERE id = ? AND user_id = ? AND is_deleted = 0",
            [$now, $now, $id, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * آرشیو کردن
     */
    public function archive(int $notificationId, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_archived = 1, archived_at = ?, updated_at = ?
             WHERE id = ? AND user_id = ? AND is_deleted = 0",
            [$now, $now, $notificationId, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * حذف منطقی (soft delete)
     */
    public function softDelete(int $notificationId, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_deleted = 1, deleted_at = ?, updated_at = ?
             WHERE id = ? AND user_id = ?",
            [$now, $now, $notificationId, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * بازیابی نوتیفیکیشن حذف‌شده
     */
    public function restore(int $notificationId, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_deleted = 0, deleted_at = NULL, updated_at = ?
             WHERE id = ? AND user_id = ?",
            [$now, $notificationId, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * آرشیو کردن منقضی‌شده‌ها (cron)
     */
    public function archiveExpired(): int
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_archived = 1, archived_at = ?, updated_at = ?
             WHERE is_archived = 0
               AND is_deleted  = 0
               AND expires_at  IS NOT NULL
               AND expires_at  < ?",
            [$now, $now, $now]
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /** alias backward-compat */
    public function deleteExpired(): int
    {
        return $this->archiveExpired();
    }

    /**
     * نوتیفیکیشن‌های زمان‌بندی‌شده آماده ارسال (cron)
     */
    public function getPendingScheduled(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query(
            "SELECT *
             FROM notifications
             WHERE scheduled_at IS NOT NULL
               AND scheduled_at <= NOW()
               AND sent_at      IS NULL
               AND is_deleted   = 0
             ORDER BY
               CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END DESC,
               scheduled_at ASC
             LIMIT {$limit}"
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * علامت‌گذاری به‌عنوان ارسال‌شده
     */
    public function markAsSent(int $id): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications SET sent_at = ?, updated_at = ? WHERE id = ?",
            [$now, $now, $id]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * دریافت بر اساس نوع
     */
    public function getByType(int $userId, string $type, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            "SELECT *
             FROM notifications
             WHERE user_id     = ?
               AND type        = ?
               AND is_archived = 0
               AND is_deleted  = 0
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC
             LIMIT {$limit}",
            [$userId, $type]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * آمار تجمیعی per-type برای analytics ادمین
     */
    public function getAdminStatsByType(int $days = 30): array
    {
        return $this->db->query(
            "SELECT
                type,
                COUNT(*)                                                            AS total_sent,
                SUM(is_read = 1)                                                    AS total_read,
                SUM(clicked_at IS NOT NULL)                                         AS total_clicked,
                ROUND(AVG(is_read) * 100, 1)                                        AS read_rate,
                ROUND(SUM(clicked_at IS NOT NULL) / NULLIF(COUNT(*), 0) * 100, 1)   AS ctr,
                AVG(CASE WHEN read_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, created_at, read_at) END)            AS avg_time_to_read_sec
             FROM notifications
             WHERE is_deleted  = 0
               AND channel     = 'in_app'
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY type
             ORDER BY total_sent DESC",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * آمار روزانه (sent/read/click per day)
     */
    public function getDailyStats(int $days = 30): array
    {
        return $this->db->query(
            "SELECT
                DATE(created_at)                                                    AS date,
                COUNT(*)                                                            AS sent,
                SUM(is_read = 1)                                                    AS read_count,
                SUM(clicked_at IS NOT NULL)                                         AS click_count,
                ROUND(AVG(is_read) * 100, 1)                                        AS read_rate
             FROM notifications
             WHERE is_deleted  = 0
               AND channel     = 'in_app'
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * آمار per-segment (KYC / level / status)
     */
    public function getStatsBySegment(int $days = 30): array
    {
        return $this->db->query(
            "SELECT
                u.kyc_status,
                u.level,
                u.status                                                            AS user_status,
                COUNT(n.id)                                                         AS total_sent,
                SUM(n.is_read = 1)                                                  AS total_read,
                ROUND(AVG(n.is_read) * 100, 1)                                      AS read_rate,
                ROUND(SUM(n.clicked_at IS NOT NULL) / NULLIF(COUNT(n.id), 0) * 100, 1) AS ctr
             FROM notifications n
             JOIN users u ON u.id = n.user_id
             WHERE n.is_deleted  = 0
               AND n.channel     = 'in_app'
               AND n.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND u.deleted_at  IS NULL
             GROUP BY u.kyc_status, u.level, u.status
             ORDER BY total_sent DESC",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * notification fatigue — کاربران با انباشت بالای نوتیف نخوانده
     */
    public function getHighUnreadUsers(int $threshold = 20, int $limit = 50): array
    {
        return $this->db->query(
            "SELECT user_id, COUNT(*) AS unread_count
             FROM notifications
             WHERE is_read    = 0
               AND is_deleted = 0
               AND is_archived = 0
               AND channel    = 'in_app'
             GROUP BY user_id
             HAVING unread_count >= ?
             ORDER BY unread_count DESC
             LIMIT {$limit}",
            [$threshold]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * گروه‌بندی نوتیفیکیشن‌ها — فقط در لایه نمایش
     * هر رکورد مستقل باقی می‌ماند
     */
    public function getGroupedForUser(int $userId, int $limit = 20): array
    {
        $notifications = $this->getUserNotifications($userId, false, $limit);
        $groups        = [];

        foreach ($notifications as $notif) {
            $key = $notif->group_key ?? ('single_' . $notif->id);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'latest' => $notif,
                    'count'  => 1,
                    'unread' => (int)!$notif->is_read,
                    'ids'    => [$notif->id],
                ];
            } else {
                $groups[$key]['count']++;
                if (!$notif->is_read) {
                    $groups[$key]['unread']++;
                }
                $groups[$key]['ids'][] = $notif->id;
            }
        }

        return array_values($groups);
    }
}
