<?php

namespace App\Controllers\Admin;

use App\Services\BackupService;
use Core\Logger;

/**
 * Controller: BackupManagementController
 * مدیریت پشتیبان‌گیری و بازیابی دیتابیس
 */
class BackupManagementController
{
    private BackupService $backupService;
    private Logger $logger;

    public function __construct(BackupService $backupService, Logger $logger)
    {
        $this->backupService = $backupService;
        $this->logger = $logger;
    }

    /**
     * نمایش لیست پشتیبان‌ها
     */
    public function index()
    {
        try {
            $backups = $this->backupService->getBackups(50, 0);
            $stats = $this->backupService->getBackupStats();

            view('admin/backups/index', [
                'backups' => $backups['backups'] ?? [],
                'stats' => $stats,
                'success' => $backups['success'] ?? false
            ]);

        } catch (\Exception $e) {
            $this->logger->error('admin.backups.index.failed', ['error' => $e->getMessage()]);
            flash('خطا: دریافت لیست پشتیبان‌ها ناموفق بود', 'error');
            redirect('/admin/dashboard');
        }
    }

    /**
     * ایجاد پشتیبان جدید
     */
    public function createBackup()
    {
        try {
            $description = $_POST['description'] ?? null;

            $result = $this->backupService->createBackup($description);

            if ($result['success']) {
                $this->logger->info('admin.backup.created', [
                    'filename' => $result['filename'],
                    'size' => $result['size']
                ]);
                flash("پشتیبان با موفقیت ایجاد شد: {$result['filename']}", 'success');
            } else {
                flash("خطا: {$result['error']}", 'error');
            }

            redirect('/admin/backups');

        } catch (\Exception $e) {
            $this->logger->error('admin.backup.create.failed', ['error' => $e->getMessage()]);
            flash('خطا: ایجاد پشتیبان ناموفق بود', 'error');
            redirect('/admin/backups');
        }
    }

    /**
     * بازیابی از پشتیبان (محدود به ادمین‌های ارشد فقط)
     */
    public function restoreBackup()
    {
        try {
            $backupId = $_POST['backup_id'] ?? null;

            if (!$backupId) {
                flash('شناسه پشتیبان الزامی است', 'error');
                redirect('/admin/backups');
                return;
            }

            // برای امنیت، فقط ادمین‌های ارشد می‌توانند restore کنند
            // این قسمت نیاز به verification کاملی دارد
            flash('بازیابی از پشتیبان فعلاً محدود شده است. لطفاً از طریق بک‌اند انجام دهید.', 'warning');
            redirect('/admin/backups');

        } catch (\Exception $e) {
            $this->logger->error('admin.backup.restore.failed', ['error' => $e->getMessage()]);
            flash('خطا: بازیابی ناموفق بود', 'error');
            redirect('/admin/backups');
        }
    }

    /**
     * نمایش آمار پشتیبان‌ها
     */
    public function stats()
    {
        try {
            $stats = $this->backupService->getBackupStats();

            view('admin/backups/stats', ['stats' => $stats]);

        } catch (\Exception $e) {
            $this->logger->error('admin.backups.stats.failed', ['error' => $e->getMessage()]);
            flash('خطا: دریافت آمار ناموفق بود', 'error');
            redirect('/admin/dashboard');
        }
    }

    /**
     * پاک‌سازی پشتیبان‌های قدیمی
     */
    public function cleanup()
    {
        try {
            $daysToKeep = (int)($_POST['days_to_keep'] ?? 30);

            $result = $this->backupService->cleanupOldBackups($daysToKeep);

            if ($result['success']) {
                $this->logger->info('admin.backup.cleanup', [
                    'deleted' => $result['deleted'],
                    'days_to_keep' => $daysToKeep
                ]);
                flash("پاک‌سازی انجام شد: {$result['deleted']} پشتیبان حذف شد", 'success');
            } else {
                flash("خطا: {$result['error']}", 'error');
            }

            redirect('/admin/backups');

        } catch (\Exception $e) {
            $this->logger->error('admin.backup.cleanup.failed', ['error' => $e->getMessage()]);
            flash('خطا: پاک‌سازی ناموفق بود', 'error');
            redirect('/admin/backups');
        }
    }
}
