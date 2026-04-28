<?php

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * SocialTaskExecution Model
 * جدول: social_task_executions
 * ✅ Using QueryBuilder for all queries (SQL injection prevention)
 */
class SocialTaskExecution extends Model
{
    protected static string $table = 'social_task_executions';

    public static function getByExecutor(int $userId, int $limit = 20, int $offset = 0): array
    {
        // ✅ Using QueryBuilder instead of raw SQL
        return Database::getInstance()
            ->table('social_task_executions')
            ->select('ste.id', 'ste.status', 'ste.created_at', 'ste.updated_at', 
                    'sa.title', 'sa.platform', 'sa.task_type', 'sa.reward')
            ->join('social_ads', 'sa.id', '=', 'ste.ad_id')
            ->where('executor_id', '=', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get() ?? [];
    }

    public static function getPending(int $userId, int $adId): ?object
    {
        // ✅ Using QueryBuilder
        return Database::getInstance()
            ->table('social_task_executions')
            ->where('executor_id', '=', $userId)
            ->where('ad_id', '=', $adId)
            ->where('status', 'NOT IN', ['expired', 'cancelled'])
            ->limit(1)
            ->first();
    }

    public static function getFlagged(int $limit = 30): array
    {
        // ✅ Using QueryBuilder
        return Database::getInstance()
            ->table('social_task_executions')
            ->select('ste.id', 'ste.status', 'ste.created_at',
                    'u.full_name', 'sa.platform', 'sa.task_type')
            ->join('users', 'u.id', '=', 'ste.executor_id')
            ->join('social_ads', 'sa.id', '=', 'ste.ad_id')
            ->where('flag_review', '=', 1)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }
}

