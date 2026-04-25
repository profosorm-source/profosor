<?php

namespace App\Services\SocialTask;

use App\Services\WalletService;
use App\Services\NotificationService;
use App\Services\ApiRateLimiter;
use App\Services\FinancialEscrowService;
use App\Services\StateMachineService;
use App\Services\RealTimeService;
use Core\Database;
use Core\Logger;

/**
 * SocialTaskService
 *
 * هماهنگ‌کننده اصلی ماژول SocialTask.
 * تنها نقطه ورود برای:
 *   - گرفتن لیست تسک برای Executor
 *   - شروع execution
 *   - ثبت behavior signals
 *   - submit نهایی
 *   - تصمیم‌گیری و پرداخت
 *   - ایجاد آگهی توسط Advertiser
 */
class SocialTaskService
{
    private Database                $db;
    private SocialTaskScoringService $scoring;
    private TrustScoreService       $trust;
    private SilentAntiFraudService  $antiFraud;
    private WalletService           $wallet;
    private NotificationService     $notification;
    private ApiRateLimiter          $rateLimiter;
    private Logger                  $logger;
    private FinancialEscrowService  $escrow;
    private StateMachineService     $stateMachine;
    private RealTimeService         $realTime;

    // تسک‌های یوتیوب جدا هستند — از این سرویس حذف می‌شوند
    private const EXCLUDED_PLATFORMS_FROM_SOCIAL = ['youtube'];

    // platform → نوع تسک‌های مجاز
    private const PLATFORM_TASK_TYPES = [
        'instagram' => ['follow', 'like', 'comment', 'share'],
        'telegram'  => ['join_channel', 'join_group'],
        'twitter'   => ['follow', 'like', 'retweet', 'comment'],
        'tiktok'    => ['follow', 'like', 'comment', 'share'],
    ];

    // زمان انتظار (ثانیه) برای rate limit per task_type
    private const TASK_EXPECTED_TIME = [
        'follow'       => 45,
        'like'         => 20,
        'comment'      => 90,
        'share'        => 30,
        'retweet'      => 25,
        'join_channel' => 30,
        'join_group'   => 30,
    ];

    public function __construct(
        Database                 $db,
        SocialTaskScoringService $scoring,
        TrustScoreService        $trust,
        SilentAntiFraudService   $antiFraud,
        WalletService            $wallet,
        NotificationService      $notification,
        ApiRateLimiter           $rateLimiter,
        Logger                   $logger,
        FinancialEscrowService   $escrow,
        StateMachineService      $stateMachine,
        RealTimeService          $realTime
    ) {
        $this->db           = $db;
        $this->scoring      = $scoring;
        $this->trust        = $trust;
        $this->antiFraud    = $antiFraud;
        $this->wallet       = $wallet;
        $this->notification = $notification;
        $this->rateLimiter  = $rateLimiter;
        $this->logger       = $logger;
        $this->escrow       = $escrow;
        $this->stateMachine = $stateMachine;
        $this->realTime     = $realTime;
    }

    // ─────────────────────────────────────────────────────────────
    // Executor — گرفتن تسک‌ها
    // ─────────────────────────────────────────────────────────────

