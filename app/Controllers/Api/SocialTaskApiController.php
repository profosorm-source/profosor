<?php

namespace App\Controllers\Api;

use App\Services\SocialTask\SocialTaskService;
use App\Services\SocialTask\SilentAntiFraudService;
use App\Services\SocialTask\TrustScoreService;
use Core\Database;
use Core\Logger;
use App\Models\SocialAccount;
use App\Models\Advertisement;
use App\Models\TaskExecution;
use App\Models\CustomTaskDispute;

/**
 * SocialTaskApiController - API برای سیستم وظایف اجتماعی
 *
 * Endpoints:
 * - GET /api/v1/social/accounts
 * - POST /api/v1/social/accounts
 * - PUT /api/v1/social/accounts/{id}
 * - DELETE /api/v1/social/accounts/{id}
 * - GET /api/v1/social/ads
 * - POST /api/v1/social/ads
 * - GET /api/v1/social/ads/{id}
 * - POST /api/v1/social/ads/{id}/pause
 * - POST /api/v1/social/ads/{id}/resume
 * - POST /api/v1/social/ads/{id}/cancel
 * - GET /api/v1/social/tasks
 * - POST /api/v1/social/tasks/{id}/start
 * - POST /api/v1/social/tasks/{id}/submit
 * - GET /api/v1/social/tasks/history
 * - POST /api/v1/social/executions/{id}/dispute
 * - GET /api/v1/social/disputes
 * 
 * Legacy endpoints (برای سازگاری عقب‌رو):
 * - POST /api/social-tasks/behavior
 * - POST /api/social-tasks/camera-verify
 * - GET /api/social-tasks/trust-status
 */
class SocialTaskApiController extends BaseApiController
{
    private SocialTaskService      $service;
    private SilentAntiFraudService $antiFraud;
    private TrustScoreService      $trust;
    private Database               $db;
    private Logger                 $logger;
    private SocialAccount          $socialAccountModel;
    private Advertisement          $advertisementModel;
    private TaskExecution          $taskExecutionModel;
    private CustomTaskDispute      $disputeModel;

    public function __construct(
        SocialTaskService     $service,
        SilentAntiFraudService $antiFraud,
        TrustScoreService     $trust,
        Database              $db,
        Logger                $logger
    ) {
        parent::__construct();
        $this->service   = $service;
        $this->antiFraud = $antiFraud;
        $this->trust     = $trust;
        $this->db        = $db;
        $this->logger    = $logger;
    }

    // ═════════════════════════════════════════════════════════════
    // SOCIAL ACCOUNTS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست حساب‌های اجتماعی کاربر
     * GET /api/v1/social/accounts
     */
    public function accounts(): void
    {
        $user = $this->currentUser();
        
        $accounts = $this->db->fetchAll(
            "SELECT * FROM social_accounts WHERE user_id = ? AND deleted_at IS NULL",
            [$user->id]
        );

        $this->success($accounts);
    }

    /**
     * ایجاد حساب اجتماعی جدید
     * POST /api/v1/social/accounts
     */
    public function storeAccount(): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();

        $platform = trim((string)($data['platform'] ?? ''));
        $account_handle = trim((string)($data['account_handle'] ?? ''));
        $access_token = trim((string)($data['access_token'] ?? ''));

        if (empty($platform) || empty($account_handle)) {
            $this->validationError(['platform' => 'الزامی', 'account_handle' => 'الزامی']);
        }

