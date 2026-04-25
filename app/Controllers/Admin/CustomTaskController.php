<?php

namespace App\Controllers\Admin;

use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;
use App\Services\CustomTaskService;
use App\Services\WalletService;
use App\Middleware\PermissionMiddleware;
use App\Controllers\Admin\BaseAdminController;

class CustomTaskController extends BaseAdminController
{
    private CustomTaskService $customTaskService;
    private WalletService $walletService;
    private CustomTask $customTaskModel;
    private CustomTaskSubmission $submissionModel;

    public function __construct(
        CustomTaskService $customTaskService,
        WalletService $walletService,
        CustomTask $customTaskModel,
        CustomTaskSubmission $submissionModel
    ) {
        parent::__construct();
        $this->customTaskService = $customTaskService;
        $this->walletService = $walletService;
        $this->customTaskModel = $customTaskModel;
        $this->submissionModel = $submissionModel;
    }

    /**
     * لیست وظایف
     */
    public function index()
    {
        PermissionMiddleware::require('tasks.view');

        $filters = [
            'status' => $this->request->get('status'),
            'task_type' => $this->request->get('task_type'),
            'search' => $this->request->get('search'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $tasks = $this->customTaskModel->adminList($filters, $limit, $offset);
        $total = $this->customTaskModel->adminCount($filters);

        return view('admin.custom-tasks.index', [
            'tasks' => $tasks,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
            'statusLabels' => $this->customTaskModel->statusLabels(),
            'statusClasses' => $this->customTaskModel->statusClasses(),
            'taskTypes' => $this->customTaskModel->taskTypes(),
        ]);
    }

    /**
     * جزئیات وظیفه
     */
    public function show()
    {
        PermissionMiddleware::require('tasks.view');

        $taskId = (int) $this->request->param('id');
        $task = $this->customTaskModel->find($taskId);

        if (!$task) {
            \http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        // لیست submission ها
        $submissions = $this->submissionModel->getByTask($taskId, null, 50, 0);

        return view('admin.custom-tasks.show', [
            'task' => $task,
            'submissions' => $submissions,
            'statusLabels' => $this->customTaskModel->statusLabels(),
            'submissionStatusLabels' => $this->submissionModel->statusLabels(),
        ]);
    }

    /**
     * تأیید/رد وظیفه (Ajax)
     */
    public function approve(): void
    {
        PermissionMiddleware::require('tasks.approve');

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $taskId = (int) ($body['task_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $reason = $body['reason'] ?? null;

        $task = $this->customTaskModel->find($taskId);
        if (!$task) {
            $this->response->json(['success' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        if ($decision === 'approve') {
            $this->customTaskModel->update($taskId, [
                'status' => 'active',
                'approved_by' => $this->userId(),
                'approved_at' => \date('Y-m-d H:i:s'),
            ]);

            $this->logger->activity('custom_task.approve', 'تأیید وظیفه', user_id(), ['task_id' => $taskId]);
            $this->response->json(['success' => true, 'message' => 'وظیفه فعال شد.']);

        } elseif ($decision === 'reject') {
            $this->customTaskModel->update($taskId, [
                'status' => 'rejected',
                'rejection_reason' => $reason ?? 'عدم رعایت قوانین',
            ]);

            // بازگشت بودجه
            $totalReturn = (float) $task->total_budget + (float) $task->site_fee_amount;
            if ($totalReturn > 0) {
                $this->walletService->deposit(
                    (int) $task->creator_id,
                    $totalReturn,
                    $task->currency,
                    [
                        'type' => 'task_refund',
                        'description' => "بازگشت بودجه وظیفه #{$taskId}",
                        'idempotency_key' => "ctask_refund_{$taskId}",
                    ]
                );
            }

            $this->logger->activity('custom_task.reject', 'رد وظیفه', user_id(), ['task_id' => $taskId, 'reason' => $reason]);
            $this->response->json(['success' => true, 'message' => 'وظیفه رد شد و بودجه بازگردانده شد.']);

        } else {
            $this->response->json(['success' => false, 'message' => 'تصمیم نامعتبر.'], 422);
        }
    }

    /**
     * تایید اجباری submission توسط ادمین
     */
    public function forceApproveSubmission(): void
    {
        PermissionMiddleware::require('tasks.manage');

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $submissionId = (int) ($body['submission_id'] ?? 0);

        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            $this->response->json(['ok' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        // استفاده از متد جدید forceApproveSubmissionByAdmin
        $result = $this->customTaskService->forceApproveSubmissionByAdmin(
            $submissionId,
            $this->userId()
        );

        $this->logger->activity('custom_task.force_approve', 'تایید اجباری submission', user_id(), [
            'submission_id' => $submissionId,
            'admin_id' => $this->userId(),
        ]);

        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * رد اجباری submission توسط ادمین
     */
    public function forceRejectSubmission(): void
    {
        PermissionMiddleware::require('tasks.manage');

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $submissionId = (int) ($body['submission_id'] ?? 0);
        $reason = $body['reason'] ?? 'رد توسط ادمین';

        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            $this->response->json(['ok' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        // استفاده از متد جدید forceRejectSubmissionByAdmin
        $result = $this->customTaskService->forceRejectSubmissionByAdmin(
            $submissionId,
            $this->userId(),
            $reason
        );

        $this->logger->activity('custom_task.force_reject', 'رد اجباری submission', user_id(), [
            'submission_id' => $submissionId,
            'admin_id' => $this->userId(),
            'reason' => $reason,
        ]);

        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * آمار و گزارش
     */
    public function stats(): void
    {
        PermissionMiddleware::require('tasks.view');

        // آمار کلی
        $stats = [
            'total_tasks' => $this->customTaskModel->adminCount([]),
            'active_tasks' => $this->customTaskModel->adminCount(['status' => 'active']),
            'pending_tasks' => $this->customTaskModel->adminCount(['status' => 'pending_review']),
            'completed_tasks' => $this->customTaskModel->adminCount(['status' => 'completed']),
        ];

        // آمار submission ها
        $stmt = $this->db->getConnection()->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                SUM(reward_amount) as total_reward
            FROM custom_task_submissions
            GROUP BY status
        ");
        $stmt->execute();
        $submissionStats = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $this->response->json([
            'success' => true,
            'stats' => $stats,
            'submissions' => $submissionStats,
        ]);
    }

    /**
     * لیست اختلافات
     */
    public function disputes()
    {
        PermissionMiddleware::require('tasks.manage');

        $filters = [
            'status' => $this->request->get('status'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $disputeModel = new \App\Models\CustomTaskDispute($this->db);
        $disputes = $disputeModel->adminList($filters, $limit, $offset);
        $total = $disputeModel->adminCount($filters);

        return view('admin.custom-tasks.disputes', [
            'disputes' => $disputes,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
        ]);
    }

    /**
     * حل اختلاف
     */
    public function resolveDispute(): void
    {
        PermissionMiddleware::require('tasks.manage');

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $disputeId = (int) ($body['dispute_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $adminNote = $body['admin_note'] ?? '';

        if (!in_array($decision, ['executor', 'advertiser'])) {
            $this->response->json(['ok' => false, 'message' => 'تصمیم نامعتبر است.'], 422);
            return;
        }

        $disputeService = new \App\Services\CustomTaskDisputeService(
            $this->db,
            new \App\Models\CustomTaskDispute($this->db),
            $this->submissionModel,
            $this->customTaskService
        );

        $result = $disputeService->resolveByAdmin(
            $this->userId(),
            $disputeId,
            $decision,
            $adminNote
        );

        $this->logger->activity('custom_task.resolve_dispute', 'حل اختلاف', user_id(), [
            'dispute_id' => $disputeId,
            'decision' => $decision,
        ]);

        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * لیست گزارش‌های تسک‌ها
     */
    public function reports()
    {
        PermissionMiddleware::require('tasks.manage');

        $filters = [
            'status' => $this->request->get('status'),
            'reason' => $this->request->get('reason'),
        ];

        $page = max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $reportModel = new \App\Models\TaskReport($this->db);
        $reports = $reportModel->adminList($filters, $limit, $offset);
        $total = $reportModel->adminCount($filters);

        return view('admin.custom-tasks.reports', [
            'reports' => $reports,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
            'filters' => $filters,
            'statusLabels' => $reportModel->statusLabels(),
            'reasonLabels' => $reportModel->reasonLabels(),
        ]);
    }

    /**
     * بررسی گزارش (Ajax)
     */
    public function reviewReport(): void
    {
        PermissionMiddleware::require('tasks.manage');

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $reportId = (int) ($body['report_id'] ?? 0);
        $status = $body['status'] ?? '';
        $adminNote = $body['admin_note'] ?? '';

        if (!in_array($status, ['reviewed', 'resolved', 'rejected'])) {
            $this->response->json(['success' => false, 'message' => 'وضعیت نامعتبر است.'], 422);
            return;
        }

        $reportModel = new \App\Models\TaskReport($this->db);
        $report = $reportModel->find($reportId);

        if (!$report) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }

        $updated = $reportModel->update($reportId, [
            'status' => $status,
            'admin_id' => $this->userId(),
            'admin_note' => $adminNote,
            'resolved_at' => ($status === 'resolved') ? date('Y-m-d H:i:s') : null,
        ]);

        if ($updated) {
            // اگر resolved شد، ممکنه تسک رو غیرفعال کنیم
            if ($status === 'resolved' && $report->reason === 'fraud') {
                $this->customTaskModel->update($report->task_id, [
                    'status' => 'rejected',
                    'rejection_reason' => 'گزارش شده به دلیل تقلب',
                ]);
            }

            $this->logger->activity('custom_task.review_report', 'بررسی گزارش', user_id(), [
                'report_id' => $reportId,
                'status' => $status,
            ]);

            $this->response->json(['success' => true, 'message' => 'گزارش با موفقیت بررسی شد.'], 200);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در بررسی گزارش.'], 500);
        }
    }

    /**
     * داشبورد آمار کلی
     */
    public function analytics()
    {
        PermissionMiddleware::require('tasks.view');

        $analyticsService = new \App\Services\CustomTaskAnalyticsService(
            $this->db,
            \Core\Cache::getInstance()
        );

        // آمار کلی
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_tasks,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as pending_review,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(total_budget) as total_budget,
                SUM(spent_budget) as spent_budget
            FROM custom_tasks
            WHERE deleted_at IS NULL
        ");
        $stmt->execute();
        $taskStats = $stmt->fetch(\PDO::FETCH_ASSOC);

        // آمار submission ها
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'disputed' THEN 1 ELSE 0 END) as disputed
            FROM custom_task_submissions
        ");
        $stmt->execute();
        $submissionStats = $stmt->fetch(\PDO::FETCH_ASSOC);

        // تسک‌های پرطرفدار
        $trending = $analyticsService->getTrendingTasks(10);

        return view('admin.custom-tasks.analytics', [
            'taskStats' => $taskStats,
            'submissionStats' => $submissionStats,
            'trending' => $trending,
        ]);
    }

    /**
     * عملیات دسته‌جمعی (Batch Operations)
     */
    public function batchAction(): void
    {
        PermissionMiddleware::require('tasks.manage');

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';
        $ids = $body['ids'] ?? [];

        if (!in_array($action, ['approve_all', 'reject_all', 'pause_all', 'delete_all'])) {
            $this->response->json(['success' => false, 'message' => 'عملیات نامعتبر است.'], 422);
            return;
        }

        if (empty($ids) || !is_array($ids)) {
            $this->response->json(['success' => false, 'message' => 'هیچ موردی انتخاب نشده است.'], 422);
            return;
        }

        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $id = (int) $id;
            
            try {
                switch ($action) {
                    case 'approve_all':
                        $result = $this->customTaskService->forceApproveSubmissionByAdmin($id, $this->userId());
                        break;
                    
                    case 'reject_all':
                        $result = $this->customTaskService->forceRejectSubmissionByAdmin($id, $this->userId(), 'رد دسته‌جمعی توسط مدیریت');
                        break;
                    
                    case 'pause_all':
                        $updated = $this->customTaskModel->update($id, ['status' => 'paused']);
                        $result = ['ok' => $updated];
                        break;
                    
                    case 'delete_all':
                        $deleted = $this->customTaskModel->softDelete($id);
                        $result = ['ok' => $deleted];
                        break;
                    
                    default:
                        $result = ['ok' => false];
                }

                if ($result['ok'] ?? false) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $this->logger->activity('custom_task.batch_action', 'عملیات دسته‌جمعی', user_id(), [
            'action' => $action,
            'total' => count($ids),
            'success' => $success,
            'failed' => $failed,
        ]);

        $this->response->json([
            'success' => true,
            'message' => "موفق: {$success}، ناموفق: {$failed}",
            'stats' => ['success' => $success, 'failed' => $failed]
        ], 200);
    }
}