    /**
     * لیست تسک‌های فعال برای کاربر با اعمال فیلتر نامحسوس
     *
     * @param array $filters [platform, task_type, min_reward, max_reward, sort, search]
     */
    public function getTasksForExecutor(int $userId, array $filters = [], int $limit = 20): array
    {
        // سطح محدودیت نامحسوس
        $restriction = $this->antiFraud->getRestrictionLevel($userId);

        // حداکثر تعداد با اعمال restriction
        $effectiveLimit = $this->antiFraud->filterTaskCount($userId, $limit);

        $where  = ["sa.status = 'active'",
                   "sa.remaining_slots > 0",
                   "sa.platform NOT IN ('" . implode("','", self::EXCLUDED_PLATFORMS_FROM_SOCIAL) . "')",
                   // تسک‌هایی که کاربر قبلاً انجام داده یا در صف دارد حذف شوند
                   "NOT EXISTS (
                       SELECT 1 FROM social_task_executions ste
                       WHERE ste.ad_id = sa.id
                         AND ste.executor_id = ?
                         AND ste.status NOT IN ('expired','cancelled')
                   )"];
        $params = [$userId];

        // فیلتر پلتفرم
        if (!empty($filters['platform'])) {
            $where[]  = 'sa.platform = ?';
            $params[] = $filters['platform'];
        }

        // فیلتر نوع تسک
        if (!empty($filters['task_type'])) {
            $where[]  = 'sa.task_type = ?';
            $params[] = $filters['task_type'];
        }

        // فیلتر قیمت
        if (!empty($filters['min_reward'])) {
            $where[]  = 'sa.reward >= ?';
            $params[] = (float)$filters['min_reward'];
        }
        if (!empty($filters['max_reward'])) {
            $where[]  = 'sa.reward <= ?';
            $params[] = (float)$filters['max_reward'];
        }

        // فیلتر Web/Mobile — Cron شبانه median را می‌گذارد
        $medianReward = $this->getMedianReward();
        if (!empty($filters['is_mobile']) && $filters['is_mobile']) {
            // موبایل: همه تسک‌ها
        } else {
            // وب: فقط تسک‌های reward ≤ median
            $where[]  = 'sa.reward <= ?';
            $params[] = $medianReward;
        }

        // جستجو از GlobalSearchService منطق استفاده می‌کنیم (search خودمان)
        if (!empty($filters['search'])) {
            $like     = '%' . $this->sanitizeSearch($filters['search']) . '%';
            $where[]  = '(sa.title LIKE ? OR sa.description LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        // مرتب‌سازی
        $orderBy = match ($filters['sort'] ?? 'random') {
            'price_desc' => 'sa.reward DESC',
            'price_asc'  => 'sa.reward ASC',
            'newest'     => 'sa.created_at DESC',
            default      => 'RAND()', // random
        };

        $whereStr = implode(' AND ', $where);
        $params[] = $effectiveLimit;

        $tasks = $this->db->fetchAll(
            "SELECT sa.*,
                    u.full_name  AS advertiser_name,
                    COALESCE(ut.trust_score, 50) AS advertiser_trust
             FROM social_ads sa
             JOIN users u ON u.id = sa.advertiser_id
             LEFT JOIN social_user_trust ut ON ut.user_id = sa.advertiser_id
             WHERE {$whereStr}
             ORDER BY {$orderBy}
             LIMIT ?",
            $params
        );

        // پاداش واقعی با اعمال restriction نامحسوس
        foreach ($tasks as &$task) {
            $task->display_reward  = $this->antiFraud->adjustedReward($userId, (float)$task->reward);
            $task->trust_display   = $this->trust->get($userId);
        }
        unset($task);

        return [
            'tasks'            => $tasks,
            'restriction_level'=> $restriction['level'], // فقط برای debug داخلی — به view ارسال نمی‌شود
            'trust_score'      => $this->trust->get($userId),
        ];
    }
	
	
	public function adminRejectAd(int $adminId, int $adId, string $reason): array
{
    try {
        $ad = $this->db->fetch(
            "SELECT * FROM social_ads WHERE id = ? LIMIT 1",
            [$adId]
        );

        if (!$ad) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد'];
        }

        if (in_array($ad->status, ['completed', 'cancelled', 'rejected'], true)) {
            return ['success' => false, 'message' => 'این تبلیغ قابل رد شدن نیست'];
        }

        $this->db->query(
            "UPDATE social_ads
             SET status = 'rejected',
                 reject_reason = ?,
                 reviewed_by = ?,
                 reviewed_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?",
            [$reason, $adminId, $adId]
        );

        return ['success' => true, 'message' => 'تبلیغ با موفقیت رد شد'];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'خطا در رد تبلیغ: ' . $e->getMessage()];
    }
}


