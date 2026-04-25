<?php

namespace App\Controllers\User;

use App\Services\SocialTask\RatingService;
use App\Services\SocialTask\SocialTaskService;
use App\Services\SocialTask\TrustScoreService;
use Core\Logger;
use Core\Database;

/**
 * SocialTaskController
 *
 * جایگزین: AdsocialController + TaskController
 *
 * Executor routes:
 *   GET  /social-tasks                    → index (لیست تسک‌ها)
 *   GET  /social-tasks/history            → history
 *   GET  /social-tasks/dashboard          → dashboard
 *   POST /social-tasks/start              → start
 *   GET  /social-tasks/{id}/execute       → showExecute
 *   POST /social-tasks/{id}/submit        → submit
 *
 * Advertiser routes:
 *   GET  /social-ads                      → myAds
 *   GET  /social-ads/dashboard            → advertiserDashboard
 *   GET  /social-ads/create               → create
 *   POST /social-ads/store                → store
 *   GET  /social-ads/{id}                 → show
 *   POST /social-ads/{id}/pause           → pause
 *   POST /social-ads/{id}/resume          → resume
 *   POST /social-ads/{id}/cancel          → cancel
 *   GET  /social-ads/execution/{id}       → executionDetail
 *   POST /social-ads/execution/{id}/approve → approveExecution
 *   POST /social-ads/execution/{id}/reject  → rejectExecution
 */
class SocialTaskController extends BaseUserController
{
    private SocialTaskService $service;
    private TrustScoreService $trustService;
    private RatingService $ratingService;
    private Logger $logger;

    public function __construct(
        SocialTaskService $service,
        TrustScoreService $trustService,
        RatingService $ratingService,
        Logger $logger
    ) {
        parent::__construct();
        $this->service      = $service;
        $this->trustService = $trustService;
        $this->ratingService = $ratingService;
        $this->logger       = $logger;
    }

    // ─────────────────────────────────────────────────────────────
    // EXECUTOR
    // ─────────────────────────────────────────────────────────────

    /**
     * لیست تسک‌ها با فیلتر پیشرفته
     * ✅ Input validation + sanitization
     */
    public function index(): void
    {
        $userId  = (int)user_id();
        
        // ✅ Validate and sanitize filters
        $filters = [
            'platform'   => $this->sanitizeString($this->request->get('platform')),
            'task_type'  => $this->sanitizeString($this->request->get('task_type')),
            'min_reward' => max(0, (float)($this->request->get('min_reward') ?? 0)),
            'max_reward' => min(999999, (float)($this->request->get('max_reward') ?? 999999)),
            'sort'       => $this->validateSort($this->request->get('sort') ?? 'random'),
            'search'     => mb_substr(trim($this->request->get('q') ?? ''), 0, 100),
            'is_mobile'  => $this->isMobileRequest(),
        ];

        $result = $this->service->getTasksForExecutor($userId, $filters, 30);

        view('user.social-tasks.index', [
            'title'       => 'تسک‌های شبکه اجتماعی',
            'tasks'       => $result['tasks'],
            'trust_score' => $result['trust_score'],
            'filters'     => $filters,
            'platforms'   => $this->platformLabels(),
            'task_types'  => $this->taskTypeLabels(),
        ]);
    }

