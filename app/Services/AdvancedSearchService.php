<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;
use Core\Cache;

/**
 * AdvancedSearchService - Unified Advanced Search Service
 *
 * Combines GlobalSearchService and SearchService capabilities with:
 * - Caching for performance
 * - Analytics logging
 * - QueryBuilder for consistency
 * - Pagination support
 * - SQL injection prevention
 */
class AdvancedSearchService
{
    private Database $db;
    private Cache $cache;
    private Logger $logger;

    private const CACHE_TTL = 300; // 5 minutes
    private const MODULES = ['social_task', 'influencer', 'vitrine'];
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->cache = Cache::getInstance();
        $this->logger = $logger;
    }

    /**
     * Global search for admins
     */
    public function searchAdmin(string $query, int $limit = 5): array
    {
        $this->logSearch('admin', $query, null);

        $cacheKey = "global_search_admin:" . md5($query . $limit);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);
        if (strlen($q) < 2) {
            return $this->emptyGlobalResult();
        }

        $results = [
            'users' => $this->searchUsers($q, $limit),
            'transactions' => $this->searchTransactions($q, $limit),
            'tickets' => $this->searchTickets($q, $limit),
            'withdrawals' => $this->searchWithdrawals($q, $limit),
            'deposits' => $this->searchDeposits($q, $limit),
            'ads' => $this->searchAds($q, $limit),
        ];

        $total = array_sum(array_map('count', $results));
        $results['total'] = $total;

        $this->cache->set($cacheKey, $results, self::CACHE_TTL);

        return $results;
    }

    /**
     * Global search for users (limited to their data)
     */
    public function searchUser(string $query, int $userId, int $limit = 5): array
    {
        $this->logSearch('user', $query, $userId);

        $cacheKey = "global_search_user:{$userId}:" . md5($query . $limit);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);
        if (strlen($q) < 2) {
            return $this->emptyUserResult();
        }

        $results = [
            'transactions' => $this->searchUserTransactions($q, $userId, $limit),
            'tickets' => $this->searchUserTickets($q, $userId, $limit),
            'ads' => $this->searchUserAds($q, $userId, $limit),
            'tasks' => $this->searchUserTasks($q, $userId, $limit),
        ];

        $total = array_sum(array_map('count', $results));
        $results['total'] = $total;

        $this->cache->set($cacheKey, $results, self::CACHE_TTL);

        return $results;
    }

    /**
     * Module-specific search (from SearchService)
     */
    public function searchModules(
        $modules,
        array $filters = [],
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0
    ): array {
        $this->logSearch('module', json_encode($filters), null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);
        $modules = is_array($modules) ? $modules : [$modules];

        $results = [];

        foreach ($modules as $module) {
            if (!in_array($module, self::MODULES, true)) {
                continue;
            }

            $cacheKey = $this->generateCacheKey($module, $filters, $limit, $offset);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                $results[$module] = $cached;
                continue;
            }

            $searchResult = match ($module) {
                'social_task' => $this->searchSocialTasks($filters, $limit, $offset),
                'influencer' => $this->searchInfluencers($filters, $limit, $offset),
                'vitrine' => $this->searchVitrine($filters, $limit, $offset),
                default => []
            };

            $this->cache->set($cacheKey, $searchResult, self::CACHE_TTL);
            $results[$module] = $searchResult;
        }

        return $results;
    }

    /**
     * Invalidate cache for a module
     */
    public function invalidateModuleCache(string $module): void
    {
        if (!in_array($module, self::MODULES, true)) {
            return;
        }

        try {
            $redis = new \Redis();
            $redis->connect('localhost', 6379);

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

    // Private methods for global search (converted to QueryBuilder)

    private function searchUsers(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('users')
            ->select('id', 'full_name', 'email', 'mobile', 'kyc_status', 'tier_level', 'created_at')
            ->where('deleted_at', 'IS', null)
            ->where(function($query) use ($like) {
                $query->where('full_name', 'LIKE', $like)
                      ->orWhere('email', 'LIKE', $like)
                      ->orWhere('mobile', 'LIKE', $like)
                      ->orWhere('referral_code', '=', $q);
            })
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    private function searchTransactions(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('transactions as t')
            ->select('t.id', 't.type', 't.amount', 't.currency', 't.status', 't.description', 't.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->where(function($query) use ($like, $q) {
                $query->where('t.reference_id', 'LIKE', $like)
                      ->orWhere('t.description', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like)
                      ->orWhere('t.id', '=', $q);
            })
            ->orderBy('t.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    private function searchTickets(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('tickets as tk')
            ->select('tk.id', 'tk.subject', 'tk.status', 'tk.priority', 'tk.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'tk.user_id')
            ->where(function($query) use ($like, $q) {
                $query->where('tk.subject', 'LIKE', $like)
                      ->orWhere('tk.message', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like)
                      ->orWhere('tk.id', '=', $q);
            })
            ->orderBy('tk.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    private function searchWithdrawals(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('withdrawals as w')
            ->select('w.id', 'w.amount', 'w.currency', 'w.status', 'w.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'w.user_id')
            ->where(function($query) use ($like, $q) {
                $query->where('w.tracking_code', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like)
                      ->orWhere('w.id', '=', $q);
            })
            ->orderBy('w.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    private function searchDeposits(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $manual = $this->db->table('manual_deposits as md')
            ->selectRaw("md.id, md.amount, 'manual' as type, md.status, md.created_at, u.full_name, u.email")
            ->leftJoin('users as u', 'u.id', '=', 'md.user_id')
            ->where(function($query) use ($like) {
                $query->where('md.tracking_code', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like);
            });

        $crypto = $this->db->table('crypto_deposits as cd')
            ->selectRaw("cd.id, cd.amount, 'crypto' as type, cd.status, cd.created_at, u.full_name, u.email")
            ->leftJoin('users as u', 'u.id', '=', 'cd.user_id')
            ->where(function($query) use ($like) {
                $query->where('cd.tx_hash', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like);
            });

        $results = array_merge($manual->get() ?? [], $crypto->get() ?? []);
        usort($results, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
        return array_slice($results, 0, $limit);
    }

    private function searchAds(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('advertisements as a')
            ->select('a.id', 'a.title', 'a.platform', 'a.task_type', 'a.status', 'a.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.deleted_at', 'IS', null)
            ->where(function($query) use ($like) {
                $query->where('a.title', 'LIKE', $like)
                      ->orWhere('u.email', 'LIKE', $like);
            })
            ->orderBy('a.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    private function searchUserTransactions(string $q, int $userId, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('transactions')
            ->select('id', 'type', 'amount', 'currency', 'status', 'description', 'created_at')
            ->where('user_id', '=', $userId)
            ->where(function($query) use ($like) {
                $query->where('description', 'LIKE', $like)
                      ->orWhere('reference_id', 'LIKE', $like);
            })
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    private function searchUserTickets(string $q, int $userId, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('tickets')
            ->select('id', 'subject', 'status', 'priority', 'created_at')
            ->where('user_id', '=', $userId)
            ->where(function($query) use ($like) {
                $query->where('subject', 'LIKE', $like)
                      ->orWhere('message', 'LIKE', $like);
            })
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    private function searchUserAds(string $q, int $userId, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('advertisements')
            ->select('id', 'title', 'platform', 'task_type', 'status', 'created_at')
            ->where('user_id', '=', $userId)
            ->where('deleted_at', 'IS', null)
            ->where('title', 'LIKE', $like)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    private function searchUserTasks(string $q, int $userId, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->table('task_executions as te')
            ->select('te.id', 'te.status', 'te.reward_amount', 'te.created_at', 'a.title as ad_title')
            ->join('advertisements as a', 'a.id', '=', 'te.advertisement_id')
            ->where('te.executor_id', '=', $userId)
            ->where('a.title', 'LIKE', $like)
            ->orderBy('te.created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    // Module search methods (from SearchService)

    private function searchSocialTasks(array $f, int $limit, int $offset): array
    {
        $query = $this->db->table('social_ads')
            ->select('id', 'title', 'description', 'platform', 'task_type', 'reward', 'status', 'created_at')
            ->where('status', '=', 'active');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where(function($sub) use ($q) {
                $sub->where('title', 'LIKE', $q)->orWhere('description', 'LIKE', $q);
            });
        }

        if (!empty($f['platform'])) {
            $query->where('platform', '=', htmlspecialchars($f['platform'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($f['task_type'])) {
            $query->where('task_type', '=', htmlspecialchars($f['task_type'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($f['min_reward'])) {
            $query->where('reward', '>=', (float)$f['min_reward']);
        }
        if (!empty($f['max_reward'])) {
            $query->where('reward', '<=', (float)$f['max_reward']);
        }

        $sort = match ($f['sort'] ?? 'newest') {
            'oldest' => ['created_at', 'ASC'],
            'reward_high' => ['reward', 'DESC'],
            'reward_low' => ['reward', 'ASC'],
            default => ['created_at', 'DESC']
        };

        $results = $query->orderBy(...$sort)->limit($limit)->offset($offset)->get() ?? [];

        return [
            'total' => $this->countSocialTasks($f),
            'items' => $results
        ];
    }

    private function searchInfluencers(array $f, int $limit, int $offset): array
    {
        $query = $this->db->table('influencer_profiles as ip')
            ->select('ip.id', 'ip.display_name', 'ip.bio', 'ip.platform', 'ip.followers', 'ip.avg_engagement', 'ip.status', 'ip.created_at')
            ->join('users as u', 'u.id', '=', 'ip.user_id')
            ->where('ip.status', '=', 'active');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where(function($sub) use ($q) {
                $sub->where('ip.display_name', 'LIKE', $q)->orWhere('ip.bio', 'LIKE', $q);
            });
        }

        if (!empty($f['platform'])) {
            $query->where('ip.platform', '=', htmlspecialchars($f['platform'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($f['min_followers'])) {
            $query->where('ip.followers', '>=', (int)$f['min_followers']);
        }
        if (!empty($f['max_followers'])) {
            $query->where('ip.followers', '<=', (int)$f['max_followers']);
        }

        $sort = match ($f['sort'] ?? 'newest') {
            'followers' => ['ip.followers', 'DESC'],
            'engagement' => ['ip.avg_engagement', 'DESC'],
            'oldest' => ['ip.created_at', 'ASC'],
            default => ['ip.created_at', 'DESC']
        };

        $results = $query->orderBy(...$sort)->limit($limit)->offset($offset)->get() ?? [];

        return [
            'total' => $this->countInfluencers($f),
            'items' => $results
        ];
    }

    private function searchVitrine(array $f, int $limit, int $offset): array
    {
        $query = $this->db->table('vitrine_listings as vl')
            ->select('vl.id', 'vl.title', 'vl.description', 'vl.category', 'vl.platform', 'vl.price_usdt', 'vl.listing_type', 'vl.status', 'vl.created_at')
            ->where('vl.status', '=', 'active')
            ->where('vl.listing_type', '=', 'sell');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where(function($sub) use ($q) {
                $sub->where('vl.title', 'LIKE', $q)->orWhere('vl.description', 'LIKE', $q);
            });
        }

        if (!empty($f['category'])) {
            $query->where('vl.category', '=', htmlspecialchars($f['category'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($f['platform'])) {
            $query->where('vl.platform', '=', htmlspecialchars($f['platform'], ENT_QUOTES, 'UTF-8'));
        }

        if (!empty($f['min_price'])) {
            $query->where('vl.price_usdt', '>=', (float)$f['min_price']);
        }
        if (!empty($f['max_price'])) {
            $query->where('vl.price_usdt', '<=', (float)$f['max_price']);
        }

        $sort = match ($f['sort'] ?? 'newest') {
            'price_asc' => ['vl.price_usdt', 'ASC'],
            'price_desc' => ['vl.price_usdt', 'DESC'],
            'oldest' => ['vl.created_at', 'ASC'],
            default => ['vl.created_at', 'DESC']
        };

        $results = $query->orderBy(...$sort)->limit($limit)->offset($offset)->get() ?? [];

        return [
            'total' => $this->countVitrine($f),
            'items' => $results
        ];
    }

    // Count methods

    private function countSocialTasks(array $f): int
    {
        $query = $this->db->table('social_ads')->where('status', '=', 'active');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where(function($sub) use ($q) {
                $sub->where('title', 'LIKE', $q)->orWhere('description', 'LIKE', $q);
            });
        }
        if (!empty($f['platform'])) $query->where('platform', '=', $f['platform']);
        if (!empty($f['task_type'])) $query->where('task_type', '=', $f['task_type']);
        if (!empty($f['min_reward'])) $query->where('reward', '>=', (float)$f['min_reward']);
        if (!empty($f['max_reward'])) $query->where('reward', '<=', (float)$f['max_reward']);

        return (int)$query->count();
    }

    private function countInfluencers(array $f): int
    {
        $query = $this->db->table('influencer_profiles as ip')
            ->join('users as u', 'u.id', '=', 'ip.user_id')
            ->where('ip.status', '=', 'active');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where(function($sub) use ($q) {
                $sub->where('ip.display_name', 'LIKE', $q)->orWhere('ip.bio', 'LIKE', $q);
            });
        }
        if (!empty($f['platform'])) $query->where('ip.platform', '=', $f['platform']);
        if (!empty($f['min_followers'])) $query->where('ip.followers', '>=', (int)$f['min_followers']);
        if (!empty($f['max_followers'])) $query->where('ip.followers', '<=', (int)$f['max_followers']);

        return (int)$query->count();
    }

    private function countVitrine(array $f): int
    {
        $query = $this->db->table('vitrine_listings as vl')
            ->where('vl.status', '=', 'active')
            ->where('vl.listing_type', '=', 'sell');

        if (!empty($f['q'])) {
            $q = '%' . trim($f['q']) . '%';
            $query->where(function($sub) use ($q) {
                $sub->where('vl.title', 'LIKE', $q)->orWhere('vl.description', 'LIKE', $q);
            });
        }
        if (!empty($f['category'])) $query->where('vl.category', '=', $f['category']);
        if (!empty($f['platform'])) $query->where('vl.platform', '=', $f['platform']);
        if (!empty($f['min_price'])) $query->where('vl.price_usdt', '>=', (float)$f['min_price']);
        if (!empty($f['max_price'])) $query->where('vl.price_usdt', '<=', (float)$f['max_price']);

        return (int)$query->count();
    }

    // Helpers

    private function sanitize(string $q): string
    {
        return trim(preg_replace('/[%_\\\\]/', '\\\\$0', $q));
    }

    private function emptyGlobalResult(): array
    {
        return [
            'users' => [], 'transactions' => [], 'tickets' => [],
            'withdrawals' => [], 'deposits' => [], 'ads' => [], 'total' => 0
        ];
    }

    private function emptyUserResult(): array
    {
        return [
            'transactions' => [], 'tickets' => [], 'ads' => [], 'tasks' => [], 'total' => 0
        ];
    }

    private function generateCacheKey(string $module, array $filters, int $limit, int $offset): string
    {
        $filterHash = md5(json_encode($filters));
        return "search:{$module}:{$filterHash}:{$limit}:{$offset}";
    }

    private function logSearch(string $type, string $query, ?int $userId): void
    {
        $this->logger->info('search.performed', [
            'type' => $type,
            'query' => $query,
            'user_id' => $userId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}