public function adminCancelAd(int $adminId, int $adId): array
{
    try {
        $this->db->beginTransaction();

        $ad = $this->db->fetch(
            "SELECT * FROM social_ads WHERE id = ? FOR UPDATE",
            [$adId]
        );

        if (!$ad) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'تبلیغ یافت نشد'];
        }

        if (in_array($ad->status, ['completed', 'cancelled'], true)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این تبلیغ قابل لغو نیست'];
        }

        $refund = (float)($ad->remaining_budget ?? 0);
        if ($refund > 0) {
            $walletResult = $this->wallet->deposit(
                (int)$ad->user_id,
                $refund,
                'irt',
                [
                    'type' => 'social_ad_refund',
                    'description' => "Refund for cancelled social ad #{$adId}",
                    'gateway' => 'social_ad_refund',
                    'gateway_transaction_id' => 'refund_' . $adId . '_' . time(),
                    'ref_id' => $adId,
                    'ref_type' => 'social_ad',
                ]
            );

            if (empty($walletResult['success'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $walletResult['message'] ?? 'خطا در بازگشت وجه'];
            }
        }

        $this->db->query(
            "UPDATE social_ads
             SET status='cancelled', updated_at=NOW()
             WHERE id=?",
            [$adId]
        );

        $this->db->commit();

        return [
            'success' => true,
            'message' => 'تبلیغ لغو شد',
            'refund' => $refund
        ];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        return ['success' => false, 'message' => 'خطا در لغو تبلیغ: ' . $e->getMessage()];
    }
}


public function adminFlagExecution(int $adminId, int $executionId, string $note = ''): array
{
    try {
        $exec = $this->db->fetch(
            "SELECT id, status FROM social_task_executions WHERE id = ? LIMIT 1",
            [$executionId]
        );

        if (!$exec) {
            return ['success' => false, 'message' => 'اجرا یافت نشد'];
        }

        if (in_array($exec->status, ['expired', 'cancelled'], true)) {
            return ['success' => false, 'message' => 'این اجرا قابل علامت‌گذاری نیست'];
        }

        $this->db->query(
            "UPDATE social_task_executions
             SET flag_review = 1, flag_note = ?, updated_at = NOW()
             WHERE id = ?",
            [$note, $executionId]
        );

        return ['success' => true, 'message' => 'اجرا برای بررسی علامت‌گذاری شد'];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'خطا در علامت‌گذاری اجرا'];
    }
}

public function adminOverrideExecution(int $adminId, int $executionId, string $decision, string $reason): array
{
    try {
        if (!in_array($decision, ['approved', 'soft_approved', 'rejected'], true)) {
            return ['success' => false, 'message' => 'تصمیم معتبر نیست'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'دلیل override الزامی است'];
        }

        $this->db->beginTransaction();

        $exec = $this->db->fetch(
            "SELECT id, executor_id, status, decision
             FROM social_task_executions
             WHERE id = ?
             FOR UPDATE",
            [$executionId]
        );

        if (!$exec) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'اجرا یافت نشد'];
        }

        $oldDecision = $exec->decision ?? null;

        $this->db->query(
            "UPDATE social_task_executions
             SET decision = ?,
                 status = ?,
                 override_reason = ?,
                 reviewed_by = ?,
                 reviewed_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?",
            [$decision, $decision, $reason, $adminId, $executionId]
        );

        $this->db->commit();

        return [
            'success' => true,
            'message' => 'تصمیم با موفقیت override شد',
            'old_decision' => $oldDecision,
            'new_decision' => $decision,
        ];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        return ['success' => false, 'message' => 'خطا در override تصمیم'];
    }
}


