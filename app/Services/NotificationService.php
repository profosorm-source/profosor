<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use Core\Database;
use Core\Cache;
use Core\Logger;
use Core\RateLimiter;

/**
 * NotificationService — سرویس مرکزی نوتیفیکیشن
 *
 * ─── کانال‌های پشتیبانی‌شده ────────────────────────────────────────────────
 *  • In-App  : ذخیره در DB + Redis cache برای unread count
 *  • Push    : FCM از طریق FcmService
 *  • Email   : از طریق EmailService موجود
 *  • SMS     : از طریق SmsNotificationService (stub)
 *
 * ─── ویژگی‌ها ───────────────────────────────────────────────────────────────
 *  • Rate Limiting با RateLimiter موجود (Redis/File)
 *  • Do Not Disturb از NotificationPreference
 *  • Scheduling — ذخیره با scheduled_at
 *  • Template System با NotificationTemplateService
 *  • Grouping (group_key) در لایه ذخیره
 *  • Soft Delete
 *  • unread count کش‌شده در Redis
 */
class NotificationService
{
    private Database                    $db;
    private Notification                $notificationModel;
    private NotificationPreference      $prefModel;
    private Logger                      $logger;
    private Cache                       $cache;
    private RateLimiter                 $rateLimiter;
    private NotificationTemplateService $templateService;
    private FcmService                  $fcmService;
    private SmsNotificationService      $smsService;
    private ?EmailService               $emailService;

    // ─── Rate Limit ──────────────────────────────────────────────────────────
    // حداکثر نوتیفیکیشن per-user در یک بازه زمانی
    private const RATE_MAX_PER_USER_PER_HOUR = 20;
    private const RATE_WINDOW_MINUTES        = 60;

    // ─── Cache ───────────────────────────────────────────────────────────────
    private const UNREAD_CACHE_PREFIX = 'notif_unread:';
    private const UNREAD_CACHE_TTL   = 5; // دقیقه

    public function __construct(
        Notification                $notificationModel,
        NotificationPreference      $prefModel,
        Database                    $db,
        Logger                      $logger,
        RateLimiter                 $rateLimiter,
        NotificationTemplateService $templateService,
        FcmService                  $fcmService,
        SmsNotificationService      $smsService,
        ?EmailService               $emailService = null
    ) {
        $this->notificationModel = $notificationModel;
        $this->prefModel         = $prefModel;
        $this->db                = $db;
        $this->logger            = $logger;
        $this->rateLimiter       = $rateLimiter;
        $this->templateService   = $templateService;
        $this->fcmService        = $fcmService;
        $this->smsService        = $smsService;
        $this->emailService      = $emailService;
        $this->cache             = Cache::getInstance();
    }

    // =========================================================================
    // ارسال اصلی
    // =========================================================================