    // ✅ Helper methods for validation
    private function sanitizeString(?string $input): string {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $input ?? '') ?: '';
    }

    private function validateSort(string $sort): string {
        return in_array($sort, ['random', 'newest', 'highest_reward', 'lowest_reward'], true)
            ? $sort
            : 'random';
    }

    /**
     * داشبورد Executor
     */
    public function executorDashboard(): void
    {
        $userId  = (int)user_id();
        $stats   = $this->service->getExecutorStats($userId);
        $history = $this->service->getExecutorHistory($userId, 7);
        $weekly  = $this->trustService->getWeeklyStats($userId);

        view('user.social-tasks.dashboard', [
            'title'        => 'داشبورد تسک‌های اجتماعی',
            'stats'        => $stats,
            'recent'       => $history,
            'trust_score'  => $this->trustService->get($userId),
            'weekly_stats' => $weekly,
        ]);
    }

    /**
     * شروع execution
     * ✅ CSRF token verification + Logger DI
     */
    public function start(): void
    {
        // ✅ CSRF verification
        if (!csrf_verify()) {
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'توکن منقضی شد.'], 419);
                return;
            }
            $this->session->setFlash('error', 'توکن منقضی شد.');
            redirect(url('/social-tasks'));
            return;
        }

        $userId = (int)user_id();
        $adId   = (int)($this->request->body()['ad_id'] ?? 0);

        if ($adId <= 0) {
            $this->response->json(['success' => false, 'message' => 'آگهی نامعتبر.'], 422);
            return;
        }

        try {
            $result = $this->service->startExecution($userId, $adId, [
                'ip'         => get_client_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
            $this->response->json($result);
        } catch (\Exception $e) {
            $this->logger->error('social_task.start.failed', [
                'user_id' => $userId,
                'ad_id' => $adId,
                'error' => $e->getMessage(),
            ]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی. لطفاً دوباره تلاش کنید.']);
        }
    }

    /**
     * صفحه انجام تسک
     * ✅ Ownership verification
     */
    public function showExecute(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');

        // ✅ Verify ownership via database query
        $exec = Database::getInstance()->query(
            "SELECT ste.* FROM social_task_executions ste
             WHERE ste.id = ? AND ste.executor_id = ?",
            [$executionId, $userId]
        )->fetch();

        if (!$exec) {
            http_response_code(403);
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
                return;
            }
            redirect(url('/social-tasks'));
            return;
        }

        view('user.social-tasks.execute', [
            'title'     => 'انجام تسک',
            'execution' => $exec,
            'task'      => $exec,
        ]);
    }

    /**
     * ثبت نهایی تسک
     * ✅ CSRF verification + ownership check
     */
    public function submit(): void
    {
        // ✅ CSRF verification
        if (!csrf_verify()) {
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'توکن منقضی شد.'], 419);
                return;
            }
            $this->session->setFlash('error', 'توکن منقضی شد.');
            redirect(url('/social-tasks'));
            return;
        }

        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');
        $body        = $this->request->body();

        // ✅ Verify ownership
        $exec = Database::getInstance()->query(
            "SELECT id FROM social_task_executions WHERE id = ? AND executor_id = ?",
            [$executionId, $userId]
        )->fetch();

        if (!$exec) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        try {
            $result = $this->service->submitExecution($executionId, $userId, array_merge($body, [
                'ip'          => get_client_ip(),
                'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]));

            if (is_ajax()) {
                $this->response->json($result);
                return;
            }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            $this->logger->error('social_task.submit.failed', [
                'user_id' => $userId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
                return;
            }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }

        redirect(url('/social-tasks'));
    }

    /**
     * تاریخچه
     */
    public function history(): void
    {
        $userId = (int)user_id();
        $page   = max(1, (int)($this->request->get('page') ?? 1));
        $limit  = 20;

        $history = $this->service->getExecutorHistory($userId, $limit, ($page - 1) * $limit);
        $stats   = $this->service->getExecutorStats($userId);

        view('user.social-tasks.history', [
            'title'       => 'تاریخچه تسک‌ها',
            'history'     => $history,
            'stats'       => $stats,
            'trust_score' => $this->trustService->get($userId),
            'page'        => $page,
        ]);
    }

    public function rateExecutionForm(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');
        $execution   = $this->getExecutionForUser($executionId, $userId);
        $role        = 'executor';

        if (!$execution) {
            $execution = $this->getExecutionForAdvertiser($userId, $executionId);
            $role = 'advertiser';
        }

        if (!$execution) {
            redirect(url('/social-tasks'));
            return;
        }

        if ($this->ratingService->hasRated($executionId, $userId, $role)) {
            $this->session->setFlash('error', 'شما قبلاً برای این اجرا امتیاز داده‌اید.');
            redirect(url('/social-tasks/history'));
            return;
        }

        view('user.social-tasks.rating-form', [
            'title'        => 'ثبت امتیاز تسک',
            'execution'    => $execution,
            'role'         => $role,
            'target_user'  => $role === 'executor' ? $execution->advertiser_name ?? '' : $execution->executor_name ?? '',
        ]);
    }

    public function rateExecution(): void
    {
        if (!csrf_verify()) {
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'توکن منقضی شد.'], 419);
                return;
            }
            $this->session->setFlash('error', 'توکن منقضی شد.');
            redirect(url('/social-tasks'));
            return;
        }

        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');
        $stars       = min(5, max(1, (int)($this->request->post('stars') ?? 0)));
        $comment     = trim($this->request->post('comment') ?? '');

        $execution = $this->getExecutionForUser($executionId, $userId);
        $role      = 'executor';

        if (!$execution) {
            $execution = $this->getExecutionForAdvertiser($userId, $executionId);
            $role = 'advertiser';
        }

        if (!$execution) {
            $this->session->setFlash('error', 'اجرای مورد نظر پیدا نشد.');
            redirect(url('/social-tasks'));
            return;
        }

        if ($this->ratingService->hasRated($executionId, $userId, $role)) {
            $this->session->setFlash('error', 'شما قبلاً برای این اجرا امتیاز داده‌اید.');
            redirect(url('/social-tasks/history'));
            return;
        }

        if ($stars <= 0 || $stars > 5) {
            $this->session->setFlash('error', 'لطفاً امتیاز صحیح را انتخاب کنید.');
            redirect(url('/social-tasks/rate/' . $executionId));
            return;
        }

        $result = $role === 'executor'
            ? $this->ratingService->rateExecutor($executionId, $userId, $stars, $comment)
            : $this->ratingService->rateAdvertiser($executionId, $userId, $stars, $comment);

        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/social-tasks/history'));
    }

    public function ratingHistory(): void
    {
        $userId = (int)user_id();
        $page   = max(1, (int)($this->request->get('page') ?? 1));
        $limit  = 20;

        $received = $this->ratingService->getRatingHistory($userId, 'rated', $limit, ($page - 1) * $limit);
        $given    = $this->ratingService->getRatingHistory($userId, 'rater', $limit, ($page - 1) * $limit);

        view('user.social-tasks.rating-history', [
            'title'        => 'تاریخچه امتیازات',
            'received'     => $received,
            'given'        => $given,
            'page'         => $page,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // ADVERTISER
    // ─────────────────────────────────────────────────────────────

    public function myAds(): void
    {
        $userId = (int)user_id();
        $page   = max(1, (int)($this->request->get('page') ?? 1));
        $limit  = 20;

        $ads = $this->getMyAds($userId, $limit, ($page - 1) * $limit);

        view('user.social-tasks.my-ads', [
            'title' => 'آگهی‌های من',
            'ads'   => $ads,
            'page'  => $page,
        ]);
    }

    public function advertiserDashboard(): void
    {
        $userId = (int)user_id();
        $summary = $this->getAdvertiserSummary($userId);

        view('user.social-tasks.advertiser-dashboard', [
            'title'   => 'داشبورد تبلیغ‌دهنده',
            'summary' => $summary,
        ]);
    }

    public function create(): void
    {
        view('user.social-tasks.create', [
            'title'      => 'ثبت آگهی جدید',
            'platforms'  => $this->platformLabels(),
            'task_types' => $this->taskTypeLabels(),
        ]);
    }

    public function store(): void
    {
        $userId = (int)user_id();

        try {
            $result = $this->service->createAd($userId, $this->request->body());
            $this->session->setFlash(
                $result['success'] ? 'success' : 'error',
                $result['success'] ? 'آگهی با موفقیت ثبت شد.' : ($result['message'] ?? 'خطا در ثبت آگهی')
            );
            redirect($result['success'] ? url('/social-ads') : url('/social-ads/create'));
        } catch (\Exception $e) {
            $this->logger->error('social_task.store.failed', ['err' => $e->getMessage(), 'user' => $userId]);
            $this->session->setFlash('error', 'خطای سیستمی در ثبت آگهی.');
            redirect(url('/social-ads/create'));
        }
    }

    public function show(): void
    {
        $userId = (int)user_id();
        $adId   = (int)$this->request->param('id');

        $stats = $this->service->getAdvertiserAdStats($userId, $adId);
        if (!$stats) {
            redirect(url('/social-ads'));
            return;
        }

        $executions = $this->getAdExecutions($adId, 20, 0);

        view('user.social-tasks.show', [
            'title'      => 'مدیریت آگهی',
            'ad'         => $stats,
            'executions' => $executions,
        ]);
    }

    public function executionDetail(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');

        $exec = $this->getExecutionForAdvertiser($userId, $executionId);
        if (!$exec) {
            redirect(url('/social-ads'));
            return;
        }

        view('user.social-tasks.execution-detail', [
            'title' => 'جزئیات اجرا',
            'exec'  => $exec,
        ]);
    }

    public function approveExecution(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');

        $result = $this->service->advertiserApprove($userId, $executionId);

        if (is_ajax()) {
            $this->response->json($result);
            return;
        }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/social-ads'));
    }

    public function rejectExecution(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');
        $reason      = trim($this->request->post('reason') ?? '');

        $result = $this->service->advertiserReject($userId, $executionId, $reason);

        if (is_ajax()) {
            $this->response->json($result);
            return;
        }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/social-ads'));
    }

    public function pause(): void  { $this->toggleAdStatus('paused'); }
    public function resume(): void { $this->toggleAdStatus('active'); }
    public function cancel(): void { $this->toggleAdStatus('cancelled'); }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    private function toggleAdStatus(string $status): void
    {
        $userId = (int)user_id();
        $adId   = (int)$this->request->param('id');

        // فقط advertiser مالک می‌تواند تغییر دهد
        $affected = $this->db()->query(
            "UPDATE social_ads SET status = ?, updated_at = NOW()
             WHERE id = ? AND advertiser_id = ?",
            [$status, $adId, $userId]
        );

        $result = ['success' => (bool)$affected, 'message' => $affected ? 'وضعیت تغییر کرد' : 'خطا'];

        if (is_ajax()) {
            $this->response->json($result);
            return;
        }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/social-ads'));
    }

    private function getExecutionForUser(int $executionId, int $userId): ?object
    {
        return $this->db()->fetch(
            "SELECT ste.*, sa.title, sa.platform, sa.task_type, sa.reward,
                    sa.target_url, sa.target_username, sa.description, sa.expected_time
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.id = ? AND ste.executor_id = ? LIMIT 1",
            [$executionId, $userId]
        ) ?: null;
    }

    private function getExecutionForAdvertiser(int $advertiserId, int $executionId): ?object
    {
        return $this->db()->fetch(
            "SELECT ste.*, sa.title, sa.platform, sa.task_type,
                    u.full_name AS executor_name
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             JOIN users u ON u.id = ste.executor_id
             WHERE ste.id = ? AND sa.advertiser_id = ? LIMIT 1",
            [$executionId, $advertiserId]
        ) ?: null;
    }

    private function getMyAds(int $userId, int $limit, int $offset): array
    {
        return $this->db()->fetchAll(
            "SELECT sa.*,
                    COUNT(ste.id)                              AS total_executions,
                    SUM(ste.decision = 'approved')             AS approved_count,
                    SUM(ste.decision = 'rejected')             AS rejected_count
             FROM social_ads sa
             LEFT JOIN social_task_executions ste ON ste.ad_id = sa.id
             WHERE sa.advertiser_id = ?
             GROUP BY sa.id
             ORDER BY sa.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        ) ?: [];
    }

    private function getAdExecutions(int $adId, int $limit, int $offset): array
    {
        return $this->db()->fetchAll(
            "SELECT ste.*, u.full_name AS executor_name,
                    COALESCE(ut.trust_score, 50) AS executor_trust
             FROM social_task_executions ste
             JOIN users u ON u.id = ste.executor_id
             LEFT JOIN social_user_trust ut ON ut.user_id = ste.executor_id
             WHERE ste.ad_id = ?
             ORDER BY ste.created_at DESC
             LIMIT ? OFFSET ?",
            [$adId, $limit, $offset]
        ) ?: [];
    }

    private function getAdvertiserSummary(int $userId): array
    {
        $row = $this->db()->fetch(
            "SELECT
                COUNT(DISTINCT sa.id) AS total_ads,
                SUM(sa.max_slots * sa.reward) AS total_budget,
                SUM(CASE WHEN ste.decision IN ('approved','soft_approved') THEN sa.reward ELSE 0 END) AS spent,
                COUNT(ste.id) AS total_executions,
                AVG(ste.task_score) AS avg_score
             FROM social_ads sa
             LEFT JOIN social_task_executions ste ON ste.ad_id = sa.id
             WHERE sa.advertiser_id = ?",
            [$userId]
        );

        return $row ? (array)$row : [];
    }

    private function isMobileRequest(): bool
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        return (bool)preg_match('/mobile|android|iphone|ipad/', $ua);
    }

    private function db(): \Core\Database
    {
        return app()->make(\Core\Database::class);
    }

    private function platformLabels(): array
    {
        return [
            'instagram' => 'اینستاگرام',
            'telegram'  => 'تلگرام',
            'twitter'   => 'توییتر/X',
            'tiktok'    => 'تیک‌تاک',
        ];
    }

    private function taskTypeLabels(): array
    {
        return [
            'follow'       => 'فالو',
            'like'         => 'لایک',
            'comment'      => 'کامنت',
            'share'        => 'اشتراک‌گذاری',
            'retweet'      => 'ریتوییت',
            'join_channel' => 'عضویت در کانال',
            'join_group'   => 'عضویت در گروه',
        ];
    }
}