        $result = $this->service->addAccount($user->id, $platform, $account_handle, $access_token);
        $this->success($result);
    }

    /**
     * به‌روزرسانی حساب اجتماعی
     * PUT /api/v1/social/accounts/{id}
     */
    public function updateAccount(string $id): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();

        $account = $this->db->fetch(
            "SELECT * FROM social_accounts WHERE id = ? AND user_id = ?",
            [(int)$id, $user->id]
        );
        
        if (!$account) {
            $this->error('حساب پیدا نشد', 404);
        }

        $this->db->query(
            "UPDATE social_accounts SET account_handle = ?, access_token = ?, updated_at = NOW() WHERE id = ?",
            [$data['account_handle'] ?? $account->account_handle, $data['access_token'] ?? $account->access_token, (int)$id]
        );

        $this->success(['id' => (int)$id, 'message' => 'حساب به‌روز شد']);
    }

    /**
     * حذف حساب اجتماعی
     * DELETE /api/v1/social/accounts/{id}
     */
    public function deleteAccount(string $id): void
    {
        $user = $this->currentUser();

        $account = $this->db->fetch(
            "SELECT * FROM social_accounts WHERE id = ? AND user_id = ?",
            [(int)$id, $user->id]
        );
        
        if (!$account) {
            $this->error('حساب پیدا نشد', 404);
        }

        $this->db->query(
            "UPDATE social_accounts SET deleted_at = NOW() WHERE id = ?",
            [(int)$id]
        );

        $this->success(['id' => (int)$id, 'message' => 'حساب حذف شد']);
    }

    // ═════════════════════════════════════════════════════════════
    // ADVERTISEMENTS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست تبلیغات کاربر
     * GET /api/v1/social/ads
     */
    public function myAds(): void
    {
        $user = $this->currentUser();
        $params = $this->paginationParams();

        $ads = $this->db->fetchAll(
            "SELECT * FROM advertisements WHERE user_id = ? AND deleted_at IS NULL LIMIT ?, ?",
            [$user->id, ($params['page'] - 1) * $params['per_page'], $params['per_page']]
        );

        $total = (int)$this->db->fetch(
            "SELECT COUNT(*) as cnt FROM advertisements WHERE user_id = ? AND deleted_at IS NULL",
            [$user->id]
        )->cnt;

        $this->paginated($ads, $total, $params['page'], $params['per_page']);
    }

    /**
     * ایجاد تبلیغ جدید
     * POST /api/v1/social/ads
     */
    public function createAd(): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();

        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $budget = (float)($data['budget'] ?? 0);

        if (empty($title) || $budget <= 0) {
            $this->validationError(['title' => 'الزامی', 'budget' => 'الزامی و باید بیشتر از صفر باشد']);
        }

        $adId = $this->db->query(
            "INSERT INTO advertisements (user_id, title, description, budget, status, created_at) 
             VALUES (?, ?, ?, ?, 'active', NOW())",
            [$user->id, $title, $description, $budget]
        );

        $this->success(['id' => $adId, 'message' => 'تبلیغ با موفقیت ایجاد شد'], 'تبلیغ ایجاد شد', 201);
    }

    /**
     * نمایش تبلیغ
     * GET /api/v1/social/ads/{id}
     */
    public function showAd(string $id): void
    {
        $user = $this->currentUser();

        $ad = $this->db->fetch(
            "SELECT * FROM advertisements WHERE id = ? AND user_id = ?",
            [(int)$id, $user->id]
        );
        
        if (!$ad) {
            $this->error('تبلیغ پیدا نشد', 404);
        }

        $this->success($ad);
    }

    /**
     * توقف موقت تبلیغ
     * POST /api/v1/social/ads/{id}/pause
     */
    public function pauseAd(string $id): void
    {
        $user = $this->currentUser();

        $ad = $this->db->fetch(
            "SELECT * FROM advertisements WHERE id = ? AND user_id = ?",
            [(int)$id, $user->id]
        );
        
        if (!$ad) {
            $this->error('تبلیغ پیدا نشد', 404);
        }

        $this->db->query(
            "UPDATE advertisements SET status = 'paused', updated_at = NOW() WHERE id = ?",
            [(int)$id]
        );

        $this->success(['id' => (int)$id, 'status' => 'paused']);
    }

    /**
     * از سر گیری تبلیغ
     * POST /api/v1/social/ads/{id}/resume
     */
    public function resumeAd(string $id): void
    {
        $user = $this->currentUser();

        $ad = $this->db->fetch(
            "SELECT * FROM advertisements WHERE id = ? AND user_id = ?",
            [(int)$id, $user->id]
        );
        
        if (!$ad) {
            $this->error('تبلیغ پیدا نشد', 404);
        }

        $this->db->query(
            "UPDATE advertisements SET status = 'active', updated_at = NOW() WHERE id = ?",
            [(int)$id]
        );

        $this->success(['id' => (int)$id, 'status' => 'active']);
    }

    /**
     * لغو تبلیغ
     * POST /api/v1/social/ads/{id}/cancel
     */
    public function cancelAd(string $id): void
    {
        $user = $this->currentUser();

        $ad = $this->db->fetch(
            "SELECT * FROM advertisements WHERE id = ? AND user_id = ?",
            [(int)$id, $user->id]
        );
        
        if (!$ad) {
            $this->error('تبلیغ پیدا نشد', 404);
        }

        $this->db->query(
            "UPDATE advertisements SET deleted_at = NOW() WHERE id = ?",
            [(int)$id]
        );

        $this->success(['id' => (int)$id, 'message' => 'تبلیغ لغو شد']);
    }

    // ═════════════════════════════════════════════════════════════
    // TASKS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست وظایف موجود برای کاربر
     * GET /api/v1/social/tasks
     */
    public function tasks(): void
    {
        $user = $this->currentUser();
        $params = $this->paginationParams();

        $tasks = $this->db->fetchAll(
            "SELECT * FROM custom_tasks WHERE status = 'active' AND deleted_at IS NULL LIMIT ?, ?",
            [($params['page'] - 1) * $params['per_page'], $params['per_page']]
        );

        $total = (int)$this->db->fetch(
            "SELECT COUNT(*) as cnt FROM custom_tasks WHERE status = 'active' AND deleted_at IS NULL"
        )->cnt;

        $this->paginated($tasks, $total, $params['page'], $params['per_page']);
    }

    /**
     * شروع اجرای وظیفه
     * POST /api/v1/social/tasks/{id}/start
     */
    public function startTask(string $id): void
    {
        $user = $this->currentUser();

        $task = $this->db->fetch(
            "SELECT * FROM custom_tasks WHERE id = ?",
            [(int)$id]
        );
        
        if (!$task) {
            $this->error('وظیفه پیدا نشد', 404);
        }

        $result = $this->service->startTask($user->id, (int)$id);
        $this->success($result);
    }

    /**
     * ارسال نتیجه وظیفه
     * POST /api/v1/social/tasks/{id}/submit
     */
    public function submitTask(string $id): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();

        $result = $this->service->submitTask($user->id, (int)$id, $data);
        $this->success($result);
    }

    /**
     * تاریخچه وظایف کاربر
     * GET /api/v1/social/tasks/history
     */
    public function history(): void
    {
        $user = $this->currentUser();
        $params = $this->paginationParams();

        $history = $this->db->fetchAll(
            "SELECT * FROM task_executions WHERE user_id = ? ORDER BY created_at DESC LIMIT ?, ?",
            [$user->id, ($params['page'] - 1) * $params['per_page'], $params['per_page']]
        );

        $total = (int)$this->db->fetch(
            "SELECT COUNT(*) as cnt FROM task_executions WHERE user_id = ?",
            [$user->id]
        )->cnt;

        $this->paginated($history, $total, $params['page'], $params['per_page']);
    }

    // ═════════════════════════════════════════════════════════════
    // DISPUTES
    // ═════════════════════════════════════════════════════════════

    /**
     * باز کردن dispute برای اجرای وظیفه
     * POST /api/v1/social/executions/{id}/dispute
     */
    public function openDispute(string $id): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();

        $execution = $this->db->fetch(
            "SELECT * FROM task_executions WHERE id = ? AND user_id = ?",
            [(int)$id, $user->id]
        );
        
        if (!$execution) {
            $this->error('اجرای وظیفه پیدا نشد', 404);
        }

        $result = $this->service->openDispute($user->id, (int)$id, $data);
        $this->success($result);
    }

    /**
     * لیست disputeهای کاربر
     * GET /api/v1/social/disputes
     */
    public function disputes(): void
    {
        $user = $this->currentUser();
        $params = $this->paginationParams();

        $disputes = $this->db->fetchAll(
            "SELECT * FROM custom_task_disputes WHERE user_id = ? ORDER BY created_at DESC LIMIT ?, ?",
            [$user->id, ($params['page'] - 1) * $params['per_page'], $params['per_page']]
        );

        $total = (int)$this->db->fetch(
            "SELECT COUNT(*) as cnt FROM custom_task_disputes WHERE user_id = ?",
            [$user->id]
        )->cnt;

        $this->paginated($disputes, $total, $params['page'], $params['per_page']);
    }

    // ═════════════════════════════════════════════════════════════
    // LEGACY ENDPOINTS (برای سازگاری عقب‌رو)
    // ═════════════════════════════════════════════════════════════

    // ─────────────────────────────────────────────────────────────
    // ثبت behavior signals (موبایل در حین انجام)
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/social-tasks/behavior
     * Body: {
     *   execution_id: int,
     *   signals: {
     *     tap_count, swipe_count, scroll_count, touch_pauses,
     *     touch_timing_variance, scroll_speed_variance, scroll_pauses,
     *     session_duration, active_time, reconnect_count,
     *     app_blur_count, max_blur_duration,
     *     hesitation_count, avg_action_delay_ms, natural_delay_count
     *   }
     * }
     */
    public function recordBehavior(): void
    {
        $user = $this->currentUser();
        $body = $this->request->body();
        $executionId = (int)($body['execution_id'] ?? 0);
        $signals = (array)($body['signals'] ?? []);

        if (!$executionId) {
            $this->error('execution_id الزامی است', 400);
        }

        // فقط فیلدهای مجاز
        $allowedSignals = [
            'tap_count', 'swipe_count', 'scroll_count', 'touch_pauses',
            'touch_timing_variance', 'scroll_speed_variance', 'scroll_pauses',
            'session_duration', 'active_time', 'reconnect_count',
            'app_blur_count', 'max_blur_duration',
            'hesitation_count', 'avg_action_delay_ms', 'natural_delay_count',
        ];
        $filtered = array_intersect_key($signals, array_flip($allowedSignals));

        $success = $this->service->recordBehaviorSignals($executionId, $user->id, $filtered);

        $this->success(['success' => $success]);
    }

    // ─────────────────────────────────────────────────────────────
    // Camera Verification Signal
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/social-tasks/camera-verify
     *
     * عکس هرگز ذخیره یا ارسال نمی‌شود.
     * موبایل نتیجه پردازش ML محلی را به صورت امتیاز ارسال می‌کند.
     *
     * Body: {
     *   execution_id: int,
     *   camera_score: int (0–100 — نتیجه ML محلی),
     *   task_type: string,
     *   verified_signals: string[] (مثلاً ['follow_button_visible','username_match'])
     * }
     */
    public function cameraVerify(): void
    {
        $user = $this->currentUser();
        $body = $this->request->body();

        $executionId     = (int)($body['execution_id'] ?? 0);
        $cameraScore     = (int)($body['camera_score'] ?? 0);
        $verifiedSignals = (array)($body['verified_signals'] ?? []);

        if (!$executionId) {
            $this->error('execution_id الزامی است', 400);
        }

        // Camera score فقط یک سیگنال است — ذخیره در behavior_data
        $signals = [
            'camera_score'      => max(0, min(100, $cameraScore)),
            'camera_signals'    => $verifiedSignals,
            'camera_verified_at'=> time(),
        ];

        $this->service->recordBehaviorSignals($executionId, $user->id, $signals);

        $this->success([
            'camera_score' => $cameraScore,
            'message'      => 'سیگنال دوربین دریافت شد',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // وضعیت Trust کاربر
    // ─────────────────────────────────────────────────────────────

    /**
     * GET /api/social-tasks/trust-status
     */
    public function trustStatus(): void
    {
        $user = $this->currentUser();

        $trust   = $this->trust->get($user->id);
        $weekly  = $this->trust->getWeeklyStats($user->id);

        $this->success([
            'trust_score'  => $trust,
            'weekly'       => $weekly,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────
}