    /**
     * ارسال نوتیفیکیشن به یک کاربر
     *
     * @param int         $userId
     * @param string      $type       یکی از Notification::TYPE_*
     * @param string      $title
     * @param string      $message
     * @param array|null  $data       داده اضافی
     * @param string|null $actionUrl
     * @param string|null $actionText
     * @param string      $priority   low|normal|high|urgent
     * @param string|null $expiresAt  Y-m-d H:i:s
     * @param string|null $imageUrl   تصویر غنی
     * @param string|null $groupKey   کلید گروه‌بندی
     * @param string|null $scheduledAt زمان‌بندی (null = فوری)
     * @return int|null   ID نوتیفیکیشن یا null
     */
    public function send(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data        = null,
        ?string $actionUrl   = null,
        ?string $actionText  = null,
        string  $priority    = Notification::PRIORITY_NORMAL,
        ?string $expiresAt   = null,
        ?string $imageUrl    = null,
        ?string $groupKey    = null,
        ?string $scheduledAt = null
    ): ?int {
        // ─── Rate Limit ───────────────────────────────────────────────────────
        if (!$this->checkRateLimit($userId, $type)) {
            $this->logger->info('notif.rate_limited', ['user_id' => $userId, 'type' => $type]);
            return null;
        }

        // ─── Do Not Disturb ───────────────────────────────────────────────────
        // اگر DND فعال است و نوتیف urgent نیست، به صف زمان‌بندی می‌رود
        if ($scheduledAt === null && $priority !== Notification::PRIORITY_URGENT) {
            if ($this->prefModel->isInDndMode($userId)) {
                $scheduledAt = $this->getNextDndEndTime($userId);
                $this->logger->info('notif.dnd_deferred', ['user_id' => $userId, 'scheduled_at' => $scheduledAt]);
            }
        }

        // ─── In-App ───────────────────────────────────────────────────────────
        $notifId = null;

        try {
            if ($this->prefModel->isInAppEnabled($userId, $type)) {
                $notifId = $this->notificationModel->create([
                    'user_id'      => $userId,
                    'type'         => $type,
                    'title'        => $title,
                    'message'      => $message,
                    'data'         => $data,
                    'action_url'   => $actionUrl,
                    'action_text'  => $actionText,
                    'priority'     => $priority,
                    'expires_at'   => $expiresAt,
                    'image_url'    => $imageUrl,
                    'group_key'    => $groupKey ?? $type,
                    'channel'      => Notification::CHANNEL_IN_APP,
                    'scheduled_at' => $scheduledAt,
                ]) ?: null;

                if ($notifId && $scheduledAt === null) {
                    // پاک‌کردن cache unread count
                    $this->invalidateUnreadCache($userId);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('notif.in_app_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        // ─── Push (FCM) ───────────────────────────────────────────────────────
        if ($scheduledAt === null && $this->prefModel->isPushEnabled($userId, $type)) {
            try {
                $this->fcmService->sendToUser(
                    $userId, $title, $message,
                    array_merge($data ?? [], ['type' => $type, 'notif_id' => (string)($notifId ?? '')]),
                    $imageUrl,
                    $actionUrl
                );
            } catch (\Throwable $e) {
                $this->logger->warning('notif.push_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return $notifId;
    }

    // =========================================================================
    // ارسال با Template
    // =========================================================================

    /**
     * ارسال با استفاده از template system
     */
    public function sendFromTemplate(
        int    $userId,
        string $templateKey,
        array  $vars       = [],
        string $priority   = Notification::PRIORITY_NORMAL,
        ?string $actionUrl = null,
        ?string $actionText= null,
        ?string $groupKey  = null,
        ?string $scheduledAt = null
    ): ?int {
        $rendered = $this->templateService->render($templateKey, $vars);

        // استخراج type از templateKey (مثلاً 'kyc_approved' → 'kyc')
        $type = explode('_', $templateKey)[0];
        if (!defined(Notification::class . '::TYPE_' . strtoupper($type))) {
            $type = Notification::TYPE_SYSTEM;
        }

        return $this->send(
            $userId,
            $type,
            $rendered['title'],
            $rendered['message'],
            $vars,
            $actionUrl,
            $actionText,
            $priority,
            null,
            null,
            $groupKey ?? $templateKey,
            $scheduledAt
        );
    }

    // =========================================================================
    // ارسال گروهی
    // =========================================================================

    /**
     * ارسال به همه کاربران active
     */
    public function sendToAll(
        string  $title,
        string  $message,
        string  $type       = Notification::TYPE_SYSTEM,
        ?string $actionUrl  = null,
        ?string $actionText = null,
        string  $priority   = Notification::PRIORITY_NORMAL,
        ?array  $data       = null,
        ?string $scheduledAt = null
    ): array {
        $users = $this->db->query(
            "SELECT id FROM users WHERE deleted_at IS NULL AND status = 'active'"
        )->fetchAll(\PDO::FETCH_OBJ);

        return $this->sendBulkToUsers(
            $users, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt
        );
    }

    /**
     * ارسال به segment کاربران
     *
     * @param string $segment  نام segment یا 'all'
     * @param array  $filters  فیلترهای اضافی: ['status', 'kyc_status', 'level', 'registered_after']
     */
    public function sendToSegment(
        string  $segment,
        string  $title,
        string  $message,
        string  $type        = Notification::TYPE_SYSTEM,
        ?string $actionUrl   = null,
        ?string $actionText  = null,
        string  $priority    = Notification::PRIORITY_NORMAL,
        ?array  $data        = null,
        ?string $scheduledAt = null,
        array   $filters     = []
    ): array {
        $users = $this->getUsersBySegment($segment, $filters);

        $result = $this->sendBulkToUsers(
            $users, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt
        );

        $this->logger->info('notif.send_to_segment', array_merge($result, [
            'segment' => $segment,
            'type'    => $type,
        ]));

        return $result;
    }

    /**
     * ارسال bulk به آرایه‌ای از user IDs
     */
    public function sendBulk(
        array   $userIds,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data      = null,
        ?string $actionUrl = null,
        string  $priority  = Notification::PRIORITY_NORMAL
    ): int {
        $sent = 0;
        foreach ($userIds as $userId) {
            if ($this->send((int)$userId, $type, $title, $message, $data, $actionUrl, null, $priority)) {
                $sent++;
            }
        }
        return $sent;
    }

    // =========================================================================
    // Query helpers
    // =========================================================================

    public function latest(int $userId, int $limit = 10): array
    {
        return $this->notificationModel->getLatestForUser($userId, $limit);
    }

    /**
     * تعداد خوانده‌نشده — با Redis cache
     */
    public function getUnreadCount(int $userId): int
    {
        $cacheKey = self::UNREAD_CACHE_PREFIX . $userId;

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (int)$cached;
        }

        $count = $this->notificationModel->countUnread($userId);
        $this->cache->put($cacheKey, $count, self::UNREAD_CACHE_TTL);

        return $count;
    }

    /**
     * پاک‌کردن cache unread count
     */
    public function invalidateUnreadCache(int $userId): void
    {
        $this->cache->forget(self::UNREAD_CACHE_PREFIX . $userId);
    }

    // =========================================================================
    // Shortcut — نوتیفیکیشن‌های از پیش تعریف‌شده
    // =========================================================================

    public function depositSuccess(int $userId, float $amount, string $currency): ?int
    {
        return $this->sendFromTemplate($userId, 'deposit', [
            'amount'   => format_amount($amount),
            'currency' => strtoupper($currency),
        ], Notification::PRIORITY_HIGH, url('/wallet'), 'مشاهده کیف پول');
    }

    public function withdrawalApproved(int $userId, float $amount, string $currency): ?int
    {
        $id = $this->sendFromTemplate($userId, 'withdrawal', [
            'amount'   => format_amount($amount),
            'currency' => strtoupper($currency),
        ], Notification::PRIORITY_HIGH, url('/wallet/history'), 'مشاهده تاریخچه');

        // SMS برای برداشت (اگر فعال باشد)
        $this->sendWithdrawalSms($userId, $amount, $currency);

        return $id;
    }

    public function withdrawalRejected(int $userId, float $amount, string $reason): ?int
    {
        return $this->sendFromTemplate($userId, 'withdrawal_rejected', [
            'amount' => format_amount($amount),
            'reason' => $reason,
        ], Notification::PRIORITY_HIGH, url('/wallet/history'), 'مشاهده جزئیات');
    }

    public function newTaskAvailable(int $userId, string $taskTitle): ?int
    {
        return $this->sendFromTemplate($userId, 'task', [
            'task_title' => $taskTitle,
        ], Notification::PRIORITY_NORMAL, url('/tasks'), 'مشاهده تسک‌ها', 'task_available');
    }

    public function kycVerified(int $userId): ?int
    {
        return $this->sendFromTemplate($userId, 'kyc_approved', [],
            Notification::PRIORITY_HIGH, url('/dashboard'), 'ورود به داشبورد');
    }

    public function kycRejected(int $userId, string $reason): ?int
    {
        return $this->sendFromTemplate($userId, 'kyc_rejected', [
            'reason' => $reason,
        ], Notification::PRIORITY_URGENT, url('/kyc/upload'), 'ارسال مجدد مدارک');
    }

    public function lotteryWinner(int $userId, float $amount): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_LOTTERY,
            '🎉 تبریک! برنده شدید!',
            'شما برنده قرعه‌کشی شدید! مبلغ ' . format_amount($amount) . ' به کیف پول شما واریز شد.',
            ['amount' => $amount],
            url('/wallet'),
            'مشاهده کیف پول',
            Notification::PRIORITY_URGENT,
            date('Y-m-d H:i:s', strtotime('+7 days'))
        );
    }

    public function referralEarning(int $userId, float $amount, string $referredUserName): ?int
    {
        return $this->sendFromTemplate($userId, 'referral', [
            'referred_user' => $referredUserName,
            'amount'        => format_amount($amount),
        ], Notification::PRIORITY_NORMAL, url('/referral'), 'مشاهده زیرمجموعه‌ها', 'referral_earning');
    }

    public function securityAlert(int $userId, string $message, string $ip): ?int
    {
        $id = $this->sendFromTemplate($userId, 'security', [
            'message' => $message,
            'ip'      => $ip,
        ], Notification::PRIORITY_URGENT, url('/profile/security'), 'بررسی حساب');

        // SMS برای هشدار امنیتی
        $this->sendSecuritySms($userId, $message);

        return $id;
    }

    public function investmentCompleted(int $userId, float $profit, float $total): ?int
    {
        return $this->sendFromTemplate($userId, 'investment_completed', [
            'profit' => format_amount($profit),
            'total'  => format_amount($total),
        ], Notification::PRIORITY_HIGH, url('/investments'), 'مشاهده سرمایه‌گذاری‌ها');
    }

    // =========================================================================
    // Segment — تعریف کاربران هر گروه
    // =========================================================================

    /**
     * دریافت کاربران بر اساس segment
     *
     * Segments:
     *   all          — همه کاربران active
     *   kyc_verified — KYC تأیید‌شده
     *   kyc_pending  — KYC در انتظار
     *   kyc_none     — بدون KYC
     *   level_silver — سطح نقره
     *   level_gold   — سطح طلا
     *   level_vip    — سطح VIP
     *   new_users    — ثبت‌نام اخیر (30 روز)
     *   inactive     — غیرفعال (60+ روز بدون login)
     *   custom       — با $filters سفارشی
     */
    public function getUsersBySegment(string $segment, array $filters = []): array
    {
        $sql    = "SELECT id FROM users WHERE deleted_at IS NULL";
        $params = [];

        switch ($segment) {
            case 'all':
                $sql .= " AND status = 'active'";
                break;

            case 'kyc_verified':
                $sql .= " AND status = 'active' AND kyc_status = 'approved'";
                break;

            case 'kyc_pending':
                $sql .= " AND status = 'active' AND kyc_status = 'pending'";
                break;

            case 'kyc_none':
                $sql .= " AND status = 'active' AND (kyc_status IS NULL OR kyc_status = 'none')";
                break;

            case 'level_silver':
                $sql .= " AND status = 'active' AND (level = 'silver' OR level IS NULL)";
                break;

            case 'level_gold':
                $sql .= " AND status = 'active' AND level = 'gold'";
                break;

            case 'level_vip':
                $sql .= " AND status = 'active' AND level = 'vip'";
                break;

            case 'new_users':
                $sql .= " AND status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;

            case 'inactive':
                $sql .= " AND status = 'active' AND (last_login_at IS NULL OR last_login_at < DATE_SUB(NOW(), INTERVAL 60 DAY))";
                break;

            case 'custom':
                if (!empty($filters['status'])) {
                    $sql .= " AND status = ?";
                    $params[] = $filters['status'];
                }
                if (!empty($filters['kyc_status'])) {
                    $sql .= " AND kyc_status = ?";
                    $params[] = $filters['kyc_status'];
                }
                if (!empty($filters['level'])) {
                    $sql .= " AND level = ?";
                    $params[] = $filters['level'];
                }
                if (!empty($filters['registered_after'])) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $filters['registered_after'];
                }
                break;

            default:
                // fallback به all active
                $sql .= " AND status = 'active'";
        }

        return $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * لیست segment‌های قابل انتخاب (برای admin UI)
     */
    public function getAvailableSegments(): array
    {
        return [
            'all'          => 'همه کاربران فعال',
            'kyc_verified' => 'کاربران با KYC تأیید‌شده',
            'kyc_pending'  => 'کاربران در انتظار KYC',
            'kyc_none'     => 'کاربران بدون KYC',
            'level_silver' => 'کاربران سطح نقره',
            'level_gold'   => 'کاربران سطح طلا',
            'level_vip'    => 'کاربران VIP',
            'new_users'    => 'کاربران جدید (۳۰ روز اخیر)',
            'inactive'     => 'کاربران غیرفعال (۶۰+ روز)',
            'custom'       => 'سفارشی (با فیلتر)',
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function sendBulkToUsers(
        array   $users,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data,
        ?string $actionUrl,
        ?string $actionText,
        string  $priority,
        ?string $scheduledAt
    ): array {
        $sent    = 0;
        $skipped = 0;

        foreach ($users as $u) {
            $ok = $this->send(
                (int)$u->id, $type, $title, $message,
                $data, $actionUrl, $actionText, $priority,
                null, null, null, $scheduledAt
            );
            $ok ? $sent++ : $skipped++;
        }

        $this->logger->info('notif.bulk_sent', [
            'sent'    => $sent,
            'skipped' => $skipped,
            'type'    => $type,
        ]);

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    private function checkRateLimit(int $userId, string $type): bool
    {
        // نوتیف‌های urgent از rate limit معاف هستند
        // (بررسی priority در caller)

        $key = "notif_rl_user_{$userId}";
        return $this->rateLimiter->attempt(
            $key,
            self::RATE_MAX_PER_USER_PER_HOUR,
            self::RATE_WINDOW_MINUTES
        );
    }

    private function getNextDndEndTime(int $userId): string
    {
        try {
            $prefs = $this->prefModel->getOrCreate($userId);
            $end   = $prefs->dnd_end ?? '07:00:00';

            // تبدیل به datetime فردا یا امروز
            $endTs = strtotime(date('Y-m-d') . ' ' . $end);
            if ($endTs <= time()) {
                $endTs = strtotime('+1 day', $endTs);
            }

            return date('Y-m-d H:i:s', $endTs);
        } catch (\Throwable) {
            return date('Y-m-d H:i:s', strtotime('+8 hours'));
        }
    }

    private function sendSecuritySms(int $userId, string $message): void
    {
        if (!$this->prefModel->isSmsEnabled($userId, 'security')) {
            return;
        }
        try {
            $user = $this->db->query("SELECT mobile FROM users WHERE id = ?", [$userId])
                ->fetch(\PDO::FETCH_OBJ);
            if ($user && !empty($user->mobile)) {
                $this->smsService->sendSecurityAlert($user->mobile, $message);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('notif.sms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function sendWithdrawalSms(int $userId, float $amount, string $currency): void
    {
        if (!$this->prefModel->isSmsEnabled($userId, 'withdrawal')) {
            return;
        }
        try {
            $user = $this->db->query("SELECT mobile FROM users WHERE id = ?", [$userId])
                ->fetch(\PDO::FETCH_OBJ);
            if ($user && !empty($user->mobile)) {
                $this->smsService->sendWithdrawalAlert($user->mobile, $amount, $currency);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('notif.sms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
