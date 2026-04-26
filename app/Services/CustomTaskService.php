<?php

namespace App\Services;

use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;
use App\Models\CustomTaskDispute;
use App\Services\WalletService;
use App\Services\UserLevelService;
use App\Services\ReferralCommissionService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\IPQualityService;
use App\Services\SessionService;
use App\Services\LogService;
use App\Services\NotificationService;
use Core\Database;

/**
 * سرویس مدیریت Custom Tasks
 * نسخه بهبودیافته با استفاده از ساختار موجود پروژه
 */
class CustomTaskService
{
    private CustomTask $taskModel;
    private CustomTaskSubmission $submissionModel;
    private CustomTaskDispute $disputeModel;
    private Database $db;
    private WalletService $walletService;
    private UserLevelService $userLevelService;
    private ReferralCommissionService $referralService;
    private LogService $logger;
    private NotificationService $notificationService;
    
    // استفاده از سیستم Anti-Fraud موجود
    private BrowserFingerprintService $fingerprintService;
    private IPQualityService $ipQualityService;
    private SessionService $sessionService;

    public function __construct(
        Database $db,
        WalletService $walletService,
        UserLevelService $userLevelService,
        ReferralCommissionService $referralService,
        LogService $logger,
        NotificationService $notificationService,
        CustomTask $taskModel,
        CustomTaskSubmission $submissionModel,
        CustomTaskDispute $disputeModel,
        BrowserFingerprintService $fingerprintService,
        IPQualityService $ipQualityService,
        SessionService $sessionService
    ) {
        $this->db = $db;
        $this->walletService = $walletService;
        $this->userLevelService = $userLevelService;
        $this->referralService = $referralService;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->disputeModel = $disputeModel;
        $this->fingerprintService = $fingerprintService;
        $this->ipQualityService = $ipQualityService;
        $this->sessionService = $sessionService;
    }

