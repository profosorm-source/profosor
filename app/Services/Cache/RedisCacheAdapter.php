<?php

namespace App\Services\Cache;

/**
 * Redis Cache Adapter برای Feature Flags
 * 
 * این کلاس یک لایه Cache مشترک بین تمام instance های برنامه ایجاد می‌کند
 */
class RedisCacheAdapter
{
    private ?\Redis $redis = null;
    private bool $enabled = false;
    private string $prefix = 'ff:';  // feature flag prefix
    private int $defaultTTL = 300;   // 5 minutes
    
    public function __construct()
    {
        $this->enabled = extension_loaded('redis') && 
                        env('REDIS_ENABLED', false);
        
        if ($this->enabled) {
            $this->connect();
        }
    }
    
    /**
     * اتصال به Redis
     */
    private function connect(): void
    {
        try {
            $this->redis = new \Redis();
            
            $host = env('REDIS_HOST', '127.0.0.1');
            $port = env('REDIS_PORT', 6379);
            $timeout = env('REDIS_TIMEOUT', 2.5);
            
            $connected = $this->redis->connect($host, $port, $timeout);
            
            if (!$connected) {
                $this->enabled = false;
                return;
            }
            
            // Auth اگر نیاز بود
            $password = env('REDIS_PASSWORD', null);
            if ($password) {
                $this->redis->auth($password);
            }
            
            // انتخاب Database
            $database = env('REDIS_DATABASE', 0);
            $this->redis->select($database);
            
            // تنظیمات بهینه
            $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
            
        } catch (\Exception $e) {
            $this->enabled = false;
            error_log("Redis connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * دریافت از Cache
     */
    public function get(string $key)
    {
        if (!$this->enabled) {
            return null;
        }
        
        try {
            $value = $this->redis->get($key);
            return $value === false ? null : $value;
        } catch (\Exception $e) {
            error_log("Redis get failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * ذخیره در Cache
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $ttl = $ttl ?? $this->defaultTTL;
            return $this->redis->setex($key, $ttl, $value);
        } catch (\Exception $e) {
            error_log("Redis set failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * حذف از Cache
     */
    public function delete(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            return $this->redis->del($key) > 0;
        } catch (\Exception $e) {
            error_log("Redis delete failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * پاک کردن تمام Cache های Feature Flag
     */
    public function flush(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            // پاک کردن تمام کلیدهای با prefix ff:
            $keys = $this->redis->keys('*');
            
            if (empty($keys)) {
                return true;
            }
            
            return $this->redis->del($keys) > 0;
        } catch (\Exception $e) {
            error_log("Redis flush failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ذخیره چندین کلید به صورت یکجا
     */
    public function setMultiple(array $items, ?int $ttl = null): bool
    {
        if (!$this->enabled || empty($items)) {
            return false;
        }
        
        try {
            $ttl = $ttl ?? $this->defaultTTL;
            $pipeline = $this->redis->multi(\Redis::PIPELINE);
            
            foreach ($items as $key => $value) {
                $pipeline->setex($key, $ttl, $value);
            }
            
            $pipeline->exec();
            return true;
        } catch (\Exception $e) {
            error_log("Redis setMultiple failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * دریافت چندین کلید به صورت یکجا
     */
    public function getMultiple(array $keys): array
    {
        if (!$this->enabled || empty($keys)) {
            return [];
        }
        
        try {
            $values = $this->redis->mGet($keys);
            
            $result = [];
            foreach ($keys as $i => $key) {
                if ($values[$i] !== false) {
                    $result[$key] = $values[$i];
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log("Redis getMultiple failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * افزایش مقدار (برای Counters)
     */
    public function increment(string $key, int $value = 1): int
    {
        if (!$this->enabled) {
            return 0;
        }
        
        try {
            return $this->redis->incrBy($key, $value);
        } catch (\Exception $e) {
            error_log("Redis increment failed: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * بررسی وجود کلید
     */
    public function exists(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            return $this->redis->exists($key) > 0;
        } catch (\Exception $e) {
            error_log("Redis exists failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * تنظیم Expire برای کلید موجود
     */
    public function expire(string $key, int $seconds): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            return $this->redis->expire($key, $seconds);
        } catch (\Exception $e) {
            error_log("Redis expire failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * دریافت TTL باقی‌مانده
     */
    public function ttl(string $key): int
    {
        if (!$this->enabled) {
            return -1;
        }
        
        try {
            return $this->redis->ttl($key);
        } catch (\Exception $e) {
            error_log("Redis ttl failed: " . $e->getMessage());
            return -1;
        }
    }
    
    /**
     * آیا Redis فعال است؟
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * دریافت آمار Redis
     */
    public function getStats(): array
    {
        if (!$this->enabled) {
            return ['enabled' => false];
        }
        
        try {
            $info = $this->redis->info();
            
            return [
                'enabled' => true,
                'connected' => $this->redis->ping() === '+PONG',
                'used_memory' => $info['used_memory_human'] ?? 'N/A',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_commands' => $info['total_commands_processed'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info),
            ];
        } catch (\Exception $e) {
            return [
                'enabled' => true,
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * محاسبه Hit Rate
     */
    private function calculateHitRate(array $info): string
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        
        if ($total === 0) {
            return '0%';
        }
        
        $rate = ($hits / $total) * 100;
        return round($rate, 2) . '%';
    }
    
    /**
     * بستن اتصال
     */
    public function __destruct()
    {
        if ($this->enabled && $this->redis) {
            try {
                $this->redis->close();
            } catch (\Exception $e) {
                // Silent fail
            }
        }
    }
}
