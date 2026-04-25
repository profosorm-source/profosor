<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * InfluencerProfile Model - Influencer verification & profile management
 * 
 * Status Flow: pending → verified → (suspended)
 * - pending: ثبت کننده، منتظر تایید مدیر
 * - verified: تایید شده، فعال است
 * - suspended: تعلیق شده (مسائل رفتاری)
 * - rejected: رد شده
 */
class InfluencerProfile extends Model
{
    // ┌─────────────────────────────────────────────────────────────┐
    // │ Status Constants
    // └─────────────────────────────────────────────────────────────┘
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_PENDING_ADMIN_REVIEW = 'pending_admin_review';

    public const ACTIVE_STATUSES = [self::STATUS_VERIFIED];
    public const INACTIVE_STATUSES = [self::STATUS_REJECTED, self::STATUS_SUSPENDED];

    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE ip.id = ? AND ip.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);
        return $r ?: null;
    }

    public function findByUserId(int $userId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE ip.user_id = ? AND ip.deleted_at IS NULL
        ");
        $stmt->execute([$userId]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);
        return $r ?: null;
    }

    public function create(array $d): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO influencer_profiles
            (user_id, platform, username, page_url, profile_image, follower_count,
             engagement_rate, category, bio, story_price_24h, post_price_24h,
             post_price_48h, post_price_72h, currency, status, verification_code)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $result = $stmt->execute([
            $d['user_id'], $d['platform'] ?? 'instagram', $d['username'],
            $d['page_url'], $d['profile_image'] ?? null, $d['follower_count'] ?? 0,
            $d['engagement_rate'] ?? 0, $d['category'] ?? null, $d['bio'] ?? null,
            $d['story_price_24h'] ?? 0, $d['post_price_24h'] ?? 0,
            $d['post_price_48h'] ?? 0, $d['post_price_72h'] ?? 0,
            $d['currency'] ?? 'irt', $d['status'] ?? 'pending',
            $d['verification_code'] ?? null,
        ]);
        if (!$result) return null;
        return $this->find((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): bool
    {
        $fields = []; $values = [];
        $allowed = [
            'username','page_url','profile_image','follower_count','engagement_rate',
            'category','bio','story_price_24h','post_price_24h','post_price_48h',
            'post_price_72h','currency','total_orders','completed_orders','average_rating',
            'status','rejection_reason','verified_by','verified_at','is_active','priority',
            'verification_code','verification_post_url','suspended_at','suspended_reason',
        ];
        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?"; $values[] = $data[$f];
            }
        }
        if (empty($fields)) return false;
        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE influencer_profiles SET " . \implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /**
     * لیست اینفلوئنسرهای تأیید‌شده (برای کاربر تبلیغ‌دهنده)
     */
    public function getVerified(array $filters = [], string $sort = 'priority', int $limit = 20, int $offset = 0): array
    {
        $where = ["ip.status = 'verified'", "ip.is_active = 1", "ip.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = "ip.category = ?"; $params[] = $filters['category'];
        }
        if (!empty($filters['min_followers'])) {
            $where[] = "ip.follower_count >= ?"; $params[] = (int) $filters['min_followers'];
        }
        if (!empty($filters['max_price'])) {
            $where[] = "ip.story_price_24h <= ?"; $params[] = (float) $filters['max_price'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR ip.bio LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);

        $orderBy = match ($sort) {
            'followers' => 'ip.follower_count DESC',
            'price_low' => 'ip.story_price_24h ASC',
            'price_high' => 'ip.story_price_24h DESC',
            'rating' => 'ip.average_rating DESC',
            'orders' => 'ip.completed_orders DESC',
            default => 'ip.priority DESC, ip.completed_orders DESC',
        };

        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE {$whereStr}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit; $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function countVerified(array $filters = []): int
    {
        $where = ["ip.status = 'verified'", "ip.is_active = 1", "ip.deleted_at IS NULL"];
        $params = [];
        if (!empty($filters['category'])) { $where[] = "ip.category = ?"; $params[] = $filters['category']; }
        if (!empty($filters['min_followers'])) { $where[] = "ip.follower_count >= ?"; $params[] = (int) $filters['min_followers']; }
        if (!empty($filters['max_price'])) { $where[] = "ip.story_price_24h <= ?"; $params[] = (float) $filters['max_price']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR ip.bio LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * لیست ادمین
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ["ip.deleted_at IS NULL"]; $params = [];
        if (!empty($filters['status'])) { $where[] = "ip.status = ?"; $params[] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id
            WHERE {$whereStr} ORDER BY ip.created_at DESC LIMIT ? OFFSET ?
        ");
        $params[] = $limit; $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCount(array $filters = []): int
    {
        $where = ["ip.deleted_at IS NULL"]; $params = [];
        if (!empty($filters['status'])) { $where[] = "ip.status = ?"; $params[] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function statusLabels(): array
    {
        return [
            'pending'             => 'در انتظار ثبت پست',
            'pending_admin_review'=> 'در انتظار تایید مدیر',
            'verified'            => 'تایید شده',
            'rejected'            => 'رد شده',
            'suspended'           => 'تعلیق شده',
        ];
    }

    public function categories(): array
    {
        $cats = setting('influencer_categories', '');
        return $cats ? \explode(',', $cats) : [];
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ Null Safety Methods
    // └─────────────────────────────────────────────────────────────┘

    /**
     * Check if profile exists and is active
     */
    public function exists(int $profileId): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM influencer_profiles WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$profileId]
        )->fetch();
        return $result !== null;
    }

    /**
     * Get profile with null safety
     */
    public function getSafe(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        return $this->find($id);
    }

    /**
     * Get profile by user ID with null safety
     */
    public function getByUserSafe(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }
        return $this->findByUserId($userId);
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ State Machine Validation
    // └─────────────────────────────────────────────────────────────┘

    /**
     * Valid status transitions
     */
    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_PENDING_ADMIN_REVIEW, self::STATUS_REJECTED],
        self::STATUS_PENDING_ADMIN_REVIEW => [self::STATUS_VERIFIED, self::STATUS_REJECTED],
        self::STATUS_VERIFIED => [self::STATUS_SUSPENDED],
        self::STATUS_SUSPENDED => [self::STATUS_VERIFIED],
        self::STATUS_REJECTED => [], // Terminal state
    ];

    /**
     * Check if transition is valid
     */
    public function canTransitionTo(string $currentStatus, string $targetStatus): bool
    {
        if (!isset(self::TRANSITIONS[$currentStatus])) {
            return false;
        }
        return \in_array($targetStatus, self::TRANSITIONS[$currentStatus], true);
    }

    /**
     * Get allowed next states
     */
    public function getAllowedTransitions(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * Check if status is terminal (no more transitions)
     */
    public function isTerminalStatus(string $status): bool
    {
        return empty(self::TRANSITIONS[$status] ?? []);
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ Business Logic Methods
    // └─────────────────────────────────────────────────────────────┘

    /**
     * Can accept new orders
     */
    public function canAcceptOrders(int $profileId): bool
    {
        $profile = $this->getSafe($profileId);
        if (!$profile) {
            return false;
        }
        return $profile->status === self::STATUS_VERIFIED && $profile->is_active == 1;
    }

    /**
     * Can create disputes
     */
    public function canCreateDispute(int $profileId): bool
    {
        $profile = $this->getSafe($profileId);
        if (!$profile) {
            return false;
        }
        // Can't dispute if suspended or rejected
        return !\in_array($profile->status, self::INACTIVE_STATUSES, true);
    }

    /**
     * Check if profile belongs to user
     */
    public function belongsToUser(int $profileId, int $userId): bool
    {
        $profile = $this->getSafe($profileId);
        if (!$profile) {
            return false;
        }
        return $profile->user_id === $userId;
    }

    /**
     * Get unread disputes count
     */
    public function getUnreadDisputesCount(int $profileId): int
    {
        if (!$this->exists($profileId)) {
            return 0;
        }
        $result = $this->db->query(
            "SELECT COUNT(*) FROM influencer_disputes 
             WHERE profile_id = ? AND status = 'open' AND read_at IS NULL",
            [$profileId]
        )->fetch();
        return (int)($result->{0} ?? 0);
    }
}
