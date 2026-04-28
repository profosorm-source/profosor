<?php

namespace App\Models;

use Core\Model;

/**
 * SocialUserTrust Model
 * جدول: social_user_trust
 */
class SocialUserTrust extends Model
{
    protected static string $table = 'social_user_trust';

    public static function getOrDefault(int $userId): object
    {
        $row = static::db()->fetch(
            "SELECT * FROM social_user_trust WHERE user_id = ? LIMIT 1",
            [$userId]
        );
        if (!$row) {
            return (object)['user_id' => $userId, 'trust_score' => 50.0];
        }
        return $row;
    }

    public static function updateScore(int $userId, float $score): void
    {
        static::db()->query(
            "INSERT INTO social_user_trust (user_id, trust_score, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE trust_score = ?, updated_at = NOW()",
            [$userId, $score, $score]
        );
    }
}