    /**
     * ایجاد وظیفه جدید
     */
    public function createTask(int $creatorId, array $data): array
    {
        // بررسی فعال بودن از setting (نه کانفیگ!)
        if (!setting('custom_task_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم وظایف سفارشی غیرفعال است.'];
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_quantity'] ?? 1);

        // بررسی حداقل قیمت از setting
        $minPrice = $currency === 'usdt'
            ? (float) setting('custom_task_min_price_usdt', 0.50)
            : (float) setting('custom_task_min_price_irt', 5000);

        if ($pricePerTask < $minPrice) {
            $label = $currency === 'usdt' 
                ? number_format($minPrice, 2) . ' USDT' 
                : number_format($minPrice) . ' تومان';
            return ['success' => false, 'message' => "حداقل قیمت هر تسک {$label} است."];
        }

        // محاسبه بودجه - از setting
        $feePercent = (float) setting('custom_task_site_fee_percent', 10);
        $totalBudget = $pricePerTask * $quantity;
        $feeAmount = round($totalBudget * ($feePercent / 100), 2);
        $totalWithFee = $totalBudget + $feeAmount;

        try {
            $this->db->beginTransaction();

            // کسر بودجه از کیف پول
            $idempotencyKey = "ctask_budget_{$creatorId}_" . time() . '_' . bin2hex(random_bytes(4));
            
            $txId = $this->walletService->withdraw(
                $creatorId,
                $totalWithFee,
                $currency,
                [
                    'type' => 'task_budget',
                    'description' => "بودجه وظیفه: {$data['title']}",
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            if (!$txId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست.'];
            }

            // وضعیت اولیه از setting
            $status = setting('custom_task_auto_approve', 0) ? 'active' : 'pending_review';

            // ایجاد تسک با Model موجود
            $task = $this->taskModel->create([
                'creator_id' => $creatorId,
                'title' => $data['title'],
                'description' => $data['description'],
                'link' => $data['link'] ?? null,
                'task_type' => $data['task_type'] ?? 'custom',
                'proof_type' => $data['proof_type'] ?? 'screenshot',
                'proof_description' => $data['proof_description'] ?? null,
                'sample_image' => $data['sample_image'] ?? null,
                'price_per_task' => $pricePerTask,
                'currency' => $currency,
                'total_budget' => $totalBudget,
                'total_quantity' => $quantity,
                'deadline_hours' => $data['deadline_hours'] ?? 24,
                'country_restriction' => $data['country_restriction'] ?? null,
                'device_restriction' => $data['device_restriction'] ?? 'all',
                'os_restriction' => $data['os_restriction'] ?? null,
                'daily_limit_per_user' => $data['daily_limit_per_user'] ?? 1,
                'status' => $status,
                'site_fee_percent' => $feePercent,
                'site_fee_amount' => $feeAmount,
            ]);

            if (!$task) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ایجاد وظیفه.'];
            }

            $this->db->commit();

            $this->logger->info('Custom task created', [
                'task_id' => $task->id,
                'creator_id' => $creatorId,
                'budget' => $totalWithFee,
            ]);

            // ارسال نوتیفیکیشن به سازنده
            $this->notificationService->send(
                $creatorId,
                'task_created',
                'وظیفه شما با موفقیت ثبت شد',
                "وظیفه «{$data['title']}» با وضعیت {$status} ثبت شد.",
                [
                    'task_id' => $task->id,
                    'url' => "/user/custom-tasks/my-tasks/{$task->id}"
                ]
            );

            return [
                'success' => true,
                'message' => 'وظیفه با موفقیت ثبت شد.',
                'task' => $task,
            ];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.create.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در ثبت وظیفه.'];
}
    }

    /**
     * شروع انجام تسک با استفاده از Anti-Fraud موجود
     */
    public function startTask(int $taskId, int $workerId): array
    {
        $task = $this->taskModel->find($taskId);

        if (!$task || $task->status !== 'active') {
            return ['success' => false, 'message' => 'وظیفه فعال نیست.'];
        }

        if ($task->creator_id === $workerId) {
            return ['success' => false, 'message' => 'نمی‌توانید وظیفه خودتان را انجام دهید.'];
        }

        // بررسی تکراری
        if ($this->submissionModel->hasWorkerDone($taskId, $workerId)) {
            return ['success' => false, 'message' => 'شما قبلاً این وظیفه را انجام داده‌اید.'];
        }

        // بررسی سقف روزانه - از setting
        $maxDaily = (int) setting('custom_task_max_daily_submissions', 20);
        if ($this->submissionModel->todayCount($workerId) >= $maxDaily) {
            return ['success' => false, 'message' => "سقف انجام تسک روزانه ({$maxDaily}) تکمیل شده."];
        }

        // ظرفیت باقی‌مانده
        $remaining = $task->total_quantity - $task->completed_count - $task->pending_count;
        if ($remaining <= 0) {
            return ['success' => false, 'message' => 'ظرفیت این وظیفه تکمیل شده.'];
        }

        // استفاده از Anti-Fraud موجود پروژه
        $riskScore = $this->calculateRiskScore($workerId, $taskId);
        
        // بررسی آستانه ریسک - از setting
        $riskThreshold = (float) setting('custom_task_risk_threshold', 70.0);
        if ($riskScore >= $riskThreshold) {
            $this->logger->warning('High risk task start attempt', [
                'worker_id' => $workerId,
                'task_id' => $taskId,
                'risk_score' => $riskScore,
            ]);
            // ارسال به صف بررسی دستی یا رد مستقیم
            $autoReject = setting('custom_task_auto_reject_high_risk', 0);
            if ($autoReject) {
                return ['success' => false, 'message' => 'امتیاز ریسک شما بالا است. لطفاً بعداً تلاش کنید.'];
            }
        }

        try {
            $this->db->beginTransaction();

            $deadlineAt = date('Y-m-d H:i:s', strtotime("+{$task->deadline_hours} hours"));
            $idempotencyKey = "ctask_sub_{$taskId}_{$workerId}_" . date('Ymd_His');

            // بررسی تکراری idempotency
            $stmt = $this->db->getConnection()->prepare(
                "SELECT id FROM custom_task_submissions WHERE idempotency_key = :key"
            );
            $stmt->execute(['key' => $idempotencyKey]);
            if ($stmt->fetch()) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست تکراری است.'];
            }

            // محاسبه پاداش با بونوس
            $rewardAmount = $this->userLevelService->applyEarningBonus(
                $workerId,
                (float) $task->price_per_task
            );

            // ایجاد submission
            $submission = $this->submissionModel->create([
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'deadline_at' => $deadlineAt,
                'reward_amount' => $rewardAmount,
                'reward_currency' => $task->currency,
                'idempotency_key' => $idempotencyKey,
                'worker_ip' => get_client_ip(),
                'worker_device' => get_user_agent(),
                'worker_fingerprint' => generate_device_fingerprint(),
            ]);

            if (!$submission) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در شروع وظیفه.'];
            }

            // به‌روزرسانی شمارنده pending
            $this->taskModel->update($taskId, [
                'pending_count' => $task->pending_count + 1,
            ]);

            $this->db->commit();

            $this->logger->info('Task started', [
                'submission_id' => $submission->id,
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'risk_score' => $riskScore,
            ]);

            return [
                'success' => true,
                'message' => 'وظیفه با موفقیت شروع شد.',
                'submission_id' => $submission->id,
                'deadline' => $deadlineAt,
            ];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.start.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در شروع وظیفه.'];
}
    }

