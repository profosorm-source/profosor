<?php

namespace App\Controllers\User;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Services\NotificationService;
use App\Controllers\User\BaseUserController;

class NotificationController extends BaseUserController
{
    private Notification           $notificationModel;
    private NotificationPreference $notificationPreferenceModel;
    private NotificationService    $notificationService;

    public function __construct(
        Notification           $notificationModel,
        NotificationPreference $notificationPreferenceModel,
        NotificationService    $notificationService
    ) {
        parent::__construct();
        $this->notificationModel           = $notificationModel;
        $this->notificationPreferenceModel = $notificationPreferenceModel;
        $this->notificationService         = $notificationService;
    }

    // =========================================================================
    // صفحات
    // =========================================================================

    /**
     * لیست نوتیفیکیشن‌ها
     */
    public function index()
    {
        $userId = user_id();
        $page   = max(1, (int)($this->request->input('page') ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $notifications = $this->notificationModel->getUserNotifications($userId, false, $limit, $offset);
        $totalCount    = $this->notificationModel->countUserNotifications($userId);
        $unreadCount   = $this->notificationService->getUnreadCount($userId);

        return view('user/notifications/index', [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
            'total_count'   => $totalCount,
            'current_page'  => $page,
            'per_page'      => $limit,
            'total_pages'   => (int)ceil($totalCount / $limit),
        ]);
    }

    /**
     * صفحه تنظیمات نوتیفیکیشن
     */
    public function preferences()
    {
        $userId = user_id();
        $prefs  = $this->notificationPreferenceModel->getOrCreate($userId);

        return view('user/notifications/preferences', [
            'preferences' => $prefs,
        ]);
    }

    // =========================================================================
    // Ajax — Long Polling
    // =========================================================================

    /**
     * Long Polling — request باز می‌ماند تا نوتیف جدید بیاید یا timeout
     *
     * Client باید:
     *  1. GET /notifications/poll?last_id=<آخرین ID دیده‌شده>
     *  2. بعد از response → ۱–۲ ثانیه صبر → دوباره connect
     */
    public function poll()
    {
        $userId    = user_id();
        $lastId    = (int)($this->request->input('last_id') ?? 0);
        $timeout   = 30;   // ثانیه
        $interval  = 2;    // ثانیه بین بررسی‌ها
        $waited    = 0;

        // تنظیمات PHP برای long polling
        set_time_limit($timeout + 10);
        ignore_user_abort(false);

        // بررسی اولیه — قبل از شروع waiting
        $new = $this->getNewNotifications($userId, $lastId);
        if (!empty($new['notifications'])) {
            return $this->response->json($new);
        }

        // Long poll loop
        while ($waited < $timeout) {
            sleep($interval);
            $waited += $interval;

            // اگر connection قطع شده، خروج
            if (connection_aborted()) {
                exit;
            }

            $new = $this->getNewNotifications($userId, $lastId);
            if (!empty($new['notifications'])) {
                return $this->response->json($new);
            }
        }

        // timeout — بدون نوتیف جدید
        return $this->response->json([
            'success'       => true,
            'notifications' => [],
            'unread_count'  => $this->notificationService->getUnreadCount($userId),
            'timeout'       => true,
        ]);
    }

    /**
     * دریافت نوتیفیکیشن‌ها (Ajax — بدون long poll)
     */
    public function get()
    {
        $userId     = user_id();
        $onlyUnread = $this->request->input('unread') === 'true';
        $limit      = max(1, min(50, (int)($this->request->input('limit') ?? 20)));

        $notifications = $this->notificationModel->getUserNotifications($userId, $onlyUnread, $limit);
        $unreadCount   = $this->notificationService->getUnreadCount($userId);

        return $this->response->json([
            'success'       => true,
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * تعداد خوانده‌نشده (برای badge)
     */
    public function unreadCount()
    {
        $userId = user_id();
        $count  = $this->notificationService->getUnreadCount($userId);

        return $this->response->json([
            'success' => true,
            'count'   => $count,
        ]);
    }

    // =========================================================================
    // Ajax — Actions
    // =========================================================================

    /**
     * علامت‌گذاری به عنوان خوانده‌شده + پاک‌کردن cache
     */
    public function markAsRead()
    {
        $notificationId = (int)$this->request->input('notification_id');
        $userId         = user_id();

        $result = $this->notificationModel->markAsRead($notificationId, $userId);

        if ($result) {
            $this->notificationService->invalidateUnreadCache($userId);
        }

        return $this->response->json([
            'success'     => $result,
            'unread_count'=> $this->notificationService->getUnreadCount($userId),
            'message'     => $result ? 'علامت‌گذاری شد' : 'خطا در علامت‌گذاری',
        ]);
    }

    /**
     * علامت خواندن همه
     */
    public function markAllAsRead()
    {
        $userId = user_id();

        $ok    = $this->notificationModel->markAllAsRead($userId);
        $count = $this->notificationModel->markAllAsReadCount($userId);

        if ($ok) {
            $this->notificationService->invalidateUnreadCache($userId);
        }

        return $this->response->json([
            'success'     => $ok,
            'count'       => $count,
            'unread_count'=> 0,
            'message'     => $ok ? "{$count} نوتیفیکیشن خوانده شد" : 'خطا در علامت‌گذاری',
        ]);
    }

    /**
     * ثبت کلیک (analytics) + redirect
     */
    public function click()
    {
        $notifId = (int)$this->request->input('notification_id');
        $userId  = user_id();

        $notif = $this->notificationModel->find($notifId);

        if ($notif && (int)$notif->user_id === $userId) {
            // ثبت کلیک
            $this->notificationModel->recordClick($notifId, $userId);
            // auto read
            if (!$notif->is_read) {
                $this->notificationModel->markAsRead($notifId, $userId);
                $this->notificationService->invalidateUnreadCache($userId);
            }

            if (!empty($notif->action_url)) {
                return redirect($notif->action_url);
            }
        }

        return redirect(url('/notifications'));
    }

    /**
     * آرشیو کردن
     */
    public function archive()
    {
        $notificationId = (int)$this->request->input('notification_id');
        $userId         = user_id();

        $result = $this->notificationModel->archive($notificationId, $userId);

        if ($result) {
            $this->notificationService->invalidateUnreadCache($userId);
        }

        return $this->response->json([
            'success' => $result,
            'message' => $result ? 'آرشیو شد' : 'خطا در آرشیو',
        ]);
    }

    /**
     * حذف منطقی (soft delete)
     */
    public function delete()
    {
        $notificationId = (int)$this->request->input('notification_id');
        $userId         = user_id();

        $result = $this->notificationModel->softDelete($notificationId, $userId);

        if ($result) {
            $this->notificationService->invalidateUnreadCache($userId);
        }

        return $this->response->json([
            'success' => $result,
            'message' => $result ? 'حذف شد' : 'خطا در حذف',
        ]);
    }

    /**
     * ذخیره FCM token (از service worker مرورگر)
     */
    public function saveFcmToken()
    {
        $token    = $this->request->input('token');
        $platform = $this->request->input('platform') ?? 'web';
        $userId   = user_id();

        if (empty($token)) {
            return $this->response->json(['success' => false, 'message' => 'token الزامی است'], 400);
        }

        // ذخیره از طریق FcmService
        $container  = app();
        $fcmService = $container->make(\App\Services\FcmService::class);
        $fcmService->saveUserToken($userId, $token, $platform);

        return $this->response->json(['success' => true]);
    }

    /**
     * ذخیره تنظیمات
     */
    public function updatePreferences()
    {
        $userId = user_id();
        $data   = $this->request->all();

        $prefModel = $this->notificationPreferenceModel;
        $prefModel->getOrCreate($userId);

        $allowedFields = $prefModel->getAllowedFields();
        $updateData    = [];

        foreach ($allowedFields as $field) {
            // فیلدهای TIME (dnd_start / dnd_end) باید string بمانند
            if (in_array($field, ['dnd_start', 'dnd_end'], true)) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            } else {
                $updateData[$field] = isset($data[$field]) ? 1 : 0;
            }
        }

        $result = $prefModel->updateForUser($userId, $updateData);

        return $this->response->json([
            'success' => $result,
            'message' => $result ? 'تنظیمات ذخیره شد' : 'خطا در ذخیره تنظیمات',
        ]);
    }

    // =========================================================================
    // Internal
    // =========================================================================

    private function getNewNotifications(int $userId, int $lastId): array
    {
        if ($lastId === 0) {
            // اولین connect — فقط unread count برگردان
            return [
                'success'       => true,
                'notifications' => [],
                'unread_count'  => $this->notificationService->getUnreadCount($userId),
            ];
        }

        $rows = $this->notificationModel->db->query(
            "SELECT *
             FROM notifications
             WHERE user_id     = ?
               AND id          > ?
               AND is_deleted  = 0
               AND channel     = 'in_app'
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
               AND (expires_at  IS NULL OR expires_at  >  NOW())
             ORDER BY id ASC
             LIMIT 20",
            [$userId, $lastId]
        )->fetchAll(\PDO::FETCH_OBJ);

        if (empty($rows)) {
            return ['success' => true, 'notifications' => [], 'unread_count' => $this->notificationService->getUnreadCount($userId)];
        }

        $this->notificationService->invalidateUnreadCache($userId);

        return [
            'success'       => true,
            'notifications' => $rows,
            'unread_count'  => $this->notificationService->getUnreadCount($userId),
            'last_id'       => end($rows)->id,
        ];
    }
}
