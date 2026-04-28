<?php

namespace App\Services\SocialTask;

use Core\Database;

/**
 * RatingService
 *
 * سیستم امتیازدهی دوطرفه:
 *   - Executor به Advertiser امتیاز می‌دهد (بعد از تأیید تسک)
 *   - Advertiser به Executor امتیاز می‌دهد
 *
 * امتیاز 1–5 ستاره + متن اختیاری
 * تأثیر روی Trust Score:
 *   - امتیاز ≥4 برای executor → trust +1
 *   - امتیاز ≤2 برای executor → trust -1
 */
class RatingService
{
    private Database          $db;
    private TrustScoreService $trust;

    // بازه مجاز برای ثبت امتیاز پس از تأیید (ساعت)
    private const RATING_WINDOW_HOURS = 72;

    public function __construct(Database $db, TrustScoreService $trust)
    {
        $this->db    = $db;
        $this->trust = $trust;
    }

    // ─────────────────────────────────────────────────────────────
    // Executor به Advertiser امتیاز می‌دهد
    // ─────────────────────────────────────────────────────────────

    /**
     * @param int    $executionId
     * @param int    $executorId   کاربری که امتیاز می‌دهد
     * @param int    $stars        1–5
     * @param string $comment      اختیاری
     */
    public function rateAdvertiser(int $executionId, int $executorId, int $stars, string $comment = ''): array
    {
        $stars = max(1, min(5, $stars));

        $exec = $this->db->fetch(
            "SELECT ste.*, sa.advertiser_id
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.id = ? AND ste.executor_id = ?
               AND ste.decision IN ('approved','soft_approved')
             LIMIT 1",
            [$executionId, $executorId]
        );

        if (!$exec) {
            return ['success' => false, 'message' => 'اجرا یافت نشد یا تأیید نشده'];
        }

        if ($this->hasRated($executionId, $executorId, 'executor')) {
            return ['success' => false, 'message' => 'قبلاً امتیاز داده‌اید'];
        }

        if (!$this->isWithinRatingWindow($exec->completed_at ?? $exec->created_at)) {
            return ['success' => false, 'message' => 'مهلت امتیازدهی گذشته است'];
        }

        $this->db->insert(
            "INSERT INTO social_ratings
               (execution_id, rater_id, rated_id, rater_type, stars, comment, status, created_at)
             VALUES (?, ?, ?, 'executor', ?, ?, 'approved', NOW())",
            [$executionId, $executorId, $exec->advertiser_id, $stars, trim($comment)]
        );

        $this->updateAdvertiserRating((int)$exec->advertiser_id);

        return ['success' => true, 'message' => 'امتیاز ثبت شد'];
    }

    // ─────────────────────────────────────────────────────────────
    // Advertiser به Executor امتیاز می‌دهد
    // ─────────────────────────────────────────────────────────────

    public function rateExecutor(int $executionId, int $advertiserId, int $stars, string $comment = ''): array
    {
        $stars = max(1, min(5, $stars));

        $exec = $this->db->fetch(
            "SELECT ste.*
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.id = ? AND sa.advertiser_id = ?
               AND ste.decision IN ('approved','soft_approved')
             LIMIT 1",
            [$executionId, $advertiserId]
        );

        if (!$exec) {
            return ['success' => false, 'message' => 'اجرا یافت نشد'];
        }

        if ($this->hasRated($executionId, $advertiserId, 'advertiser')) {
            return ['success' => false, 'message' => 'قبلاً امتیاز داده‌اید'];
        }

        if (!$this->isWithinRatingWindow($exec->completed_at ?? $exec->created_at)) {
            return ['success' => false, 'message' => 'مهلت امتیازدهی گذشته است'];
        }

        $this->db->insert(
            "INSERT INTO social_ratings
               (execution_id, rater_id, rated_id, rater_type, stars, comment, status, created_at)
             VALUES (?, ?, ?, 'advertiser', ?, ?, 'approved', NOW())",
            [$executionId, $advertiserId, $exec->executor_id, $stars, trim($comment)]
        );

        // تأثیر روی Trust Score executor
        if ($stars >= 4) {
            $this->trust->penalizeSuspicious((int)$exec->executor_id, 'high_rating_from_advertiser');
            // در واقع جایزه - از penalize سوءاستفاده نمی‌کنیم، از rewardGoodTask استفاده می‌کنیم
        }

        $this->updateExecutorRating((int)$exec->executor_id);

        return ['success' => true, 'message' => 'امتیاز ثبت شد'];
    }

    // ─────────────────────────────────────────────────────────────
    // دریافت امتیاز
    // ─────────────────────────────────────────────────────────────

    /**
     * میانگین امتیاز یک advertiser (از نظر executors)
     */
    public function getAdvertiserRating(int $advertiserId): array
    {
        $row = $this->db->fetch(
            "SELECT AVG(stars) AS avg_stars, COUNT(*) AS total_ratings
             FROM social_ratings
             WHERE rated_id = ? AND rater_type = 'executor'",
            [$advertiserId]
        );

        return [
            'avg_stars'     => $row ? round((float)$row->avg_stars, 1) : 0,
            'total_ratings' => $row ? (int)$row->total_ratings : 0,
        ];
    }

