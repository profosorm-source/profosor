<?php

namespace App\Controllers\User;

use App\Models\User;
use App\Services\UserSettingsService;
use Core\Logger;

/**
 * SettingsController — مدیریت تنظیمات پیشرفته کاربر
 */
class SettingsController extends BaseUserController
{
    private User $userModel;
    private UserSettingsService $settingsService;
    private Logger $logger;

    public function __construct(
        User $userModel,
        UserSettingsService $settingsService,
        Logger $logger
    ) {
        parent::__construct();
        $this->userModel = $userModel;
        $this->settingsService = $settingsService;
        $this->logger = $logger;
    }

    /**
     * صفحه تنظیمات عمومی
     */
    public function general(): void
    {
        try {
            $userId = user_id();
            $settings = $this->settingsService->getAll($userId);

            view('user/settings/general', [
                'title' => 'تنظیمات عمومی',
                'settings' => $settings,
                'timezones' => timezone_identifiers_list(),
                'themes' => [
                    'light' => 'روشن',
                    'dark' => 'تاریک',
                    'auto' => 'خودکار',
                ],
                'languages' => [
                    'fa' => 'فارسی',
                    'en' => 'English',
                ],
                'date_formats' => [
                    'jalali' => 'تاریخ جلالی',
                    'gregorian' => 'تاریخ میلادی',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.general.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تنظیمات');
            redirect(url('/dashboard'));
        }
    }

    /**
     * بروزرسانی تنظیمات عمومی
     */
    public function updateGeneral(): void
    {
        try {
            $userId = user_id();

            $settings = [
                'language' => $this->request->post('language') ?? 'fa',
                'timezone' => $this->request->post('timezone') ?? 'Asia/Tehran',
                'theme' => $this->request->post('theme') ?? 'light',
                'date_format' => $this->request->post('date_format') ?? 'jalali',
                'items_per_page' => (int)($this->request->post('items_per_page') ?? 20),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', 'تنظیمات ذخیره شد');
                $this->logger->info('settings.general.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', 'خطا در ذخیره تنظیمات');
            }

            redirect(url('/settings/general'));
        } catch (\Exception $e) {
            $this->logger->error('settings.general.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای داخلی سرور');
            redirect(url('/settings/general'));
        }
    }

    /**
     * صفحه تنظیمات حریم خصوصی
     */
    public function privacy(): void
    {
        try {
            $userId = user_id();
            $settings = $this->settingsService->getAll($userId);

            view('user/settings/privacy', [
                'title' => 'تنظیمات حریم خصوصی',
                'settings' => $settings,
                'visibility_options' => [
                    'public' => 'عمومی',
                    'friends' => 'فقط دوستان',
                    'private' => 'خصوصی',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.privacy.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تنظیمات');
            redirect(url('/dashboard'));
        }
    }

    /**
     * بروزرسانی تنظیمات حریم خصوصی
     */
    public function updatePrivacy(): void
    {
        try {
            $userId = user_id();

            $settings = [
                'profile_visibility' => $this->request->post('profile_visibility') ?? 'public',
                'show_online_status' => (bool)$this->request->post('show_online_status'),
                'show_activity' => (bool)$this->request->post('show_activity'),
                'allow_messages' => (bool)$this->request->post('allow_messages'),
                'allow_friend_requests' => (bool)$this->request->post('allow_friend_requests'),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', 'تنظیمات ذخیره شد');
                $this->logger->info('settings.privacy.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', 'خطا در ذخیره تنظیمات');
            }

            redirect(url('/settings/privacy'));
        } catch (\Exception $e) {
            $this->logger->error('settings.privacy.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای داخلی سرور');
            redirect(url('/settings/privacy'));
        }
    }

    /**
     * صفحه تنظیمات امنیتی
     */
    public function security(): void
    {
        try {
            $userId = user_id();
            $settings = $this->settingsService->getAll($userId);
            $user = $this->userModel->findById($userId);

            view('user/settings/security', [
                'title' => 'تنظیمات امنیتی',
                'settings' => $settings,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.security.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تنظیمات');
            redirect(url('/dashboard'));
        }
    }

    /**
     * بروزرسانی تنظیمات امنیتی
     */
    public function updateSecurity(): void
    {
        try {
            $userId = user_id();

            $settings = [
                'login_alerts' => (bool)$this->request->post('login_alerts'),
                'suspicious_activity_alerts' => (bool)$this->request->post('suspicious_activity_alerts'),
                'session_timeout' => (int)($this->request->post('session_timeout') ?? 30),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', 'تنظیمات امنیتی بروزرسانی شد');
                $this->logger->info('settings.security.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', 'خطا در ذخیره تنظیمات');
            }

            redirect(url('/settings/security'));
        } catch (\Exception $e) {
            $this->logger->error('settings.security.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای داخلی سرور');
            redirect(url('/settings/security'));
        }
    }

    /**
     * صفحه تنظیمات اعلان‌ها
     */
    public function notifications(): void
    {
        try {
            $userId = user_id();
            $settings = $this->settingsService->getAll($userId);

            view('user/settings/notifications', [
                'title' => 'تنظیمات اعلان‌ها',
                'settings' => $settings,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.notifications.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تنظیمات');
            redirect(url('/dashboard'));
        }
    }

    /**
     * بروزرسانی تنظیمات اعلان‌ها
     */
    public function updateNotifications(): void
    {
        try {
            $userId = user_id();

            $settings = [
                'email_notifications' => (bool)$this->request->post('email_notifications'),
                'push_notifications' => (bool)$this->request->post('push_notifications'),
                'sms_notifications' => (bool)$this->request->post('sms_notifications'),
                'marketing_emails' => (bool)$this->request->post('marketing_emails'),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', 'تنظیمات اعلان‌ها ذخیره شد');
                $this->logger->info('settings.notifications.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', 'خطا در ذخیره تنظیمات');
            }

            redirect(url('/settings/notifications'));
        } catch (\Exception $e) {
            $this->logger->error('settings.notifications.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای داخلی سرور');
            redirect(url('/settings/notifications'));
        }
    }

    /**
     * صفحه صادر کردن داده‌ها
     */
    public function dataExport(): void
    {
        try {
            $userId = user_id();

            view('user/settings/data-export', [
                'title' => 'صادر کردن داده‌های من',
                'export_formats' => [
                    'json' => 'JSON',
                    'csv' => 'CSV',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.data_export.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری صفحه');
            redirect(url('/dashboard'));
        }
    }

    /**
     * حذف حساب کاربری
     */
    public function accountDeletion(): void
    {
        try {
            view('user/settings/account-deletion', [
                'title' => 'حذف حساب کاربری',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری صفحه');
            redirect(url('/dashboard'));
        }
    }

    /**
     * درخواست حذف حساب کاربری
     */
    public function requestAccountDeletion(): void
    {
        try {
            $userId = user_id();
            $password = $this->request->post('password') ?? '';

            if (empty($password)) {
                $this->session->setFlash('error', 'رمزعبور الزامی است');
                redirect(url('/settings/account-deletion'));
                return;
            }

            $user = $this->userModel->findById($userId);
            if (!$user || !password_verify($password, $user['password'])) {
                $this->session->setFlash('error', 'رمزعبور نادرست');
                redirect(url('/settings/account-deletion'));
                return;
            }

            // ذخیره درخواست حذف حساب (7 روز موقت‌شدن)
            $this->db->query(
                "UPDATE users SET account_deletion_requested_at = NOW(), account_deletion_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?",
                [$userId]
            );

            $this->session->setFlash('success', 'درخواست شما ثبت شد. حساب شما در 7 روز حذف خواهد شد');
            $this->logger->warning('settings.account_deletion_requested', ['user_id' => $userId]);

            redirect(url('/dashboard'));
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion_request.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای داخلی سرور');
            redirect(url('/settings/account-deletion'));
        }
    }

    /**
     * لغو درخواست حذف حساب کاربری
     */
    public function cancelAccountDeletion(): void
    {
        try {
            $userId = user_id();

            $this->db->query(
                "UPDATE users SET account_deletion_requested_at = NULL, account_deletion_expires_at = NULL WHERE id = ?",
                [$userId]
            );

            $this->session->setFlash('success', 'درخواست حذف حساب لغو شد');
            $this->logger->info('settings.account_deletion_cancelled', ['user_id' => $userId]);

            redirect(url('/settings/account-deletion'));
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion_cancel.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای داخلی سرور');
            redirect(url('/settings/account-deletion'));
        }
    }
}