public function adminAdjustTrust(int $adminId, int $userId, float $delta, string $reason): array
{
    try {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'دلیل الزامی است'];
        }

        if ($delta == 0.0) {
            return ['success' => false, 'message' => 'مقدار تغییر نمی‌تواند صفر باشد'];
        }

        $this->db->beginTransaction();

        $row = $this->db->fetch(
            "SELECT user_id, trust_score
             FROM social_user_trust
             WHERE user_id = ?
             FOR UPDATE",
            [$userId]
        );

        if (!$row) {
            $this->db->query(
                "INSERT INTO social_user_trust (user_id, trust_score, created_at, updated_at)
                 VALUES (?, 50, NOW(), NOW())",
                [$userId]
            );
            $oldTrust = 50.0;
        } else {
            $oldTrust = (float)$row->trust_score;
        }

        $newTrust = max(0.0, min(100.0, $oldTrust + $delta));

        $this->db->query(
            "UPDATE social_user_trust
             SET trust_score = ?, updated_at = NOW()
             WHERE user_id = ?",
            [$newTrust, $userId]
        );

        $this->db->query(
            "INSERT INTO social_trust_adjustments
             (user_id, admin_id, delta, old_trust, new_trust, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$userId, $adminId, $delta, $oldTrust, $newTrust, $reason]
        );

        $this->db->commit();

        return [
            'success' => true,
            'message' => 'امتیاز اعتماد با موفقیت تغییر کرد',
            'old_trust' => $oldTrust,
            'new_trust' => $newTrust,
        ];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        return ['success' => false, 'message' => 'خطا در تغییر امتیاز اعتماد'];
    }
}
    /**
     * شروع یک execution جدید
     */
    public function startExecution(int $userId, int $adId, array $context = []): array
{
    try {
        $this->db->beginTransaction();

        $ad = $this->db->fetch(
            "SELECT * FROM social_ads
             WHERE id = ? AND status = 'active' AND remaining_slots > 0
             FOR UPDATE",
            [$adId]
        );

        if (!$ad) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'تسک موجود نیست یا ظرفیت تکمیل شده'];
        }

        $existing = $this->db->fetch(
            "SELECT id FROM social_task_executions
             WHERE ad_id = ? AND executor_id = ? AND status NOT IN ('expired','cancelled')
             LIMIT 1",
            [$adId, $userId]
        );

        if ($existing) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'قبلاً این تسک را شروع کرده‌اید'];
        }

        if (!$this->rateLimiter->check('task_submit', $userId, 50, 60)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'تعداد تسک در این ساعت به حد مجاز رسیده است'];
        }

        $expectedTime = self::TASK_EXPECTED_TIME[$ad->task_type] ?? 60;

        $dec = $this->db->query(
            "UPDATE social_ads
             SET remaining_slots = remaining_slots - 1
             WHERE id = ? AND remaining_slots > 0",
            [$adId]
        );
        $affected = $dec instanceof \PDOStatement ? $dec->rowCount() : 0;
        if ($affected < 1) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'ظرفیت تکمیل شده'];
        }

        $execId = $this->db->insert(
            "INSERT INTO social_task_executions
             (ad_id, executor_id, status, ip_address, user_agent, started_at, expected_time, created_at)
             VALUES (?, ?, 'pending', ?, ?, NOW(), ?, NOW())",
            [
                $adId,
                $userId,
                $context['ip'] ?? '',
                $context['user_agent'] ?? '',
                $expectedTime,
            ]
        );

        $this->db->commit();

        return [
            'success' => true,
            'execution_id' => $execId,
            'expected_time' => $expectedTime,
            'target_url' => $ad->target_url,
            'task_type' => $ad->task_type,
        ];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        return ['success' => false, 'message' => 'خطا در شروع تسک'];
    }
}
    // ─────────────────────────────────────────────────────────────
    // Executor — ثبت Behavior Signals (از موبایل/وب)
    // ─────────────────────────────────────────────────────────────

    /**
     * ذخیره سیگنال‌های رفتاری در طول انجام تسک.
     * چند بار قابل فراخوانی است (incremental update).
     */
    public function recordBehaviorSignals(int $executionId, int $userId, array $signals): bool
    {
        $exec = $this->db->fetch(
            "SELECT id FROM social_task_executions WHERE id = ? AND executor_id = ? LIMIT 1",
            [$executionId, $userId]
        );
        if (!$exec) {
            return false;
        }

        // merge با داده قبلی
        $existing = $this->db->fetch(
            "SELECT behavior_data FROM social_task_executions WHERE id = ? LIMIT 1",
            [$executionId]
        );
        $prevData = [];
        if ($existing && $existing->behavior_data) {
            $prevData = json_decode($existing->behavior_data, true) ?? [];
        }

        $merged = $this->mergeBehaviorSignals($prevData, $signals);

        $this->db->query(
            "UPDATE social_task_executions SET behavior_data = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($merged, JSON_UNESCAPED_UNICODE), $executionId]
        );

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // Executor — Submit نهایی
    // ─────────────────────────────────────────────────────────────

    /**
     * ثبت نهایی execution و تصمیم‌گیری.
     * @param array $submitData [active_time, interactions, behavior_signals, ip, fingerprint, session_id, ...]
     */
    public function submitExecution(int $userId, int $executionId, array $payload = []): array
{
    try {
        $this->db->beginTransaction();

        // اجرای کار را قفل می‌کنیم تا دوباره‌کاری/همزمانی خراب نکند
        $exec = $this->db->fetch(
            "SELECT e.*, a.reward, a.task_type, a.id AS ad_id
             FROM social_task_executions e
             INNER JOIN social_ads a ON a.id = e.ad_id
             WHERE e.id = ? AND e.executor_id = ?
             FOR UPDATE",
            [$executionId, $userId]
        );

        if (!$exec) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'رکورد اجرا یافت نشد'];
        }

        // فقط pending قابل ارسال نهایی است
        if (($exec->status ?? null) !== 'pending') {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'وضعیت اجرا برای ارسال معتبر نیست'];
        }

        $proofUrl = trim((string)($payload['proof_url'] ?? ''));
        $proofText = trim((string)($payload['proof_text'] ?? ''));

        if ($proofUrl === '' && $proofText === '') {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'مدرک انجام تسک الزامی است'];
        }

        // امتیاز ضدتقلب
        $score = $this->antiFraud->scoreExecution($exec, $payload);
        $decision = $this->antiFraud->decisionFromScore($score);

        // تصمیم پیش‌فرض
        $finalStatus = ($decision['decision'] ?? '') === 'reject' ? 'rejected' : 'approved';
        $rewardPaid = 0;
        $rewardAmount = 0.0;

        if (!empty($decision['pay_reward'])) {
            $rewardAmount = (float)$this->antiFraud->adjustedReward($userId, (float)$exec->reward);

            if ($rewardAmount > 0) {
                $pay = $this->wallet->deposit(
                    $userId,
                    $rewardAmount,
                    'irt',
                    [
                        'source' => 'social_task_reward',
                        'execution_id' => $executionId,
                        'ad_id' => (int)$exec->ad_id,
                        'task_type' => $exec->task_type ?? null,
                        'decision' => $decision['decision'] ?? null,
                        'risk_score' => $score['score'] ?? null,
                    ]
                );

                if (!is_array($pay) || empty($pay['success'])) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => $pay['message'] ?? 'خطا در پرداخت پاداش'];
                }

                $rewardPaid = 1;
            }
        }

        // ثبت نتیجه نهایی اجرا
        $updated = $this->db->query(
            "UPDATE social_task_executions
             SET status = ?,
                 proof_url = ?,
                 proof_text = ?,
                 anti_fraud_score = ?,
                 anti_fraud_decision = ?,
                 reward_amount = ?,
                 reward_paid = ?,
                 submitted_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?",
            [
                $finalStatus,
                $proofUrl !== '' ? $proofUrl : null,
                $proofText !== '' ? $proofText : null,
                (float)($score['score'] ?? 0),
                (string)($decision['decision'] ?? 'unknown'),
                $rewardAmount,
                $rewardPaid,
                $executionId,
            ]
        );

        if (!$updated) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در ثبت نتیجه اجرا'];
        }

        // ✅ Notify advertiser about execution submission
        $this->realTime->notifyExecutionSubmitted(
            $executionId,
            (int)$exec->advertiser_id ?? 0,
            $exec->task_type ?? 'Unknown'
        );

        $this->db->commit();

        return [
            'success' => true,
            'message' => $finalStatus === 'approved' ? 'تسک با موفقیت تایید شد' : 'تسک رد شد',
            'status' => $finalStatus,
            'reward_paid' => (bool)$rewardPaid,
            'reward_amount' => $rewardAmount,
            'risk_score' => (float)($score['score'] ?? 0),
            'decision' => (string)($decision['decision'] ?? 'unknown'),
        ];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        return [
            'success' => false,
            'message' => 'خطا در ثبت نهایی تسک',
        ];
    }
}


