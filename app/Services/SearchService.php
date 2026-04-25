<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;

/**
 * SearchService - جستجو و فیلتراسیون یکپارچه برای تمام ماژول‌ها
 * 
 * پشتیبانی‌شده: SocialTask, Influencer, Vitrine
 * ✅ SQL Injection prevention via QueryBuilder
 * ✅ Result pagination
 * ✅ Multi-column search
 * ✅ Redis caching (5 min TTL) for performance
 */
class SearchService
{
    private Database $db;
    private CacheService $cache;
    private Logger $logger;
    
    private const MODULES = ['social_task', 'influencer', 'vitrine'];
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(Database $db, CacheService $cache, Logger $logger)
    {
        $this->db     = $db;
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    /**
     * جستجوی یکپارچه بر روی تمام ماژول‌ها یا ماژول‌های خاص
     * ✅ With Redis caching (5 min TTL)
     * 
     * @param string|array $modules Module(s) to search: 'social_task', 'influencer', 'vitrine'
     * @param array $filters         Search filters: ['q' => 'keyword', 'category' => '...', 'sort' => '...']
     * @param int $limit             Results per page
     * @param int $offset            Pagination offset
     * @return array                 Results grouped by module
     */
    public function search(
        $modules,
        array $filters = [],
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0
    ): array {
        $limit  = min((int)$limit, self::MAX_LIMIT);
        $offset = max(0, (int)$offset);
        $modules = is_array($modules) ? $modules : [$modules];

        $results = [];

        foreach ($modules as $module) {
            if (!in_array($module, self::MODULES, true)) {
                continue;
            }

            // ✅ Try to get from cache first
            $cacheKey = $this->generateCacheKey($module, $filters, $limit, $offset);
            $cached = $this->cache->get($cacheKey);
            
            if ($cached !== null) {
                $results[$module] = $cached;
                continue;
            }

            // ✅ Query database if not in cache
            $searchResult = match ($module) {
                'social_task'  => $this->searchSocialTasks($filters, $limit, $offset),
                'influencer'   => $this->searchInfluencers($filters, $limit, $offset),
                'vitrine'      => $this->searchVitrine($filters, $limit, $offset),
                default        => []
            };
            
            // ✅ Cache the result for 5 minutes
            $this->cache->set($cacheKey, $searchResult, self::CACHE_TTL);
            $results[$module] = $searchResult;
        }

        return $results;
    }

    /**
     * Generate cache key with MD5 hash of filters
     */
    private function generateCacheKey(string $module, array $filters, int $limit, int $offset): string
    {
        $filterHash = md5(json_encode($filters));
        return "search:{$module}:{$filterHash}:{$limit}:{$offset}";
    }

    /**
     * Invalidate all cached search results for a module
     * Called when data changes (new task created, profile updated, etc)
     */
    public function invalidateModuleCache(string $module): void
    {
        if (!in_array($module, self::MODULES, true)) {
            return;
        }

        try {
            // ✅ Get Redis connection and delete all keys matching pattern
            $redis = new \Redis();
            $redis->connect('localhost', 6379);
            
            // Pattern: search:{module}:*
            $pattern = "search:{$module}:*";
            $keys = $redis->keys($pattern);
            
            if (!empty($keys)) {
                $redis->delete(...$keys);
                $this->logger->info("search.cache_invalidated", [
                    'module' => $module,
                    'keys_deleted' => count($keys)
                ]);
            }
            
            $redis->close();
        } catch (\Exception $e) {
            $this->logger->error("search.cache_invalidation_failed", [
                'module' => $module,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * جستجو در تسک‌های شبکه‌اجتماعی
     * ✅ Using QueryBuilder
     */
    private function searchSocialTasks(array $f, int $limit, int $offset): array
    {
        $query = $this->db->table('social_ads')
            ->select('id', 'title', 'description', 'platform', 'task_type', 'reward', 'status', 'created_at')
            ->where('status', '=', 'active');

        // ✅ Full-text search
        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where('title', 'LIKE', $q)
                  ->orWhere('description', 'LIKE', $q);
        }

        // ✅ Platform filter
        if (!empty($f['platform'])) {
            $query->where('platform', '=', htmlspecialchars($f['platform'], ENT_QUOTES, 'UTF-8'));
        }

        // ✅ Task type filter
        if (!empty($f['task_type'])) {
            $query->where('task_type', '=', htmlspecialchars($f['task_type'], ENT_QUOTES, 'UTF-8'));
        }

        // ✅ Price range
        if (!empty($f['min_reward'])) {
            $query->where('reward', '>=', (float)$f['min_reward']);
        }
        if (!empty($f['max_reward'])) {
            $query->where('reward', '<=', (float)$f['max_reward']);
        }

        // ✅ Sorting
        $sort = match ($f['sort'] ?? 'newest') {
            'oldest'  => ['created_at', 'ASC'],
            'reward_high' => ['reward', 'DESC'],
            'reward_low'  => ['reward', 'ASC'],
            default       => ['created_at', 'DESC']
        };

        $results = $query->orderBy(...$sort)
                        ->limit($limit)
                        ->offset($offset)
                        ->get() ?? [];

        return [
            'total' => $this->countSocialTasks($f),
            'items' => $results
        ];
    }

    /**
     * جستجو در پروفایل‌های اینفلوئنسر
     * ✅ Using QueryBuilder
     */
    private function searchInfluencers(array $f, int $limit, int $offset): array
    {
        $query = $this->db->table('influencer_profiles as ip')
            ->select('ip.id', 'ip.display_name', 'ip.bio', 'ip.platform', 
                    'ip.followers', 'ip.avg_engagement', 'ip.status', 'ip.created_at')
            ->join('users as u', 'u.id', '=', 'ip.user_id')
            ->where('ip.status', '=', 'active');

        // ✅ Full-text search
        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where('ip.display_name', 'LIKE', $q)
                  ->orWhere('ip.bio', 'LIKE', $q);
        }

        // ✅ Platform filter
        if (!empty($f['platform'])) {
            $query->where('ip.platform', '=', htmlspecialchars($f['platform'], ENT_QUOTES, 'UTF-8'));
        }

        // ✅ Follower range
        if (!empty($f['min_followers'])) {
            $query->where('ip.followers', '>=', (int)$f['min_followers']);
        }
        if (!empty($f['max_followers'])) {
            $query->where('ip.followers', '<=', (int)$f['max_followers']);
        }

        // ✅ Sorting
        $sort = match ($f['sort'] ?? 'newest') {
            'followers' => ['ip.followers', 'DESC'],
            'engagement' => ['ip.avg_engagement', 'DESC'],
            'oldest' => ['ip.created_at', 'ASC'],
            default => ['ip.created_at', 'DESC']
        };

        $results = $query->orderBy(...$sort)
                        ->limit($limit)
                        ->offset($offset)
                        ->get() ?? [];

        return [
            'total' => $this->countInfluencers($f),
            'items' => $results
        ];
    }

    /**
     * جستجو در ویترین
     * ✅ Using QueryBuilder
     */
    private function searchVitrine(array $f, int $limit, int $offset): array
    {
        $query = $this->db->table('vitrine_listings as vl')
            ->select('vl.id', 'vl.title', 'vl.description', 'vl.category', 'vl.platform',
                    'vl.price_usdt', 'vl.listing_type', 'vl.status', 'vl.created_at')
            ->where('vl.status', '=', 'active')
            ->where('vl.listing_type', '=', 'sell');

        // ✅ Full-text search
        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where('vl.title', 'LIKE', $q)
                  ->orWhere('vl.description', 'LIKE', $q);
        }

        // ✅ Category filter
        if (!empty($f['category'])) {
            $query->where('vl.category', '=', htmlspecialchars($f['category'], ENT_QUOTES, 'UTF-8'));
        }

        // ✅ Platform filter
        if (!empty($f['platform'])) {
            $query->where('vl.platform', '=', htmlspecialchars($f['platform'], ENT_QUOTES, 'UTF-8'));
        }

        // ✅ Price range
        if (!empty($f['min_price'])) {
            $query->where('vl.price_usdt', '>=', (float)$f['min_price']);
        }
        if (!empty($f['max_price'])) {
            $query->where('vl.price_usdt', '<=', (float)$f['max_price']);
        }

        // ✅ Sorting
        $sort = match ($f['sort'] ?? 'newest') {
            'price_asc'  => ['vl.price_usdt', 'ASC'],
            'price_desc' => ['vl.price_usdt', 'DESC'],
            'oldest' => ['vl.created_at', 'ASC'],
            default => ['vl.created_at', 'DESC']
        };

        $results = $query->orderBy(...$sort)
                        ->limit($limit)
                        ->offset($offset)
                        ->get() ?? [];

        return [
            'total' => $this->countVitrine($f),
            'items' => $results
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Count Methods (for pagination)
    // ──────────────────────────────────────────────────────────────────────────

    private function countSocialTasks(array $f): int
    {
        $query = $this->db->table('social_ads')
            ->where('status', '=', 'active');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where('title', 'LIKE', $q)
                  ->orWhere('description', 'LIKE', $q);
        }
        if (!empty($f['platform'])) {
            $query->where('platform', '=', $f['platform']);
        }
        if (!empty($f['task_type'])) {
            $query->where('task_type', '=', $f['task_type']);
        }
        if (!empty($f['min_reward'])) {
            $query->where('reward', '>=', (float)$f['min_reward']);
        }
        if (!empty($f['max_reward'])) {
            $query->where('reward', '<=', (float)$f['max_reward']);
        }

        return (int)$query->count();
    }

    private function countInfluencers(array $f): int
    {
        $query = $this->db->table('influencer_profiles as ip')
            ->join('users as u', 'u.id', '=', 'ip.user_id')
            ->where('ip.status', '=', 'active');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where('ip.display_name', 'LIKE', $q)
                  ->orWhere('ip.bio', 'LIKE', $q);
        }
        if (!empty($f['platform'])) {
            $query->where('ip.platform', '=', $f['platform']);
        }
        if (!empty($f['min_followers'])) {
            $query->where('ip.followers', '>=', (int)$f['min_followers']);
        }
        if (!empty($f['max_followers'])) {
            $query->where('ip.followers', '<=', (int)$f['max_followers']);
        }

        return (int)$query->count();
    }

    private function countVitrine(array $f): int
    {
        $query = $this->db->table('vitrine_listings as vl')
            ->where('vl.status', '=', 'active')
            ->where('vl.listing_type', '=', 'sell');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where('vl.title', 'LIKE', $q)
                  ->orWhere('vl.description', 'LIKE', $q);
        }
        if (!empty($f['category'])) {
            $query->where('vl.category', '=', $f['category']);
        }
        if (!empty($f['platform'])) {
            $query->where('vl.platform', '=', $f['platform']);
        }
        if (!empty($f['min_price'])) {
            $query->where('vl.price_usdt', '>=', (float)$f['min_price']);
        }
        if (!empty($f['max_price'])) {
            $query->where('vl.price_usdt', '<=', (float)$f['max_price']);
        }

        return (int)$query->count();
    }
}