    /**
     * محاسبه ریسک با استفاده از Anti-Fraud موجود
     */
    private function calculateRiskScore(int $userId, int $taskId): float
    {
        $scores = [];

        // 1. بررسی کیفیت IP از سرویس موجود
        try {
            $ipQuality = $this->ipQualityService->checkIP(get_client_ip());
            $scores[] = $ipQuality['fraud_score'] ?? 0;
        } catch (\Exception $e) {
            $this->logger->warning('IP quality check failed', ['error' => $e->getMessage()]);
        }

        // 2. بررسی Browser Fingerprint
        try {
            $fingerprint = generate_device_fingerprint();
            $fpCheck = $this->fingerprintService->analyze($fingerprint, $userId);
            if ($fpCheck['is_suspicious']) {
                $scores[] = 60; // امتیاز بالا برای fingerprint مشکوک
            }
        } catch (\Exception $e) {
            $this->logger->warning('Fingerprint check failed', ['error' => $e->getMessage()]);
        }

        // 3. بررسی Session Anomaly
        try {
            $sessionCheck = $this->sessionAnomalyService->detect($userId);
            if ($sessionCheck['has_anomaly']) {
                $scores[] = 50;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Session anomaly check failed', ['error' => $e->getMessage()]);
        }

        // 4. بررسی تکراری بودن (سرعت submission)
        $recentCount = $this->submissionModel->todayCount($userId);
        $dailyLimit = (int) setting('custom_task_max_daily_submissions', 20);
        if ($recentCount > $dailyLimit * 0.8) {
            $scores[] = 40; // نزدیک به سقف
        }

        // محاسبه میانگین
        return empty($scores) ? 0 : round(array_sum($scores) / count($scores), 2);
    }

    /**
     * ارسال مدرک
     */
    public function submitProof(int $submissionId, int $workerId, array $proofData): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission || $submission->worker_id !== $workerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if ($submission->status !== 'in_progress') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        // بررسی deadline
        if (strtotime($submission->deadline_at) < time()) {
            return ['success' => false, 'message' => 'مهلت ارسال به پایان رسیده.'];
        }

        // بررسی تکراری بودن proof
        if (!empty($proofData['proof_file_hash'])) {
            if ($this->submissionModel->isDuplicateImage(
                $proofData['proof_file_hash'],
                $submission->task_id
            )) {
                return ['success' => false, 'message' => 'این مدرک قبلاً ارسال شده است.'];
            }
        }

        try {
            $this->db->beginTransaction();

            $updateData = [
                'proof_text' => $proofData['proof_text'] ?? null,
                'proof_file' => $proofData['proof_file'] ?? null,
                'proof_file_hash' => $proofData['proof_file_hash'] ?? null,
                'submitted_at' => date('Y-m-d H:i:s'),
                'status' => 'submitted',
            ];

            $this->submissionModel->update($submissionId, $updateData);

            $this->db->commit();

            $this->logger->info('Proof submitted', [
                'submission_id' => $submissionId,
                'worker_id' => $workerId,
            ]);

            // ارسال نوتیفیکیشن به سازنده تسک
            $task = $this->taskModel->find($submission->task_id);
            $this->notificationService->send(
                $task->creator_id,
                'task_proof_submitted',
                'مدرک جدید دریافت شد',
                "مدرک جدیدی برای وظیفه «{$task->title}» ارسال شد و منتظر بررسی است.",
                [
                    'task_id' => $task->id,
                    'submission_id' => $submissionId,
                    'url' => "/user/custom-tasks/submissions/{$submissionId}"
                ]
            );

            // بررسی تایید خودکار - از setting
            $autoApproveHours = (int) setting('custom_task_auto_approve_hours', 48);
            
            return [
                'success' => true,
                'message' => 'مدرک شما با موفقیت ارسال شد.',
                'auto_approve_info' => "در صورت عدم بررسی توسط تبلیغ‌دهنده تا {$autoApproveHours} ساعت آینده، به‌صورت خودکار تایید خواهد شد.",
            ];

        }catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.proof_submission.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در ارسال مدرک.'];
}
    }

    /**
     * بررسی و تایید/رد
     */
    public function reviewSubmission(
        int $submissionId,
        int $reviewerId,
        string $decision,
        ?string $reason = null
    ): array {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        if ($submission->creator_id !== $reviewerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if ($submission->status !== 'submitted') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        if (!in_array($decision, ['approve', 'reject'])) {
            return ['success' => false, 'message' => 'تصمیم نامعتبر.'];
        }

        if ($decision === 'approve') {
            return $this->approveSubmission($submission);
        } else {
            return $this->rejectSubmission($submission, $reason);
        }
    }

    /**
     * تایید submission
     */
    private function approveSubmission(object $submission): array
    {
        try {
            $this->db->beginTransaction();

            // به‌روزرسانی وضعیت
            $this->submissionModel->update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            // پرداخت پاداش
            $this->payWorkerReward($submission);

            // به‌روزرسانی آمار تسک
            $task = $this->taskModel->find($submission->task_id);
            $this->taskModel->update($task->id, [
                'completed_count' => $task->completed_count + 1,
                'pending_count' => max(0, $task->pending_count - 1),
                'spent_budget' => $task->spent_budget + $submission->reward_amount,
            ]);

            $this->db->commit();

            $this->logger->info('Submission approved', [
                'submission_id' => $submission->id,
                'worker_id' => $submission->worker_id,
            ]);

            // ارسال نوتیفیکیشن به انجام‌دهنده
            $this->notificationService->send(
                $submission->worker_id,
                'task_submission_approved',
                'مدرک شما تایید شد',
                "مدرک شما برای وظیفه «{$submission->task_title}» تایید شد و پاداش پرداخت گردید.",
                [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reward' => $submission->reward_amount,
                    'currency' => $submission->reward_currency,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            );

            return ['success' => true, 'message' => 'درخواست تایید شد.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.approval.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در تایید.'];
}
    }

    /**
     * رد submission
     */
    private function rejectSubmission(object $submission, ?string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $this->submissionModel->update($submission->id, [
                'status' => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason,
            ]);

            // کاهش شمارنده pending
            $task = $this->taskModel->find($submission->task_id);
            $this->taskModel->update($task->id, [
                'pending_count' => max(0, $task->pending_count - 1),
            ]);

            $this->db->commit();

            $this->logger->info('Submission rejected', [
                'submission_id' => $submission->id,
                'reason' => $reason,
            ]);

            // ارسال نوتیفیکیشن به انجام‌دهنده
            $this->notificationService->send(
                $submission->worker_id,
                'task_submission_rejected',
                'مدرک شما رد شد',
                "متأسفانه مدرک شما برای وظیفه «{$submission->task_title}» رد شد. دلیل: {$reason}",
                [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reason' => $reason,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            );

            return ['success' => true, 'message' => 'درخواست رد شد.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.rejection.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در رد درخواست.'];
}
    }

    /**
     * پرداخت پاداش
     */
    private function payWorkerReward(object $submission): void
    {
        $idempotencyKey = "ctask_reward_{$submission->id}_" . time();

        $txId = $this->walletService->deposit(
            $submission->worker_id,
            $submission->reward_amount,
            $submission->reward_currency,
            [
                'type' => 'task_reward',
                'description' => "پاداش وظیفه #{$submission->task_id}",
                'idempotency_key' => $idempotencyKey,
            ]
        );

        if ($txId) {
            $this->submissionModel->update($submission->id, [
                'reward_paid' => 1,
                'reward_transaction_id' => $txId,
            ]);

            // پرداخت کمیسیون
            $this->referralService->processCommission(
                $submission->worker_id,
                'task_reward',
                $submission->id,
                $submission->reward_amount,
                $submission->reward_currency
            );
        }
    }

    // متدهای Query ساده برای Controller ها

    public function find(int $id): ?object
    {
        return $this->taskModel->find($id);
    }

    public function getAvailableTasks(int $workerId, array $filters, int $limit, int $offset): array
    {
        return $this->taskModel->getAvailable($workerId, $filters, $limit, $offset);
    }

    public function getMyTasks(int $creatorId, ?string $status, int $limit, int $offset): array
    {
        return $this->taskModel->getByCreator($creatorId, $status, $limit, $offset);
    }

    public function getMySubmissions(int $workerId, ?string $status, int $limit, int $offset): array
    {
        return $this->submissionModel->getByWorker($workerId, $status, $limit, $offset);
    }

    /**
     * تایید اجباری توسط ادمین (برای حل اختلاف)
     */
    public function forceApproveSubmissionByAdmin(int $submissionId, int $adminId): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return ['ok' => false, 'message' => 'یافت نشد.'];
        }

        if (!in_array($submission->status, ['submitted', 'disputed', 'rejected'])) {
            return ['ok' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        try {
            $this->db->beginTransaction();

            // به‌روزرسانی وضعیت
            $this->submissionModel->update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            // پرداخت پاداش
            $this->payWorkerReward($submission);

            // به‌روزرسانی آمار تسک
            $task = $this->taskModel->find($submission->task_id);
            
            // اگر قبلا رد شده بود، pending_count تغییر نمیکنه
            $pendingDecrease = in_array($submission->status, ['submitted']) ? 1 : 0;
            
            $this->taskModel->update($task->id, [
                'completed_count' => $task->completed_count + 1,
                'pending_count' => max(0, $task->pending_count - $pendingDecrease),
                'spent_budget' => $task->spent_budget + $submission->reward_amount,
            ]);

            $this->db->commit();

            $this->logger->info('Submission force approved by admin', [
                'submission_id' => $submission->id,
                'admin_id' => $adminId,
            ]);

            return ['ok' => true, 'message' => 'درخواست توسط ادمین تایید شد.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.force_approval.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['ok' => false, 'message' => 'خطا در تایید.'];
}
    }

    /**
     * رد اجباری توسط ادمین (برای حل اختلاف)
     */
    public function forceRejectSubmissionByAdmin(int $submissionId, int $adminId, ?string $reason = null): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return ['ok' => false, 'message' => 'یافت نشد.'];
        }

        if (!in_array($submission->status, ['submitted', 'disputed', 'approved'])) {
            return ['ok' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        try {
            $this->db->beginTransaction();

            $this->submissionModel->update($submission->id, [
                'status' => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason ?? 'رد شده توسط مدیریت',
            ]);

            // کاهش شمارنده pending
            $task = $this->taskModel->find($submission->task_id);
            
            $pendingDecrease = in_array($submission->status, ['submitted']) ? 1 : 0;
            
            $this->taskModel->update($task->id, [
                'pending_count' => max(0, $task->pending_count - $pendingDecrease),
            ]);

            $this->db->commit();

            $this->logger->info('Submission force rejected by admin', [
                'submission_id' => $submission->id,
                'admin_id' => $adminId,
                'reason' => $reason,
            ]);

            return ['ok' => true, 'message' => 'درخواست توسط ادمین رد شد.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.force_rejection.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['ok' => false, 'message' => 'خطا در رد درخواست.'];
}
    }

    /**
     * ثبت امتیاز برای یک submission
     */
    public function rateSubmission(int $submissionId, int $raterId, array $ratingData): array
    {
        if (!setting('custom_task_rating_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم امتیازدهی غیرفعال است.'];
        }

        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        if ($submission->status !== 'approved') {
            return ['success' => false, 'message' => 'فقط می‌توانید به submission های تایید شده امتیاز دهید.'];
        }

        $rating = (int) ($ratingData['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'message' => 'امتیاز باید بین 1 تا 5 باشد.'];
        }

        $reviewText = trim($ratingData['review_text'] ?? '');
        $minLength = (int) setting('custom_task_min_rating_text_length', 20);
        
        if (!empty($reviewText) && mb_strlen($reviewText) < $minLength) {
            return ['success' => false, 'message' => "متن نظر باید حداقل {$minLength} کاراکتر باشد."];
        }

        // تشخیص نوع امتیاز
        $task = $this->taskModel->find($submission->task_id);
        
        if ($raterId == $task->creator_id) {
            // creator داره به worker امتیاز میده
            $ratingType = 'worker';
            $ratedUserId = $submission->worker_id;
        } elseif ($raterId == $submission->worker_id) {
            // worker داره به creator امتیاز میده
            $ratingType = 'creator';
            $ratedUserId = $task->creator_id;
        } else {
            return ['success' => false, 'message' => 'شما مجاز به امتیازدهی نیستید.'];
        }

        try {
            $ratingModel = new \App\Models\TaskRating($this->db);

            // بررسی تکراری
            if ($ratingModel->hasRated($submissionId, $raterId, $ratingType)) {
                return ['success' => false, 'message' => 'شما قبلاً امتیاز داده‌اید.'];
            }

            $this->db->beginTransaction();

            $ratingObj = $ratingModel->create([
                'task_id' => $task->id,
                'submission_id' => $submissionId,
                'rater_id' => $raterId,
                'rated_user_id' => $ratedUserId,
                'rating_type' => $ratingType,
                'rating' => $rating,
                'review_text' => $reviewText ?: null,
            ]);

            if (!$ratingObj) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز.'];
            }

            // به‌روزرسانی میانگین امتیاز تسک
            $this->updateTaskRating($task->id);

            $this->db->commit();

            // ارسال نوتیفیکیشن
            $this->notificationService->send(
                $ratedUserId,
                'new_rating_received',
                'امتیاز جدید دریافت کردید',
                "امتیاز {$rating} ستاره برای وظیفه «{$task->title}» دریافت کردید.",
                [
                    'rating_id' => $ratingObj->id,
                    'task_id' => $task->id,
                    'rating' => $rating
                ]
            );

            return [
                'success' => true,
                'message' => 'امتیاز با موفقیت ثبت شد.',
                'rating' => $ratingObj
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('rating.create.failed', [
                'error' => $e->getMessage(),
                'submission_id' => $submissionId,
            ]);
            return ['success' => false, 'message' => 'خطا در ثبت امتیاز.'];
        }
    }

    /**
     * به‌روزرسانی میانگین امتیاز یک تسک
     */
    private function updateTaskRating(int $taskId): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total, AVG(rating) as average
            FROM task_ratings
            WHERE task_id = ?
        ");
        $stmt->execute([$taskId]);
        $stats = $stmt->fetch(\PDO::FETCH_OBJ);

        if ($stats) {
            $this->taskModel->update($taskId, [
                'average_rating' => round($stats->average, 2),
                'total_ratings' => $stats->total,
            ]);
        }
    }

    /**
     * ثبت بازدید تسک
     */
    public function recordTaskView(int $taskId, int $userId): void
    {
        // افزایش شمارنده بازدید
        $this->db->prepare("
            UPDATE custom_tasks 
            SET view_count = view_count + 1 
            WHERE id = ?
        ")->execute([$taskId]);

        // ثبت در آمار روزانه
        $this->db->prepare("
            INSERT INTO task_analytics (task_id, date, views)
            VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE views = views + 1
        ")->execute([$taskId]);
    }

    /**
     * افزودن/حذف از علاقه‌مندی‌ها
     */
    public function toggleFavorite(int $taskId, int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM task_favorites 
                WHERE task_id = ? AND user_id = ?
            ");
            $stmt->execute([$taskId, $userId]);
            $exists = $stmt->fetch();

            $this->db->beginTransaction();

            if ($exists) {
                // حذف از علاقه‌مندی‌ها
                $this->db->prepare("
                    DELETE FROM task_favorites 
                    WHERE task_id = ? AND user_id = ?
                ")->execute([$taskId, $userId]);

                $this->db->prepare("
                    UPDATE custom_tasks 
                    SET favorite_count = GREATEST(0, favorite_count - 1)
                    WHERE id = ?
                ")->execute([$taskId]);

                $message = 'از علاقه‌مندی‌ها حذف شد.';
                $isFavorite = false;
            } else {
                // اضافه به علاقه‌مندی‌ها
                $this->db->prepare("
                    INSERT INTO task_favorites (task_id, user_id)
                    VALUES (?, ?)
                ")->execute([$taskId, $userId]);

                $this->db->prepare("
                    UPDATE custom_tasks 
                    SET favorite_count = favorite_count + 1
                    WHERE id = ?
                ")->execute([$taskId]);

                $message = 'به علاقه‌مندی‌ها اضافه شد.';
                $isFavorite = true;
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => $message,
                'is_favorite' => $isFavorite
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در عملیات.'];
        }
    }

    /**
     * دریافت آمار تفصیلی یک تسک
     */
    public function getTaskAnalytics(int $taskId, int $days = 30): array
    {
        // آمار کلی
        $stmt = $this->db->prepare("
            SELECT * FROM task_stats_view WHERE id = ?
        ");
        $stmt->execute([$taskId]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

        // آمار روزانه
        $stmt = $this->db->prepare("
            SELECT date, views, starts, submissions, approvals, rejections
            FROM task_analytics
            WHERE task_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY date DESC
        ");
        $stmt->execute([$taskId, $days]);
        $dailyStats = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // توزیع امتیازها
        $ratingModel = new \App\Models\TaskRating($this->db);
        $ratings = $ratingModel->getTaskRatings($taskId, 100, 0);

        return [
            'overall' => $stats,
            'daily' => $dailyStats,
            'ratings' => $ratings,
        ];
    }

    /**
     * Auto-approve submissions که مدت زیادی بررسی نشده‌اند
     */
    public function autoApproveOldSubmissions(): int
    {
        $hours = (int) setting('custom_task_auto_approve_hours', 48);
        
        $stmt = $this->db->prepare("
            SELECT id FROM custom_task_submissions
            WHERE status = 'submitted' 
            AND submitted_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
            LIMIT 100
        ");
        $stmt->execute([$hours]);
        $submissions = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $approved = 0;
        foreach ($submissions as $sub) {
            $fullSubmission = $this->submissionModel->find($sub->id);
            if ($fullSubmission) {
                $result = $this->approveSubmission($fullSubmission);
                if ($result['success']) {
                    $approved++;
                    
                    // نوتیفیکیشن تایید خودکار
                    $this->notificationService->send(
                        $fullSubmission->worker_id,
                        'auto_approved',
                        'مدرک شما به صورت خودکار تایید شد',
                        "مدرک شما برای وظیفه «{$fullSubmission->task_title}» به دلیل عدم بررسی توسط تبلیغ‌دهنده، خودکار تایید و پاداش پرداخت شد.",
                        [
                            'submission_id' => $fullSubmission->id,
                            'task_id' => $fullSubmission->task_id
                        ]
                    );
                }
            }
        }

        return $approved;
    }

    /**
     * منقضی کردن submission های deadline گذشته
     */
    public function expireOldSubmissions(): int
    {
        $expired = 0;
        $submissions = $this->submissionModel->getExpiredSubmissions();

        foreach ($submissions as $sub) {
            try {
                $this->db->beginTransaction();

                $this->submissionModel->update($sub->id, [
                    'status' => 'expired',
                ]);

                // کاهش pending count
                $task = $this->taskModel->find($sub->task_id);
                if ($task) {
                    $this->taskModel->update($task->id, [
                        'pending_count' => max(0, $task->pending_count - 1),
                    ]);
                }

                $this->db->commit();
                $expired++;

            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->logger->error('expire_submission_failed', [
                    'submission_id' => $sub->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $expired;
    }
}
