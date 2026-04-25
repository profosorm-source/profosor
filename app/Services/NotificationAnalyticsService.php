<?php

namespace App\Services;

use App\Models\Notification;
use Core\Database;
use Core\Cache;
use Core\Logger;

/**
 * NotificationAnalyticsService — آنالیتیکس سیستم نوتیفیکیشن
 *
 * ─── استراتژی ──────────────────────────────────────────────────────────────
 *  • داده خام در جدول notifications (hybrid)
 *  • آمار تجمیعی در جدول notification_analytics (batch cron)
 *  • کش Redis/File برای داشبورد ادمین
 *
 * ─── متدهای اصلی ───────────────────────────────────────────────────────────
 *  getOverview()         — KPI های کلی
 *  getByType()           — آمار per-type
 *  getDailyTrend()       — روند روزانه
 *  getSegmentStats()     — آمار per-segment
 *  getFunnelStats()      — قیف Sent→Read→Clicked
 *  getFatigueReport()    — notification fatigue
 *  getChannelStats()     — push/email/sms/in_app
 *  runBatchAggregation() — اجرا از cron
 */
class NotificationAnalyticsService
{
    private Database     $db;
    private Notification $notifModel;
    private Cache        $cache;
    private Logger       $logger;

    private const CACHE_TTL    = 15; // دقیقه
    private const CACHE_PREFIX = 'notif_analytics:';

    public function __construct(
        Database     $db,
        Notification $notifModel,
        Logger       $logger
    ) {
        $this->db         = $db;
        $this->notifModel = $notifModel;
        $this->logger     = $logger;
        $this->cache      = Cache::getInstance();
    }

    /**
     * Overview — KPI های اصلی داشبورد
     */
    public function getOverview(int $days = 30): array
    {
        return $this->cache->remember(
            self::CACHE_PREFIX . "overview:{$days}",
            self::CACHE_TTL,
            function () use ($days) {
                $row = $this->db->query(
                    "SELECT
                        COUNT(*)                                                             AS total_sent,
                        SUM(is_read = 1)                                                     AS total_read,
                        SUM(clicked_at IS NOT NULL)                                          AS total_clicked,
                        COUNT(DISTINCT user_id)                                              AS unique_users,
                        ROUND(AVG(is_read) * 100, 1)                                         AS read_rate,
                        ROUND(SUM(clicked_at IS NOT NULL) / NULLIF(COUNT(*), 0) * 100, 1)    AS ctr,
                        AVG(CASE WHEN read_at IS NOT NULL
                            THEN TIMESTAMPDIFF(SECOND, created_at, read_at) END)             AS avg_time_to_read_sec,
                        SUM(is_read = 0 AND is_archived = 0 AND is_deleted = 0)              AS unread_backlog
                     FROM notifications
                     WHERE is_deleted  = 0
                       AND channel     = 'in_app'
                       AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                )->fetch(\PDO::FETCH_ASSOC);

                return $row ?: [];
            }
        );
    }

    /**
     * آمار per-type با breakdown کامل
     */
    public function getByType(int $days = 30): array
    {
        return $this->cache->remember(
            self::CACHE_PREFIX . "by_type:{$days}",
            self::CACHE_TTL,
            fn() => $this->notifModel->getAdminStatsByType($days)
        );
    }

    /**
     * روند روزانه (sent / read / click)
     */
    public function getDailyTrend(int $days = 30): array
    {
        return $this->cache->remember(
            self::CACHE_PREFIX . "daily:{$days}",
            self::CACHE_TTL,
            fn() => $this->notifModel->getDailyStats($days)
        );
    }

    /**
     * آمار per-segment (KYC / level / status)
     */
    public function getSegmentStats(int $days = 30): array
    {
        return $this->cache->remember(
            self::CACHE_PREFIX . "segment:{$days}",
            self::CACHE_TTL,
            fn() => $this->notifModel->getStatsBySegment($days)
        );
    }

