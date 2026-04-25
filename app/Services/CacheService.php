<?php

declare(strict_types=1);

namespace App\Services;

use Core\Redis;
use Core\Logger;

/**
 * CacheService - Redis-based caching layer for performance
 * 
 * Caches:
 * - Search results (SearchService output)
 * - Profile data (Influencer, SocialAd)
 * - Settings & configuration
 * - Rate limit counters
 * - User preferences
 * 
 * TTL Strategy:
 * - Search results: 5 minutes (frequently changing)
 * - Profiles: 15 minutes (less frequent changes)
 * - Settings: 1 hour (rarely change)
 * - Counters: 1 minute (real-time tracking)
 */
class CacheService
{
    private Redis $redis;
    private Logger $logger;

    // Cache TTLs (seconds)
    private const TTL_SEARCH = 300;      // 5 minutes
    private const TTL_PROFILE = 900;     // 15 minutes
    private const TTL_SETTINGS = 3600;   // 1 hour
    private const TTL_COUNTER = 60;      // 1 minute
    private const TTL_USER = 1800;       // 30 minutes

    public function __construct(Redis $redis, Logger $logger)
    {
        $this->redis = $redis;
        $this->logger = $logger;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Search Results Caching
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get cached search results
     * 
     * @param string $module Module name (social_task, influencer, vitrine)
     * @param string $query Search query
     * @param array $filters Additional filters
     * @param int $offset Pagination offset
     * @return array|null Cached results or null
     */
    public function getSearchResults(string $module, string $query, array $filters = [], int $offset = 0): ?array
    {
        $key = $this->searchCacheKey($module, $query, $filters, $offset);
        $cached = $this->redis->get($key);
        
        if ($cached) {
            $this->logger->debug('cache.search.hit', ['module' => $module, 'query' => $query]);
            return json_decode($cached, true);
        }

        return null;
    }

    /**
     * Cache search results
     */
    public function setSearchResults(string $module, string $query, array $filters, int $offset, array $results): void
    {
        $key = $this->searchCacheKey($module, $query, $filters, $offset);
        $this->redis->setex($key, self::TTL_SEARCH, json_encode($results));
        
        $this->logger->debug('cache.search.set', ['module' => $module, 'query' => $query, 'ttl' => self::TTL_SEARCH]);
    }

    /**
     * Invalidate all search cache for a module
     */
    public function invalidateModuleSearch(string $module): void
    {
        $pattern = "search:{$module}:*";
        $keys = $this->redis->keys($pattern);
        
        if (!empty($keys)) {
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
            $this->logger->info('cache.search.invalidated', ['module' => $module, 'count' => count($keys)]);
        }
    }

    private function searchCacheKey(string $module, string $query, array $filters, int $offset): string
    {
        $hash = md5(json_encode(['q' => $query, 'f' => $filters, 'o' => $offset]));
        return "search:{$module}:{$hash}";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Profile Caching
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get cached influencer profile
     */
    public function getInfluencerProfile(int $profileId): ?array
    {
        $key = "profile:influencer:{$profileId}";
        $cached = $this->redis->get($key);
        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Cache influencer profile
     */
    public function setInfluencerProfile(int $profileId, array $profile): void
    {
        $key = "profile:influencer:{$profileId}";
        $this->redis->setex($key, self::TTL_PROFILE, json_encode($profile));
    }

    /**
     * Invalidate influencer profile cache
     */
    public function invalidateInfluencerProfile(int $profileId): void
    {
        $this->redis->del("profile:influencer:{$profileId}");
    }

    /**
     * Get cached social ad
     */
    public function getSocialAd(int $adId): ?array
    {
        $key = "profile:ad:{$adId}";
        $cached = $this->redis->get($key);
        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Cache social ad
     */
    public function setSocialAd(int $adId, array $ad): void
    {
        $key = "profile:ad:{$adId}";
        $this->redis->setex($key, self::TTL_PROFILE, json_encode($ad));
    }

    /**
     * Invalidate social ad cache
     */
    public function invalidateSocialAd(int $adId): void
    {
        $this->redis->del("profile:ad:{$adId}");
    }

    /**
     * Get cached vitrine listing
     */
    public function getVitrineListing(int $listingId): ?array
    {
        $key = "profile:vitrine:{$listingId}";
        $cached = $this->redis->get($key);
        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Cache vitrine listing
     */
    public function setVitrineListing(int $listingId, array $listing): void
    {
        $key = "profile:vitrine:{$listingId}";
        $this->redis->setex($key, self::TTL_PROFILE, json_encode($listing));
    }

    /**
     * Invalidate vitrine listing cache
     */
    public function invalidateVitrineListing(int $listingId): void
    {
        $this->redis->del("profile:vitrine:{$listingId}");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Settings & Configuration Caching
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get cached setting
     */
    public function getSetting(string $key, $default = null)
    {
        $cacheKey = "setting:{$key}";
        $cached = $this->redis->get($cacheKey);
        
        return $cached ? json_decode($cached, true) : $default;
    }

    /**
     * Cache setting
     */
    public function setSetting(string $key, $value): void
    {
        $cacheKey = "setting:{$key}";
        $this->redis->setex($cacheKey, self::TTL_SETTINGS, json_encode($value));
    }

    /**
     * Invalidate all settings cache
     */
    public function invalidateAllSettings(): void
    {
        $keys = $this->redis->keys('setting:*');
        if (!empty($keys)) {
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Counter Operations (Atomic)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Increment counter (for rate limiting, stats, etc.)
     */
    public function incrementCounter(string $key, int $amount = 1, ?int $ttl = null): int
    {
        $ttl = $ttl ?? self::TTL_COUNTER;
        
        $current = (int)($this->redis->get($key) ?? 0);
        $newValue = $current + $amount;
        
        $this->redis->setex($key, $ttl, $newValue);
        return $newValue;
    }

    /**
     * Get counter value
     */
    public function getCounter(string $key): int
    {
        return (int)($this->redis->get($key) ?? 0);
    }

    /**
     * Reset counter
     */
    public function resetCounter(string $key): void
    {
        $this->redis->del($key);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // User Preferences Caching
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get cached user preferences
     */
    public function getUserPreferences(int $userId): ?array
    {
        $key = "user:prefs:{$userId}";
        $cached = $this->redis->get($key);
        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Cache user preferences
     */
    public function setUserPreferences(int $userId, array $prefs): void
    {
        $key = "user:prefs:{$userId}";
        $this->redis->setex($key, self::TTL_USER, json_encode($prefs));
    }

    /**
     * Invalidate user preferences
     */
    public function invalidateUserPreferences(int $userId): void
    {
        $this->redis->del("user:prefs:{$userId}");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Generic Cache Operations
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get any cached value
     */
    public function get(string $key)
    {
        $value = $this->redis->get($key);
        return $value ? json_decode($value, true) : null;
    }

    /**
     * Set any value in cache
     */
    public function set(string $key, $value, int $ttl = self::TTL_PROFILE): void
    {
        $this->redis->setex($key, $ttl, json_encode($value));
    }

    /**
     * Delete key from cache
     */
    public function delete(string $key): void
    {
        $this->redis->del($key);
    }

    /**
     * Clear all cache
     */
    public function flush(): void
    {
        $this->redis->flushDb();
        $this->logger->warning('cache.flush.all', ['note' => 'All cache cleared']);
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $info = $this->redis->info();
        return [
            'used_memory' => $info['used_memory'] ?? 0,
            'connected_clients' => $info['connected_clients'] ?? 0,
            'keys_count' => $this->redis->dbSize(),
        ];
    }
}