public function adminChangeAdStatus(int $adminId, int $adId, string $status): array
{
    try {
        $ad = $this->db->fetch("SELECT * FROM social_ads WHERE id = ? LIMIT 1", [$adId]);
        if (!$ad) {
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        $this->db->query(
            "UPDATE social_ads SET status = ?, updated_at = NOW() WHERE id = ?",
            [$status, $adId]
        );

        return ['success' => true, 'message' => 'وضعیت آگهی تغییر کرد'];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'خطا در تغییر وضعیت آگهی'];
    }
}
    // ─────────────────────────────────────────────────────────────
    // Advertiser — تأیید/رد دستی execution
    // ─────────────────────────────────────────────────────────────

    public function advertiserApprove(int $advertiserId, int $executionId): array
    {
        $exec = $this->getExecutionForAdvertiser($advertiserId, $executionId);
        if (!$exec) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->db->query(
            "UPDATE social_task_executions SET status = 'approved', updated_at = NOW() WHERE id = ?",
            [$executionId]
        );

        return ['success' => true, 'message' => 'اجرا تأیید شد'];
    }

    public function advertiserReject(int $advertiserId, int $executionId, string $reason): array
    {
        if (empty(trim($reason))) {
            return ['success' => false, 'message' => 'دلیل رد الزامی است'];
        }

        $exec = $this->getExecutionForAdvertiser($advertiserId, $executionId);
        if (!$exec) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->db->query(
            "UPDATE social_task_executions SET status = 'rejected', reject_reason = ?, updated_at = NOW() WHERE id = ?",
            [$reason, $executionId]
        );

        // Trust penalty برای executor
        $this->trust->penalizeRejection((int)$exec->executor_id, $executionId);

        return ['success' => true, 'message' => 'اجرا رد شد'];
    }

    // ─────────────────────────────────────────────────────────────
    // آمار و گزارش
    // ─────────────────────────────────────────────────────────────

    public function getExecutorStats(int $userId): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(decision = 'approved') AS approved,
                SUM(decision = 'soft_approved') AS soft_approved,
                SUM(decision = 'rejected') AS rejected,
                AVG(task_score) AS avg_score,
                SUM(CASE WHEN decision IN ('approved','soft_approved') THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*),0) AS success_rate
             FROM social_task_executions
             WHERE executor_id = ?",
            [$userId]
        ) ?: (object)['total' => 0, 'approved' => 0, 'soft_approved' => 0, 'rejected' => 0, 'avg_score' => 0, 'success_rate' => 0];
    }

    public function getAdvertiserAdStats(int $advertiserId, int $adId): ?object
    {
        return $this->db->fetch(
            "SELECT
                sa.*,
                COUNT(ste.id) AS total_executions,
                SUM(ste.decision = 'approved') AS approved,
                SUM(ste.decision = 'soft_approved') AS soft_approved,
                SUM(ste.decision = 'rejected') AS rejected,
                AVG(ste.task_score) AS avg_score,
                AVG(ste.active_time) AS avg_time
             FROM social_ads sa
             LEFT JOIN social_task_executions ste ON ste.ad_id = sa.id
             WHERE sa.id = ? AND sa.advertiser_id = ?
             GROUP BY sa.id
             LIMIT 1",
            [$adId, $advertiserId]
        );
    }

    public function getExecutorHistory(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT ste.*, sa.title, sa.platform, sa.task_type, sa.reward
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.executor_id = ?
             ORDER BY ste.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        ) ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // Cron — Web/Mobile Split (فراخوانی شبانه از Scheduler)
    // ─────────────────────────────────────────────────────────────

    /**
     * median reward را محاسبه و ذخیره می‌کند.
     * تسک‌های reward > median فقط در موبایل نمایش داده می‌شوند.
     */
    public function updateMedianReward(): float
    {
        $result = $this->db->fetch(
            "SELECT AVG(reward) AS median_reward
             FROM (
                 SELECT reward
                 FROM social_ads
                 WHERE status = 'active'
                 ORDER BY reward
                 LIMIT 2 OFFSET (SELECT FLOOR(COUNT(*)/2) FROM social_ads WHERE status = 'active')
             ) t"
        );
        $median = (float)($result ? $result->median_reward : 0);

        // ذخیره در cache/settings
        $this->db->query(
            "INSERT INTO social_task_settings (key_name, value, updated_at)
             VALUES ('median_reward', ?, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()",
            [(string)$median]
        );

        return $median;
    }

public function createAd(int $advertiserId, array $data): array
{
    $platform = $data['platform'] ?? '';
    $taskType = $data['task_type'] ?? '';
    $reward = (float)($data['reward'] ?? 0);
    $maxSlots = (int)($data['max_slots'] ?? 0);
    $totalCost = $reward * $maxSlots;

    $allowed = array_keys(self::PLATFORM_TASK_TYPES);
    if (!in_array($platform, $allowed, true)) {
        return ['success' => false, 'message' => 'پلتفرم انتخابی در این ماژول مجاز نیست'];
    }

    $allowedTypes = self::PLATFORM_TASK_TYPES[$platform] ?? [];
    if (!in_array($taskType, $allowedTypes, true)) {
        return ['success' => false, 'message' => 'نوع تسک برای این پلتفرم مجاز نیست'];
    }

    if ($reward <= 0 || $maxSlots <= 0) {
        return ['success' => false, 'message' => 'پاداش و تعداد کاربر باید بیشتر از صفر باشد'];
    }

    $commentTemplates = isset($data['comment_templates']) && is_array($data['comment_templates'])
        ? json_encode($data['comment_templates'], JSON_UNESCAPED_UNICODE)
        : null;

    try {
        $this->db->beginTransaction();

        $withdraw = $this->wallet->withdraw($advertiserId, $totalCost, 'irt', [
            'source' => 'social_ad_create',
            'note' => "ایجاد آگهی {$platform}/{$taskType}",
            'ref_type' => 'social_ad',
        ]);

        if (!is_array($withdraw) || empty($withdraw['success'])) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $withdraw['message'] ?? 'موجودی کافی نیست'];
        }

        $adId = $this->db->insert(
            "INSERT INTO social_ads
            (advertiser_id, platform, task_type, title, description,
             target_url, target_username, reward, max_slots,
             remaining_slots, allow_copy_paste, comment_templates,
             status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_review', NOW())",
            [
                $advertiserId,
                $platform,
                $taskType,
                trim($data['title'] ?? ''),
                trim($data['description'] ?? ''),
                trim($data['target_url'] ?? ''),
                trim($data['target_username'] ?? ''),
                $reward,
                $maxSlots,
                $maxSlots,
                isset($data['allow_copy_paste']) ? 1 : 0,
                $commentTemplates,
            ]
        );

        if (!$adId) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در ثبت آگهی'];
        }

        $this->db->commit();
        return ['success' => true, 'ad_id' => $adId];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        return ['success' => false, 'message' => 'خطا در ایجاد آگهی'];
    }
}

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    private function getMedianReward(): float
    {
        $row = $this->db->fetch(
            "SELECT value FROM social_task_settings WHERE key_name = 'median_reward' LIMIT 1"
        );
        return $row ? (float)$row->value : 100.0;
    }

    private function getExecutionForAdvertiser(int $advertiserId, int $executionId): ?object
    {
        return $this->db->fetch(
            "SELECT ste.*
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.id = ? AND sa.advertiser_id = ?
             LIMIT 1",
            [$executionId, $advertiserId]
        ) ?: null;
    }

    private function mergeBehaviorSignals(array $prev, array $new): array
    {
        // فیلدهای عددی additive (مثل tap_count)
        $additive = ['tap_count', 'swipe_count', 'scroll_count', 'touch_pauses',
                     'scroll_pauses', 'reconnect_count', 'hesitation_count',
                     'natural_delay_count', 'app_blur_count'];

        foreach ($additive as $key) {
            if (isset($new[$key])) {
                $prev[$key] = ((int)($prev[$key] ?? 0)) + (int)$new[$key];
            }
        }

        // فیلدهای override (مثل variance که آخرین مقدار معتبر است)
        $override = ['touch_timing_variance', 'scroll_speed_variance',
                     'session_duration', 'active_time',
                     'max_blur_duration', 'avg_action_delay_ms'];
        foreach ($override as $key) {
            if (isset($new[$key])) {
                $prev[$key] = $new[$key];
            }
        }

        return $prev;
    }

    private function sendDecisionNotification(int $userId, array $decision, string $taskType): void
    {
        $titleMap = [
            'approved'      => 'تسک تأیید شد',
            'soft_approved' => 'تسک در انتظار بررسی',
            'rejected'      => 'تسک رد شد',
        ];

        $this->notification->send(
            $userId,
            'task_result',
            $titleMap[$decision['decision']] ?? 'نتیجه تسک',
            $this->decisionMessage($decision['decision']),
            ['decision' => $decision['decision'], 'task_type' => $taskType]
        );
    }

    private function decisionMessage(string $decision): string
    {
        return match ($decision) {
            'approved'      => 'تسک با موفقیت تأیید شد و پاداش واریز گردید.',
            'soft_approved' => 'تسک دریافت شد. پاداش واریز شده ولی در انتظار تأیید نهایی است.',
            'rejected'      => 'تسک تأیید نشد. لطفاً دستورالعمل‌ها را با دقت بیشتری دنبال کنید.',
            default         => 'نتیجه تسک پردازش شد.',
        };
    }

    private function sanitizeSearch(string $q): string
    {
        return preg_replace('/[%_\\\\]/', '\\\\$0', mb_substr(trim($q), 0, 100));
    }
}
