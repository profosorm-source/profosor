<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;

/**
 * AppealService - سیستم اعتراض به تصمیمات
 *
 * امکان اعتراض به:
 * - تعلیق حساب به دلیل تقلب
 * - رد درخواست KYC
 * - اختلافات سفارش
 * - رد تأیید حساب اینفلوئنسر
 */
class AppealService
{
    private Database $db;
    private Logger   $logger;

    // محدودیت‌های ارسال اعتراض
    private const APPEAL_LIMITS = [
        'daily'     => 3,   // حداکثر 3 اعتراض در روز
        'weekly'    => 10,  // حداکثر 10 اعتراض در هفته
        'monthly'   => 25   // حداکثر 25 اعتراض در ماه
    ];

    // زمان‌های تصمیم خودکار
    private const AUTO_DECISION_TIMES = [
        'minor' => 3 * 24 * 3600,  // 3 روز برای موارد جزئی
        'major' => 7 * 24 * 3600   // 1 هفته برای موارد مهم
    ];

    public function __construct(Database $db, Logger $logger)
    {
        $this->db     = $db;
        $this->logger = $logger;
    }

    /**
     * ارسال اعتراض جدید
     */
    public function submitAppeal(
        int    $userId,
        string $appealType,
        string $title,
        string $description,
        ?int   $referenceId = null,
        ?string $referenceType = null,
        array  $attachments = []
    ): array {
        // بررسی محدودیت‌ها
        if (!$this->canSubmitAppeal($userId)) {
            throw new \Exception('Appeal submission limit exceeded');
        }

        // بررسی نوع اعتراض
        if (!$this->isValidAppealType($appealType)) {
            throw new \Exception('Invalid appeal type');
        }

        try {
            $this->db->beginTransaction();

            // درج اعتراض
            $appealId = $this->db->insert(
                "INSERT INTO appeals (user_id, appeal_type, reference_id, reference_type, title, description, priority, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    $appealType,
                    $referenceId,
                    $referenceType,
                    $title,
                    $description,
                    $this->determinePriority($appealType)
                ]
            );

            // آپلود پیوست‌ها
            foreach ($attachments as $attachment) {
                $this->addAttachment($appealId, $userId, $attachment);
            }

            // بروزرسانی شمارنده کاربر
            $this->updateUserAppealCount($userId);

            // بررسی تصمیم خودکار
            $autoDecision = $this->checkAutoDecision($appealId, $appealType);

            $this->db->commit();

            $this->logger->info("Appeal submitted", [
                'appeal_id' => $appealId,
                'user_id' => $userId,
                'type' => $appealType
            ]);

            return [
                'appeal_id' => $appealId,
                'auto_decision' => $autoDecision
            ];

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * بررسی امکان ارسال اعتراض
     */
    public function canSubmitAppeal(int $userId): bool
    {
        $user = $this->db->query(
            "SELECT appeal_count, last_appeal_at, appeal_banned_until FROM users WHERE id = ?",
            [$userId]
        )->fetch();

        if (!$user) return false;

        // بررسی محرومیت
        if ($user['appeal_banned_until'] && strtotime($user['appeal_banned_until']) > time()) {
            return false;
        }

        // بررسی محدودیت روزانه
        $today = date('Y-m-d');
        $dailyCount = $this->db->query(
            "SELECT COUNT(*) as count FROM appeals 
             WHERE user_id = ? AND DATE(created_at) = ?",
            [$userId, $today]
        )->fetch()['count'];

        if ($dailyCount >= self::APPEAL_LIMITS['daily']) {
            return false;
        }

        // بررسی محدودیت هفتگی
        $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $weeklyCount = $this->db->query(
            "SELECT COUNT(*) as count FROM appeals 
             WHERE user_id = ? AND created_at >= ?",
            [$userId, $weekAgo]
        )->fetch()['count'];

        if ($weeklyCount >= self::APPEAL_LIMITS['weekly']) {
            return false;
        }

        return true;
    }

    /**
     * بررسی نوع اعتراض معتبر
     */
    private function isValidAppealType(string $type): bool
    {
        $validTypes = [
            'fraud_suspension',
            'kyc_rejection',
            'order_dispute',
            'verification_rejection',
            'account_limitation',
            'payment_dispute'
        ];

        return in_array($type, $validTypes);
    }

    /**
     * تعیین اولویت اعتراض
     */
    private function determinePriority(string $appealType): string
    {
        $priorities = [
            'fraud_suspension'      => 'urgent',
            'account_limitation'    => 'high',
            'kyc_rejection'         => 'high',
            'verification_rejection' => 'medium',
            'order_dispute'         => 'medium',
            'payment_dispute'       => 'low'
        ];

        return $priorities[$appealType] ?? 'medium';
    }

    /**
     * اضافه کردن پیوست
     */
    private function addAttachment(int $appealId, int $userId, array $attachment): void
    {
        $this->db->query(
            "INSERT INTO appeal_attachments 
             (appeal_id, user_id, filename, original_name, file_path, file_size, mime_type, uploaded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $appealId,
                $userId,
                $attachment['filename'],
                $attachment['original_name'],
                $attachment['file_path'],
                $attachment['file_size'],
                $attachment['mime_type']
            ]
        );
    }

    /**
     * بروزرسانی شمارنده اعتراضات کاربر
     */
    private function updateUserAppealCount(int $userId): void
    {
        $this->db->query(
            "UPDATE users SET 
             appeal_count = appeal_count + 1, 
             last_appeal_at = NOW() 
             WHERE id = ?",
            [$userId]
        );
    }

    /**
     * بررسی تصمیم خودکار
     */
    private function checkAutoDecision(int $appealId, string $appealType): ?array
    {
        // تصمیم خودکار فقط برای موارد جزئی
        if (!in_array($appealType, ['verification_rejection', 'order_dispute'])) {
            return null;
        }

        // بررسی شرایط تصمیم خودکار
        $canAutoDecide = $this->canAutoDecide($appealId, $appealType);
        if (!$canAutoDecide) {
            return null;
        }

        // اجرای تصمیم خودکار
        return $this->executeAutoDecision($appealId, $appealType);
    }

    /**
     * بررسی امکان تصمیم خودکار
     */
    private function canAutoDecide(int $appealId, string $appealType): bool
    {
        // بررسی سابقه کاربر
        $appeal = $this->db->query(
            "SELECT a.*, u.appeal_count, u.created_at as user_created_at 
             FROM appeals a 
             JOIN users u ON a.user_id = u.id 
             WHERE a.id = ?",
            [$appealId]
        )->fetch();

        if (!$appeal) return false;

        // کاربران جدید نمی‌توانند تصمیم خودکار بگیرند
        $userAge = time() - strtotime($appeal['user_created_at']);
        if ($userAge < 30 * 24 * 3600) { // کمتر از 30 روز
            return false;
        }

        // کاربران با سابقه اعتراض زیاد نمی‌توانند تصمیم خودکار بگیرند
        if ($appeal['appeal_count'] > 5) {
            return false;
        }

        return true;
    }

    /**
     * اجرای تصمیم خودکار
     */
    private function executeAutoDecision(int $appealId, string $appealType): array
    {
        $decision = $this->makeAutoDecision($appealType);

        $this->db->query(
            "UPDATE appeals SET 
             status = ?, 
             decision = ?, 
             decision_at = NOW(), 
             auto_decision = 1 
             WHERE id = ?",
            [$decision['status'], $decision['decision'], $appealId]
        );

        return $decision;
    }

    /**
     * تصمیم‌گیری خودکار
     */
    private function makeAutoDecision(string $appealType): array
    {
        // تصمیمات خودکار ساده - در عمل باید پیچیده‌تر باشد
        $decisions = [
            'verification_rejection' => [
                'status' => 'approved',
                'decision' => 'بر اساس بررسی خودکار، اعتراض تأیید شد. حساب برای بررسی مجدد ارسال خواهد شد.'
            ],
            'order_dispute' => [
                'status' => 'under_review',
                'decision' => 'اعتراض دریافت شد. موضوع برای بررسی توسط تیم پشتیبانی در صف قرار گرفت.'
            ]
        ];

        return $decisions[$appealType] ?? [
            'status' => 'under_review',
            'decision' => 'اعتراض دریافت شد و در حال بررسی است.'
        ];
    }

    /**
     * گرفتن اعتراضات کاربر
     */
    public function getUserAppeals(int $userId, int $limit = 20, int $offset = 0): array
    {
        $appeals = $this->db->query(
            "SELECT a.*, 
                    COUNT(at.id) as attachment_count,
                    COUNT(ar.id) as response_count
             FROM appeals a
             LEFT JOIN appeal_attachments at ON a.id = at.appeal_id
             LEFT JOIN appeal_responses ar ON a.id = ar.appeal_id
             WHERE a.user_id = ?
             GROUP BY a.id
             ORDER BY a.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        )->fetchAll();

        return $appeals;
    }

    /**
     * گرفتن جزئیات اعتراض
     */
    public function getAppealDetails(int $appealId, int $userId): ?array
    {
        $appeal = $this->db->query(
            "SELECT a.*, u.username as admin_username
             FROM appeals a
             LEFT JOIN users u ON a.admin_id = u.id
             WHERE a.id = ? AND a.user_id = ?",
            [$appealId, $userId]
        )->fetch();

        if (!$appeal) return null;

        // گرفتن پیوست‌ها
        $attachments = $this->db->query(
            "SELECT * FROM appeal_attachments 
             WHERE appeal_id = ? ORDER BY uploaded_at ASC",
            [$appealId]
        )->fetchAll();

        // گرفتن پاسخ‌ها
        $responses = $this->db->query(
            "SELECT ar.*, u.username as admin_username
             FROM appeal_responses ar
             JOIN users u ON ar.admin_id = u.id
             WHERE ar.appeal_id = ? ORDER BY ar.created_at ASC",
            [$appealId]
        )->fetchAll();

        return [
            'appeal' => $appeal,
            'attachments' => $attachments,
            'responses' => $responses
        ];
    }

    /**
     * گرفتن اعتراضات برای ادمین
     */
    public function getAppealsForAdmin(
        string $status = null,
        string $priority = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $conditions = [];
        $params = [];

        if ($status) {
            $conditions[] = "a.status = ?";
            $params[] = $status;
        }

        if ($priority) {
            $conditions[] = "a.priority = ?";
            $params[] = $priority;
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $appeals = $this->db->query(
            "SELECT a.*, u.username, u.email,
                    COUNT(at.id) as attachment_count
             FROM appeals a
             JOIN users u ON a.user_id = u.id
             LEFT JOIN appeal_attachments at ON a.id = at.appeal_id
             {$whereClause}
             GROUP BY a.id
             ORDER BY 
                 CASE a.priority 
                     WHEN 'urgent' THEN 1 
                     WHEN 'high' THEN 2 
                     WHEN 'medium' THEN 3 
                     WHEN 'low' THEN 4 
                 END, a.created_at ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->fetchAll();

        return $appeals;
    }

    /**
     * پاسخ به اعتراض
     */
    public function respondToAppeal(
        int    $appealId,
        int    $adminId,
        string $response,
        string $newStatus = null,
        string $internalNotes = null
    ): void {
        try {
            $this->db->beginTransaction();

            // درج پاسخ
            $this->db->query(
                "INSERT INTO appeal_responses 
                 (appeal_id, admin_id, response, status_change, internal_notes, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$appealId, $adminId, $response, $newStatus, $internalNotes]
            );

            // بروزرسانی وضعیت اعتراض
            if ($newStatus) {
                $this->db->query(
                    "UPDATE appeals SET 
                     status = ?, 
                     admin_id = ?, 
                     decision = ?, 
                     decision_at = NOW(), 
                     updated_at = NOW()
                     WHERE id = ?",
                    [$newStatus, $adminId, $response, $appealId]
                );
            } else {
                $this->db->query(
                    "UPDATE appeals SET 
                     admin_id = ?, 
                     updated_at = NOW()
                     WHERE id = ?",
                    [$adminId, $appealId]
                );
            }

            $this->db->commit();

            $this->logger->info("Appeal response added", [
                'appeal_id' => $appealId,
                'admin_id' => $adminId,
                'new_status' => $newStatus
            ]);

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * گرفتن آمار اعتراضات
     */
    public function getAppealStats(): array
    {
        $stats = $this->db->query(
            "SELECT 
                COUNT(*) as total_appeals,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent,
                SUM(CASE WHEN auto_decision = 1 THEN 1 ELSE 0 END) as auto_decided,
                AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(decision_at, NOW()))) as avg_resolution_hours
             FROM appeals"
        )->fetch();

        return $stats;
    }

    /**
     * گرفتن قالب‌های اعتراض
     */
    public function getAppealTemplates(string $appealType = null): array
    {
        if ($appealType) {
            return $this->db->query(
                "SELECT * FROM appeal_templates 
                 WHERE appeal_type = ? AND is_active = 1 
                 ORDER BY title ASC",
                [$appealType]
            )->fetchAll();
        }

        return $this->db->query(
            "SELECT * FROM appeal_templates 
             WHERE is_active = 1 
             ORDER BY appeal_type ASC, title ASC"
        )->fetchAll();
    }

    /**
     * محرومیت کاربر از ارسال اعتراض
     */
    public function banUserFromAppeals(int $userId, int $days, string $reason): void
    {
        $bannedUntil = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $this->db->query(
            "UPDATE users SET 
             appeal_banned_until = ?, 
             updated_at = NOW()
             WHERE id = ?",
            [$bannedUntil, $userId]
        );

        $this->logger->info("User banned from appeals", [
            'user_id' => $userId,
            'banned_until' => $bannedUntil,
            'reason' => $reason
        ]);
    }
}