    /**
     * قیف کامل: Sent → Read → Clicked
     */
    public function getFunnelStats(int $days = 30): array
    {
        return $this->cache->remember(
            self::CACHE_PREFIX . "funnel:{$days}",
            self::CACHE_TTL,
            function () use ($days) {
                $row = $this->db->query(
                    "SELECT
                        COUNT(*)                                            AS sent,
                        SUM(is_read = 1)                                    AS opened,
                        SUM(clicked_at IS NOT NULL)                         AS clicked,
                        ROUND(AVG(is_read) * 100, 1)                        AS open_rate,
                        ROUND(SUM(clicked_at IS NOT NULL) / NULLIF(SUM(is_read), 0) * 100, 1) AS click_after_read_rate,
                        ROUND(SUM(clicked_at IS NOT NULL) / NULLIF(COUNT(*), 0) * 100, 1)     AS overall_ctr
                     FROM notifications
                     WHERE is_deleted  = 0
                       AND channel     = 'in_app'
                       AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                )->fetch(\PDO::FETCH_ASSOC);

                return $row ?: [];
            }
        );
    }

    /**
     * Notification Fatigue — کاربران با انباشت بالا
     */
    public function getFatigueReport(int $threshold = 20): array
    {
        return $this->cache->remember(
            self::CACHE_PREFIX . "fatigue:{$threshold}",
            self::CACHE_TTL,
            function () use ($threshold) {
                $users = $this->notifModel->getHighUnreadUsers($threshold, 50);

                // آمار کلی fatigue
                $summary = $this->db->query(
                    "SELECT
                        COUNT(DISTINCT user_id)                                     AS affected_users,
                        AVG(unread_cnt)                                             AS avg_unread_per_user,
                        MAX(unread_cnt)                                             AS max_unread
                     FROM (
                         SELECT user_id, COUNT(*) AS unread_cnt
                         FROM notifications
                         WHERE is_read    = 0
                           AND is_deleted = 0
                           AND is_archived = 0
                           AND channel    = 'in_app'
                         GROUP BY user_id
                         HAVING unread_cnt >= ?
                     ) t",
                    [$threshold]
                )->fetch(\PDO::FETCH_ASSOC);

                return [
                    'summary' => $summary ?: [],
                    'users'   => $users,
                ];
            }
        );
    }

    /**
     * آمار per-channel (push/email/sms/in_app)
     */
    public function getChannelStats(int $days = 30): array
    {
        return $this->cache->remember(
            self::CACHE_PREFIX . "channel:{$days}",
            self::CACHE_TTL,
            function () use ($days) {
                return $this->db->query(
                    "SELECT
                        channel,
                        COUNT(*)                                                            AS total_sent,
                        SUM(CASE WHEN channel = 'in_app' THEN is_read ELSE 0 END)          AS total_read,
                        ROUND(AVG(CASE WHEN channel = 'in_app' THEN is_read END) * 100, 1) AS read_rate
                     FROM notifications
                     WHERE is_deleted  = 0
                       AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                     GROUP BY channel
                     ORDER BY total_sent DESC",
                    [$days]
                )->fetchAll(\PDO::FETCH_OBJ);
            }
        );
    }

