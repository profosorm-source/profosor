<?php

namespace App\Services;

use Core\Database;
use Core\Logger;

/**
 * BackupService — سرویس پشتیبان‌گیری و بازیابی دیتابیس
 *
 * ویژگی‌ها:
 * - ایجاد پشتیبان دستی یا خودکار
 * - بازیابی از فایل پشتیبان
 * - مدیریت پشتیبان‌های قدیمی
 * - فشرده‌سازی فایل‌های پشتیبان
 */
class BackupService
{
    private Database $db;
    private Logger $logger;
    private string $backupDir;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->backupDir = storage_path('backups');

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * ایجاد پشتیبان دیتابیس
     */
    public function createBackup(?string $description = null): array
    {
        try {
            $timestamp = date('YmdHis');
            $filename = "backup_{$timestamp}.sql";
            $filepath = $this->backupDir . '/' . $filename;

            // دریافت نام دیتابیس از تنظیمات
            $dbName = env('DB_DATABASE', 'chortke');
            $dbUser = env('DB_USERNAME', 'root');
            $dbPass = env('DB_PASSWORD', '');
            $dbHost = env('DB_HOST', 'localhost');

            // دستور mysqldump
            $command = sprintf(
                'mysqldump --host=%s --user=%s --password=%s %s > %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                $dbPass ? escapeshellarg($dbPass) : '',
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Exception('mysqldump failed: ' . implode("\n", $output));
            }

            // فشرده‌سازی فایل
            $gzFilepath = $filepath . '.gz';
            exec("gzip " . escapeshellarg($filepath), $compressOutput, $compressCode);

            $fileSize = filesize($gzFilepath ?? $filepath);

            $this->logger->info('backup.created', [
                'filename' => $filename,
                'size' => $fileSize,
                'description' => $description,
                'timestamp' => $timestamp
            ]);

            // ذخیره اطلاعات پشتیبان
            $this->db->query(
                "INSERT INTO backup_logs (filename, size, description, created_at)
                 VALUES (?, ?, ?, NOW())",
                [$filename, $fileSize, $description]
            );

            return [
                'success' => true,
                'filename' => basename($gzFilepath ?? $filepath),
                'size' => $this->formatBytes($fileSize),
                'path' => $gzFilepath ?? $filepath,
                'timestamp' => $timestamp
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.creation_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت لیست پشتیبان‌ها
     */
    public function getBackups(int $limit = 50, int $offset = 0): array
    {
        try {
            $logs = $this->db->fetch(
                "SELECT * FROM backup_logs ORDER BY created_at DESC LIMIT ? OFFSET ?",
                [$limit, $offset]
            ) ?? [];

            return [
                'success' => true,
                'backups' => is_array($logs) ? $logs : ($logs ? [$logs] : []),
                'count' => count(is_array($logs) ? $logs : ($logs ? [$logs] : []))
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * حذف پشتیبان قدیمی‌ها (قدیمی‌تر از X روز)
     */
    public function cleanupOldBackups(int $daysToKeep = 30): array
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));

            // دریافت فایل‌های قدیمی
            $oldBackups = $this->db->fetchAll(
                "SELECT filename FROM backup_logs WHERE created_at < ?",
                [$cutoffDate]
            ) ?? [];

            $deleted = 0;
            foreach ($oldBackups as $backup) {
                $backup = (array)$backup;
                $filepath = $this->backupDir . '/' . $backup['filename'];
                $gzPath = $filepath . '.gz';

                if (file_exists($gzPath)) {
                    unlink($gzPath);
                    $deleted++;
                } elseif (file_exists($filepath)) {
                    unlink($filepath);
                    $deleted++;
                }
            }

            // حذف سوابق
            $this->db->query(
                "DELETE FROM backup_logs WHERE created_at < ?",
                [$cutoffDate]
            );

            $this->logger->info('backup.cleanup_completed', ['deleted' => $deleted]);

            return [
                'success' => true,
                'deleted' => $deleted,
                'message' => "Deleted {$deleted} old backups (older than {$daysToKeep} days)"
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.cleanup_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * بازیابی از پشتیبان
     */
    public function restoreBackup(string $filename): array
    {
        try {
            $filepath = $this->backupDir . '/' . $filename;
            $gzFilepath = $filepath . '.gz';

            // بررسی وجود فایل
            if (file_exists($gzFilepath)) {
                // unzip
                exec("gunzip " . escapeshellarg($gzFilepath), $output, $exitCode);
                if ($exitCode !== 0) {
                    throw new \Exception('Failed to unzip backup file');
                }
            } elseif (!file_exists($filepath)) {
                throw new \Exception('Backup file not found');
            }

            // دریافت تنظیمات دیتابیس
            $dbName = env('DB_DATABASE', 'chortke');
            $dbUser = env('DB_USERNAME', 'root');
            $dbPass = env('DB_PASSWORD', '');
            $dbHost = env('DB_HOST', 'localhost');

            // دستور mysql import
            $command = sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                $dbPass ? escapeshellarg($dbPass) : '',
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Exception('mysql import failed: ' . implode("\n", $output));
            }

            $this->logger->info('backup.restored', [
                'filename' => $filename,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'message' => 'Backup restored successfully'
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.restore_failed', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت آمار پشتیبان‌ها
     */
    public function getBackupStats(): array
    {
        try {
            $stats = $this->db->fetchOne(
                "SELECT 
                    COUNT(*) as total_backups,
                    SUM(size) as total_size,
                    MAX(created_at) as last_backup,
                    MIN(created_at) as first_backup
                 FROM backup_logs"
            );

            return [
                'success' => true,
                'total_backups' => (int)($stats->total_backups ?? 0),
                'total_size' => $this->formatBytes((int)($stats->total_size ?? 0)),
                'last_backup' => $stats->last_backup ?? null,
                'first_backup' => $stats->first_backup ?? null
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * تبدیل بایت به فرمت خوانا
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