    /**
     * میانگین امتیاز یک executor (از نظر advertisers)
     */
    public function getExecutorRating(int $executorId): array
    {
        $row = $this->db->fetch(
            "SELECT AVG(stars) AS avg_stars, COUNT(*) AS total_ratings
             FROM social_ratings
             WHERE rated_id = ? AND rater_type = 'advertiser'",
            [$executorId]
        );

        return [
            'avg_stars'     => $row ? round((float)$row->avg_stars, 1) : 0,
            'total_ratings' => $row ? (int)$row->total_ratings : 0,
        ];
    }

    /**
     * نظرات یک کاربر
     */
    public function getComments(int $userId, string $raterType = 'advertiser', int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT sr.stars, sr.comment, sr.created_at, u.full_name AS rater_name
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rater_id
             WHERE sr.rated_id = ? AND sr.rater_type = ? AND sr.status = 'approved'
             ORDER BY sr.created_at DESC
             LIMIT ?",
            [$userId, $raterType, $limit]
        ) ?: [];
    }

    public function getPendingReviews(int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT sr.*, u.full_name AS rater_name, rated.full_name AS rated_name, ste.title AS execution_title
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rater_id
             JOIN users rated ON rated.id = sr.rated_id
             JOIN social_task_executions ste ON ste.id = sr.execution_id
             WHERE sr.status = 'pending'
             ORDER BY sr.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        ) ?: [];
    }

    public function getReviewById(int $reviewId): ?array
    {
        return $this->db->fetch(
            "SELECT sr.*, u.full_name AS rater_name, rated.full_name AS rated_name, ste.title AS execution_title
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rater_id
             JOIN users rated ON rated.id = sr.rated_id
             JOIN social_task_executions ste ON ste.id = sr.execution_id
             WHERE sr.id = ?",
            [$reviewId]
        ) ?: null;
    }

    public function moderateReview(int $reviewId, string $status, int $adminId): array
    {
        $allowed = ['approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'وضعیت نامعتبر است'];
        }

        $review = $this->getReviewById($reviewId);
        if (!$review) {
            return ['success' => false, 'message' => 'بررسی یافت نشد'];
        }

        $this->db->query(
            "UPDATE social_ratings SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$status, $adminId, $reviewId]
        );

        if ($status === 'approved') {
            if ($review->rater_type === 'executor') {
                $this->updateAdvertiserRating((int)$review->rated_id);
            } else {
                $this->updateExecutorRating((int)$review->rated_id);
            }
        }

        return ['success' => true, 'message' => 'بررسی با موفقیت به‌روزرسانی شد'];
    }

    public function getReviewStats(): array
    {
        $summary = $this->db->fetch(
            "SELECT
                SUM(status = 'pending') AS pending_reviews,
                SUM(status = 'approved') AS approved_reviews,
                SUM(status = 'rejected') AS rejected_reviews
             FROM social_ratings"
        );

        return [
            'pending_reviews'  => $summary ? (int)$summary->pending_reviews : 0,
            'approved_reviews' => $summary ? (int)$summary->approved_reviews : 0,
            'rejected_reviews' => $summary ? (int)$summary->rejected_reviews : 0,
        ];
    }

    public function getRatingHistory(int $userId, string $role = 'rated', int $limit = 20, int $offset = 0): array
    {
        $column = $role === 'rater' ? 'sr.rater_id' : 'sr.rated_id';

        return $this->db->fetchAll(
            "SELECT sr.*, u.full_name AS rater_name, rated.full_name AS rated_name, ste.title AS execution_title
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rater_id
             JOIN users rated ON rated.id = sr.rated_id
             JOIN social_task_executions ste ON ste.id = sr.execution_id
             WHERE $column = ?
             ORDER BY sr.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        ) ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    public function hasRated(int $executionId, int $raterId, string $raterType): bool
    {
        $row = $this->db->fetch(
            "SELECT id FROM social_ratings
             WHERE execution_id = ? AND rater_id = ? AND rater_type = ? LIMIT 1",
            [$executionId, $raterId, $raterType]
        );
        return (bool)$row;
    }

    private function isWithinRatingWindow(?string $completedAt): bool
    {
        if (!$completedAt) return false;
        $completed = strtotime($completedAt);
        return (time() - $completed) <= (self::RATING_WINDOW_HOURS * 3600);
    }

    private function updateAdvertiserRating(int $advertiserId): void
    {
        $rating = $this->getAdvertiserRating($advertiserId);
        $this->db->query(
            "UPDATE users SET social_advertiser_rating = ?, social_rating_count = ? WHERE id = ?",
            [$rating['avg_stars'], $rating['total_ratings'], $advertiserId]
        );
    }

    private function updateExecutorRating(int $executorId): void
    {
        $rating = $this->getExecutorRating($executorId);
        $this->db->query(
            "UPDATE users SET social_executor_rating = ?, social_rating_count = ? WHERE id = ?",
            [$rating['avg_stars'], $rating['total_ratings'], $executorId]
        );
    }
}
