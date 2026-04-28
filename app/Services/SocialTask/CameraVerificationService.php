<?php

namespace App\Services\SocialTask;

use Core\Database;

/**
 * CameraVerificationService
 *
 * مدیریت فرآیند Camera Verification:
 *
 * ۱. تصمیم می‌گیرد آیا برای یک execution نیاز به camera هست
 * ۲. درخواست camera را در DB ثبت می‌کند
 * ۳. سیگنال ML محلی موبایل را دریافت می‌کند (عکس هرگز ارسال نمی‌شود)
 * ۴. نتیجه را به عنوان یک signal به SocialTaskScoringService ارسال می‌کند
 *
 * مهم: عکس هرگز upload یا ذخیره نمی‌شود.
 * موبایل نتیجه ML را به صورت امتیاز + signals ارسال می‌کند.
 */
class CameraVerificationService
{
    // وضعیت‌های یک camera request
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_EXPIRED   = 'expired';

    // حداکثر مدت انتظار برای پاسخ کاربر (ثانیه)
    private const EXPIRY_SECONDS = 120;

    private Database                $db;
    private BehaviorAnalysisService $behavior;

    public function __construct(Database $db, BehaviorAnalysisService $behavior)
    {
        $this->db       = $db;
        $this->behavior = $behavior;
    }

    // ─────────────────────────────────────────────────────────────
    // تصمیم برای درخواست Camera
    // ─────────────────────────────────────────────────────────────

    /**
     * بررسی اینکه آیا این execution نیاز به camera verification دارد.
     * فقط وقتی مشکوک است و امتیاز کافی نیست.
     *
     * @param int   $executionId
     * @param float $currentScore امتیاز فعلی قبل از تصمیم نهایی
     * @param array $behaviorSignals
     * @return bool
     */
    public function isRequired(int $executionId, float $currentScore, array $behaviorSignals): bool
    {
        // اگر قبلاً camera انجام شده → نیاز نیست
        $existing = $this->db->fetch(
            "SELECT id, status FROM social_camera_requests
             WHERE execution_id = ? AND status IN ('completed','pending')
             LIMIT 1",
            [$executionId]
        );
        if ($existing) return false;

        // تحلیل رفتار
        $patterns = $this->behavior->detectPatterns($behaviorSignals);

        return $this->behavior->needsCameraVerification($currentScore, $patterns);
    }

    // ─────────────────────────────────────────────────────────────
    // ثبت درخواست Camera
    // ─────────────────────────────────────────────────────────────

    /**
     * ثبت یک camera request جدید در DB
     * موبایل این request را می‌خواند و UI camera را نشان می‌دهد
     */
    public function createRequest(int $executionId, int $userId): int
    {
        return (int)$this->db->insert(
            "INSERT INTO social_camera_requests
               (execution_id, user_id, status, expires_at, created_at)
             VALUES (?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())",
            [$executionId, $userId, self::EXPIRY_SECONDS]
        );
    }

    /**
     * بررسی اینکه آیا camera request فعال وجود دارد
     */
    public function getPendingRequest(int $executionId): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM social_camera_requests
             WHERE execution_id = ?
               AND status = 'pending'
               AND expires_at > NOW()
             LIMIT 1",
            [$executionId]
        ) ?: null;
    }

    // ─────────────────────────────────────────────────────────────
    // پردازش نتیجه Camera
    // ─────────────────────────────────────────────────────────────

    /**
     * دریافت نتیجه ML محلی از موبایل و تبدیل به signal.
     *
     * @param int   $executionId
     * @param int   $userId
     * @param int   $cameraScore      امتیاز ML محلی (0–100)
     * @param array $verifiedSignals  ['follow_button_visible', 'username_match', ...]
     * @return array ['success'=>bool, 'signal'=>array, 'score_contribution'=>int]
     */
    public function processResult(
        int   $executionId,
        int   $userId,
        int   $cameraScore,
        array $verifiedSignals = []
    ): array {
        $request = $this->db->fetch(
            "SELECT * FROM social_camera_requests
             WHERE execution_id = ? AND user_id = ? AND status = 'pending'
             LIMIT 1",
            [$executionId, $userId]
        );

        if (!$request) {
            return ['success' => false, 'message' => 'درخواست camera یافت نشد یا منقضی شده'];
        }

        // ذخیره نتیجه — عکس هرگز ذخیره نمی‌شود
        $this->db->query(
            "UPDATE social_camera_requests
             SET status         = 'completed',
                 camera_score   = ?,
                 verified_signals = ?,
                 completed_at   = NOW()
             WHERE id = ?",
            [$cameraScore, json_encode($verifiedSignals, JSON_UNESCAPED_UNICODE), $request->id]
        );

        // محاسبه contribution به task score
        $contribution = $this->scoreContribution($cameraScore, $verifiedSignals);

        // ذخیره به عنوان behavior signal در execution
        $this->db->query(
            "UPDATE social_task_executions
             SET behavior_data = JSON_SET(
                   COALESCE(behavior_data, '{}'),
                   '$.camera_score', ?,
                   '$.camera_signals', ?,
                   '$.camera_verified', 1
             )
             WHERE id = ?",
            [$cameraScore, json_encode($verifiedSignals), $executionId]
        );

        return [
            'success'            => true,
            'camera_score'       => $cameraScore,
            'score_contribution' => $contribution,
            'verified_signals'   => $verifiedSignals,
            'signal'             => [
                'camera_score'   => $cameraScore,
                'camera_signals' => $verifiedSignals,
                'camera_verified'=> true,
            ],
        ];
    }

    /**
     * انقضای request بدون پاسخ
     */
    public function expireRequest(int $executionId): void
    {
        $this->db->query(
            "UPDATE social_camera_requests
             SET status = 'expired'
             WHERE execution_id = ? AND status = 'pending' AND expires_at < NOW()",
            [$executionId]
        );
    }

    // ─────────────────────────────────────────────────────────────
    // محاسبه تأثیر Camera Score بر Task Score
    // ─────────────────────────────────────────────────────────────

    /**
     * تبدیل camera score به contribution برای task score
     *
     * camera_score ≥ 80 → +15  (تأیید قوی)
     * camera_score ≥ 60 → +8   (تأیید معقول)
     * camera_score ≥ 40 → +2   (ضعیف ولی قابل قبول)
     * camera_score  < 40 → -10  (احتمال تقلب)
     */
    public function scoreContribution(int $cameraScore, array $verifiedSignals = []): int
    {
        $base = 0;
        if ($cameraScore >= 80) $base = 15;
        elseif ($cameraScore >= 60) $base = 8;
        elseif ($cameraScore >= 40) $base = 2;
        else $base = -10;

        // bonus برای سیگنال‌های تأیید شده
        $bonus = 0;
        $highValueSignals = ['follow_button_visible', 'username_match', 'subscribe_confirmed', 'like_button_active'];
        foreach ($highValueSignals as $sig) {
            if (in_array($sig, $verifiedSignals, true)) $bonus += 3;
        }

        return $base + min($bonus, 10); // حداکثر +10 bonus
    }

    // ─────────────────────────────────────────────────────────────
    // آمار برای admin
    // ─────────────────────────────────────────────────────────────

    public function getStats(): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'completed') AS completed,
                SUM(status = 'expired')   AS expired,
                SUM(status = 'pending')   AS pending,
                AVG(CASE WHEN status = 'completed' THEN camera_score END) AS avg_score,
                SUM(CASE WHEN status = 'completed' AND camera_score >= 60 THEN 1 ELSE 0 END) AS passed
             FROM social_camera_requests"
        ) ?: (object)[];
    }
}
