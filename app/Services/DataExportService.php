<?php

namespace App\Services;

use App\Models\DataExport;
use App\Models\User;
use Core\Database;
use Core\Logger;
use Core\Cache;

/**
 * DataExportService — صادرکردن داده‌های کاربر
 */
class DataExportService
{
    private DataExport $exportModel;
    private User $userModel;
    private Database $db;
    private Logger $logger;
    private Cache $cache;

    public function __construct(
        DataExport $exportModel,
        User $userModel,
        Database $db,
        Logger $logger
    ) {
        $this->exportModel = $exportModel;
        $this->userModel = $userModel;
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = Cache::getInstance();
    }

    /**
     * ایجاد درخواست صادرکردن
     */
    public function requestExport(int $userId, string $format): ?int
    {
        if (!in_array($format, ['json', 'csv'])) {
            $this->logger->warning('data_export.invalid_format', ['format' => $format, 'user_id' => $userId]);
            return null;
        }

        try {
            $exportId = $this->exportModel->createExport($userId, $format);
            $this->logger->info('data_export.requested', ['user_id' => $userId, 'format' => $format, 'export_id' => $exportId]);
            return $exportId;
        } catch (\Exception $e) {
            $this->logger->error('data_export.request_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * صادرکردن داده‌های JSON
     */
    public function exportJSON(int $userId): ?string
    {
        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                return null;
            }

            $data = [
                'user' => $this->sanitizeUserData($user),
                'transactions' => $this->getUserTransactions($userId),
                'wallet' => $this->getUserWallet($userId),
                'kyc' => $this->getUserKYC($userId),
                'settings' => $this->getUserSettings($userId),
                'exported_at' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get(),
            ];

            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $this->logger->error('data_export.json_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * صادرکردن داده‌های CSV
     */
    public function exportCSV(int $userId): ?string
    {
        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                return null;
            }

            $csv = "نام,مقدار\n";

            // اطلاعات کاربر
            $csv .= "نام کاربری,\"" . $user['username'] . "\"\n";
            $csv .= "نام کامل,\"" . $user['full_name'] . "\"\n";
            $csv .= "ایمیل,\"" . $user['email'] . "\"\n";
            $csv .= "موبایل,\"" . ($user['mobile'] ?? 'ندارد') . "\"\n";
            $csv .= "تاریخ عضویت,\"" . $user['created_at'] . "\"\n";

            // آمار تراکنش‌ها
            $transactions = $this->getUserTransactions($userId);
            $csv .= "\n--- تراکنش‌ها ---\n";
            $csv .= "کل تراکنش‌ها," . count($transactions) . "\n";
            $totalAmount = array_sum(array_map(fn($t) => $t['amount'], $transactions));
            $csv .= "کل مبلغ," . $totalAmount . " تومان\n";

            // آمار کیف‌پول
            $wallet = $this->getUserWallet($userId);
            $csv .= "\n--- کیف‌پول ---\n";
            $csv .= "موجودی,\"" . ($wallet['balance'] ?? 0) . " تومان\"\n";

            return $csv;
        } catch (\Exception $e) {
            $this->logger->error('data_export.csv_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ذخیره فایل صادرشده
     */
    public function saveExportFile(int $exportId, string $format, string $content): ?string
    {
        try {
            $timestamp = date('Ymdhis');
            $filename = "export_{$exportId}_{$timestamp}.{$format}";
            $filepath = storage_path("exports/{$filename}");

            // ایجاد دایرکتوری اگر وجود نداشته باشد
            if (!is_dir(dirname($filepath))) {
                mkdir(dirname($filepath), 0755, true);
            }

            file_put_contents($filepath, $content);

            // بروزرسانی وضعیت
            $this->exportModel->updateStatus($exportId, 'completed', $filepath);

            $this->logger->info('data_export.file_saved', ['export_id' => $exportId, 'filepath' => $filepath]);
            return $filepath;
        } catch (\Exception $e) {
            $this->logger->error('data_export.save_failed', ['export_id' => $exportId, 'error' => $e->getMessage()]);
            $this->exportModel->updateStatus($exportId, 'failed', null, $e->getMessage());
            return null;
        }
    }

    /**
     * حذف فایل‌های منقضی
     */
    public function deleteExpiredExports(): int
    {
        try {
            $expiredExports = $this->exportModel->getExpiredExports();
            $deleted = 0;

            foreach ($expiredExports as $export) {
                if ($export['file_path'] && file_exists($export['file_path'])) {
                    unlink($export['file_path']);
                }

                // بروزرسانی رکورد
                $this->db->query(
                    "UPDATE data_exports SET file_path = NULL WHERE id = ?",
                    [$export['id']]
                );

                $deleted++;
            }

            $this->logger->info('data_export.expired_deleted', ['count' => $deleted]);
            return $deleted;
        } catch (\Exception $e) {
            $this->logger->error('data_export.delete_expired_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * دریافت تراکنش‌های کاربر
     */
    private function getUserTransactions(int $userId): array
    {
        return $this->db->query(
            "SELECT id, type, amount, status, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 100",
            [$userId]
        ) ?: [];
    }

    /**
     * دریافت کیف‌پول کاربر
     */
    private function getUserWallet(int $userId): array
    {
        return $this->db->queryOne(
            "SELECT balance, currency FROM wallets WHERE user_id = ?",
            [$userId]
        ) ?? [];
    }

    /**
     * دریافت KYC کاربر
     */
    private function getUserKYC(int $userId): array
    {
        return $this->db->queryOne(
            "SELECT status, verified_at, document_type FROM kyc_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1",
            [$userId]
        ) ?? [];
    }

    /**
     * دریافت تنظیمات کاربر
     */
    private function getUserSettings(int $userId): array
    {
        $settings = $this->db->query(
            "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?",
            [$userId]
        ) ?: [];

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = $setting['setting_value'];
        }
        return $result;
    }

    /**
     * پاکسازی داده‌های حساس
     */
    private function sanitizeUserData(array $user): array
    {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'mobile' => $user['mobile'] ?? null,
            'kyc_status' => $user['kyc_status'] ?? null,
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
        ];
    }
}
