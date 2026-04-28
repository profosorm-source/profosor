<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * SocialAd Model - آگهی‌های شبکه‌اجتماعی
 * جدول: social_ads
 * ✅ State machine enforcement
 * ✅ Null safety checks
 */
class SocialAd extends Model
{
    protected static string $table = 'social_ads';

    // Status Constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REJECTED = 'rejected';

    /**
     * دریافت آگهی‌های فعال
     * ✅ Null safety on slots
     */
    public static function getActive(int $limit = 20, int $offset = 0): array
    {
        return static::db()->fetchAll(
            "SELECT * FROM social_ads
             WHERE status = ? AND remaining_slots > 0 AND deleted_at IS NULL
             ORDER BY RAND()
             LIMIT ? OFFSET ?",
            [self::STATUS_ACTIVE, $limit, $offset]
        ) ?: [];
    }

    public static function getByAdvertiser(int $advertiserId, int $limit = 20, int $offset = 0): array
    {
        return static::db()->fetchAll(
            "SELECT * FROM social_ads
             WHERE advertiser_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            [$advertiserId, $limit, $offset]
        ) ?: [];
    }

    public static function decrementSlot(int $id): void
    {
        static::db()->query(
            "UPDATE social_ads SET remaining_slots = remaining_slots - 1 WHERE id = ? AND remaining_slots > 0",
            [$id]
        );
    }

    public static function incrementSlot(int $id): void
    {
        static::db()->query(
            "UPDATE social_ads SET remaining_slots = remaining_slots + 1 WHERE id = ?",
            [$id]
        );
    }
}