    /**
     * top کاربران درگیر (most engaged)
     */
    public function getTopEngagedUsers(int $days = 30, int $limit = 10): array
    {
        return $this->db->query(
            "SELECT
                n.user_id,
                u.email,
                u.full_name,
                COUNT(n.id)                                                     AS total_received,
                SUM(n.is_read = 1)                                              AS total_read,
                SUM(n.clicked_at IS NOT NULL)                                   AS total_clicked,
                ROUND(AVG(n.is_read) * 100, 1)                                  AS read_rate
             FROM notifications n
             JOIN users u ON u.id = n.user_id
             WHERE n.is_deleted  = 0
               AND n.channel     = 'in_app'
               AND n.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND u.deleted_at  IS NULL
             GROUP BY n.user_id
             HAVING total_received >= 5
             ORDER BY read_rate DESC, total_clicked DESC
             LIMIT {$limit}",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * کاربران با کمترین engagement (opt-out candidates)
     */
    public function getLeastEngagedUsers(int $days = 30, int $limit = 10): array
    {
        return $this->db->query(
            "SELECT
                n.user_id,
                u.email,
                COUNT(n.id)                             AS total_received,
                SUM(n.is_read = 1)                      AS total_read,
                ROUND(AVG(n.is_read) * 100, 1)          AS read_rate
             FROM notifications n
             JOIN users u ON u.id = n.user_id
             WHERE n.is_deleted  = 0
               AND n.channel     = 'in_app'
               AND n.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND u.deleted_at  IS NULL
             GROUP BY n.user_id
             HAVING total_received >= 5
             ORDER BY read_rate ASC, total_received DESC
             LIMIT {$limit}",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * داشبورد کامل برای ادمین (یک call، همه داده‌ها)
     */
    public function getDashboard(int $days = 30): array
    {
        return [
            'overview'      => $this->getOverview($days),
            'by_type'       => $this->getByType($days),
            'daily_trend'   => $this->getDailyTrend($days),
            'funnel'        => $this->getFunnelStats($days),
            'channels'      => $this->getChannelStats($days),
            'fatigue'       => $this->getFatigueReport(),
            'top_engaged'   => $this->getTopEngagedUsers($days),
            'least_engaged' => $this->getLeastEngagedUsers($days),
            'segment'       => $this->getSegmentStats($days),
            'period_days'   => $days,
            'generated_at'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * اجرای batch aggregation — از cron (هر ساعت)
     * آمار را در جدول notification_analytics ذخیره می‌کند
     */
    public function runBatchAggregation(): array
    {
        $date  = date('Y-m-d');
        $stats = ['processed' => 0, 'errors' => 0];

        try {
            // آمار روز جاری per-type
            $rows = $this->db->query(
                "SELECT
                    type,
                    channel,
                    COUNT(*)                                AS sent,
                    SUM(is_read = 1)                        AS read_count,
                    SUM(clicked_at IS NOT NULL)             AS click_count,
                    COUNT(DISTINCT user_id)                 AS unique_users
                 FROM notifications
                 WHERE DATE(created_at) = ?
                   AND is_deleted       = 0
                 GROUP BY type, channel",
                [$date]
            )->fetchAll(\PDO::FETCH_OBJ);

            foreach ($rows as $row) {
                $this->db->query(
                    "INSERT INTO notification_analytics
                        (date, type, channel, sent, read_count, click_count, unique_users, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        sent         = VALUES(sent),
                        read_count   = VALUES(read_count),
                        click_count  = VALUES(click_count),
                        unique_users = VALUES(unique_users),
                        updated_at   = NOW()",
                    [
                        $date,
                        $row->type,
                        $row->channel,
                        $row->sent,
                        $row->read_count,
                        $row->click_count,
                        $row->unique_users,
                    ]
                );
                $stats['processed']++;
            }

            // پاک‌کردن cache بعد از aggregation
            $this->invalidateCache();

            $this->logger->info('notif.analytics.batch_done', $stats);

        } catch (\Throwable $e) {
            $this->logger->error('notif.analytics.batch_failed', ['error' => $e->getMessage()]);
            $stats['errors']++;
        }

        return $stats;
    }

    /**
     * پاک‌کردن cache آنالیتیکس
     */
    public function invalidateCache(): void
    {
        foreach (['overview', 'by_type', 'daily', 'funnel', 'channel', 'segment', 'fatigue'] as $key) {
            foreach ([7, 14, 30, 90] as $days) {
                $this->cache->forget(self::CACHE_PREFIX . "{$key}:{$days}");
            }
        }
    }
}
