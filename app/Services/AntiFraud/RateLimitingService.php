<?php

namespace App\Services\AntiFraud;

use App\Services\RiskPolicyService;
use Core\Database;
use Core\Logger;

/**
 * RateLimitingService
 * 
 * سیستم محدودسازی پیشرفته با الگوریتم Token Bucket و Sliding Window
 * 
 * Features:
 * - Rate limiting per IP, User, Device, API Endpoint
 * - Token Bucket algorithm for smooth rate limiting
 * - Sliding Window for accurate time-based limits
 * - Dynamic rate adjustment based on risk score
 * - Whitelist/Blacklist support
 */
class RateLimitingService
{
    private Database $db;
    private RiskPolicyService $policy;
    private Logger $logger;
    
    // Cache برای جلوگیری از query های مکرر
    private array $cache = [];
    private int $cacheTtl = 60; // 60 seconds

    public function __construct(
        Database $db,
        RiskPolicyService $policy,
        Logger $logger
    ) {
        $this->db = $db;
        $this->policy = $policy;
        $this->logger = $logger;
    }

    /**
     * بررسی Rate Limit با الگوریتم Token Bucket
     * 
     * @param string $key شناسه یکتا (IP, UserID, etc.)
     * @param string $action نوع عملیات (login, api_call, task_execute, etc.)
     * @param int|null $cost هزینه توکن (پیش‌فرض 1)
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    public function checkTokenBucket(
        string $key,
        string $action,
        ?int $cost = 1
    ): array {
        $config = $this->getRateLimitConfig($action);
        $bucketKey = "rate_limit:token_bucket:{$action}:{$key}";
        
        // دریافت bucket فعلی
        $bucket = $this->getBucket($bucketKey, $config);
        
        $now = time();
        $timePassed = $now - $bucket['last_refill'];
        
        // پر کردن توکن‌های جدید
        $tokensToAdd = floor($timePassed * $config['refill_rate']);
        $bucket['tokens'] = min(
            $config['capacity'],
            $bucket['tokens'] + $tokensToAdd
        );
        $bucket['last_refill'] = $now;
        
        // بررسی امکان مصرف
        $allowed = $bucket['tokens'] >= $cost;
        
        if ($allowed) {
            $bucket['tokens'] -= $cost;
        } else {
            // لاگ محدودیت
            $this->logRateLimit($key, $action, 'token_bucket', [
                'cost' => $cost,
                'available' => $bucket['tokens'],
                'capacity' => $config['capacity']
            ]);
        }
        
        // ذخیره bucket
        $this->saveBucket($bucketKey, $bucket, $config['window']);
        
        $resetAt = $now + ceil(($cost - $bucket['tokens']) / $config['refill_rate']);
        
        return [
            'allowed' => $allowed,
            'remaining' => (int)$bucket['tokens'],
            'capacity' => $config['capacity'],
            'reset_at' => $resetAt,
            'retry_after' => $allowed ? 0 : ($resetAt - $now)
        ];
    }

    /**
     * بررسی Rate Limit با الگوریتم Sliding Window
     * 
     * دقیق‌تر از Fixed Window و کارآمدتر از Token Bucket برای محدودیت‌های سخت‌گیرانه
     */
    public function checkSlidingWindow(
        string $key,
        string $action,
        ?int $increment = 1
    ): array {
        $config = $this->getRateLimitConfig($action);
        $now = time();
        $windowStart = $now - $config['window'];
        
        // پاک‌سازی رکوردهای قدیمی
        $this->db->query(
            "DELETE FROM rate_limit_requests 
             WHERE identifier_key = ? 
             AND action = ? 
             AND created_at < ?",
            [$key, $action, date('Y-m-d H:i:s', $windowStart)]
        );
        
        // شمارش درخواست‌های فعلی
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count 
             FROM rate_limit_requests 
             WHERE identifier_key = ? 
             AND action = ? 
             AND created_at >= ?",
            [$key, $action, date('Y-m-d H:i:s', $windowStart)]
        );
        
        $currentCount = $result ? (int)$result->count : 0;
        $limit = $config['limit'];
        
        $allowed = ($currentCount + $increment) <= $limit;
        
        if ($allowed) {
            // ثبت درخواست جدید
            for ($i = 0; $i < $increment; $i++) {
                $this->db->query(
                    "INSERT INTO rate_limit_requests 
                     (identifier_key, action, created_at) 
                     VALUES (?, ?, NOW())",
                    [$key, $action]
                );
            }
        } else {
            // لاگ محدودیت
            $this->logRateLimit($key, $action, 'sliding_window', [
                'current_count' => $currentCount,
                'limit' => $limit,
                'window' => $config['window']
            ]);
        }
        
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $limit - $currentCount - ($allowed ? $increment : 0)),
            'limit' => $limit,
            'reset_at' => $now + $config['window'],
            'window' => $config['window']
        ];
    }

    /**
     * بررسی محدودیت ترکیبی (Multi-dimensional)
     * 
     * مثال: محدودیت همزمان روی IP، User و Endpoint
     */
    public function checkComposite(array $limits): array
    {
        $results = [];
        $overallAllowed = true;
        $strictestResetAt = 0;
        
        foreach ($limits as $limitConfig) {
            $key = $limitConfig['key'];
            $action = $limitConfig['action'];
            $method = $limitConfig['method'] ?? 'sliding_window';
            $cost = $limitConfig['cost'] ?? 1;
            
            if ($method === 'token_bucket') {
                $result = $this->checkTokenBucket($key, $action, $cost);
            } else {
                $result = $this->checkSlidingWindow($key, $action, $cost);
            }
            
            $results[$key] = $result;
            
            if (!$result['allowed']) {
                $overallAllowed = false;
            }
            
            $strictestResetAt = max($strictestResetAt, $result['reset_at']);
        }
        
        return [
            'allowed' => $overallAllowed,
            'details' => $results,
            'reset_at' => $strictestResetAt
        ];
    }

    /**
     * Rate limit پویا بر اساس Risk Score
     */
    public function checkDynamic(
        string $key,
        string $action,
        int $riskScore
    ): array {
        $baseConfig = $this->getRateLimitConfig($action);
        
        // تنظیم پویا بر اساس ریسک
        if ($riskScore >= 80) {
            // کاربران پرخطر: 10% محدودیت
            $adjustedLimit = (int)($baseConfig['limit'] * 0.1);
            $adjustedAction = $action . ':high_risk';
        } elseif ($riskScore >= 50) {
            // کاربران متوسط: 50% محدودیت
            $adjustedLimit = (int)($baseConfig['limit'] * 0.5);
            $adjustedAction = $action . ':medium_risk';
        } else {
            // کاربران عادی: محدودیت کامل
            $adjustedLimit = $baseConfig['limit'];
            $adjustedAction = $action;
        }
        
        // استفاده از sliding window با محدودیت تنظیم شده
        $result = $this->checkSlidingWindow($key, $adjustedAction, 1);
        
        // اضافه کردن اطلاعات risk
        $result['risk_score'] = $riskScore;
        $result['adjusted_limit'] = $adjustedLimit;
        $result['original_limit'] = $baseConfig['limit'];
        
        return $result;
    }

    /**
     * بررسی Whitelist/Blacklist
     */
    public function isWhitelisted(string $key, string $type = 'ip'): bool
    {
        $cacheKey = "whitelist:{$type}:{$key}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count 
             FROM rate_limit_whitelist 
             WHERE identifier = ? 
             AND identifier_type = ? 
             AND is_active = 1",
            [$key, $type]
        );
        
        $isWhitelisted = $result && (int)$result->count > 0;
        $this->cache[$cacheKey] = $isWhitelisted;
        
        return $isWhitelisted;
    }

    public function isBlacklisted(string $key, string $type = 'ip'): bool
    {
        $cacheKey = "blacklist:{$type}:{$key}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count 
             FROM rate_limit_blacklist 
             WHERE identifier = ? 
             AND identifier_type = ? 
             AND is_active = 1 
             AND (expires_at IS NULL OR expires_at > NOW())",
            [$key, $type]
        );
        
        $isBlacklisted = $result && (int)$result->count > 0;
        $this->cache[$cacheKey] = $isBlacklisted;
        
        return $isBlacklisted;
    }

    /**
     * اضافه کردن به Whitelist
     */
    public function addToWhitelist(string $key, string $type, string $reason): void
    {
        $sql = "INSERT INTO rate_limit_whitelist 
                (identifier, identifier_type, reason, created_at) 
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                reason = VALUES(reason), 
                updated_at = NOW()";
        
        $this->db->query($sql, [$key, $type, $reason]);
        unset($this->cache["whitelist:{$type}:{$key}"]);
        
        $this->logger->info('rate_limit.whitelist.added', [
            'key' => $key,
            'type' => $type,
            'reason' => $reason
        ]);
    }

    /**
     * اضافه کردن به Blacklist
     */
    public function addToBlacklist(
        string $key,
        string $type,
        string $reason,
        ?int $duration = null
    ): void {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        
        $sql = "INSERT INTO rate_limit_blacklist 
                (identifier, identifier_type, reason, expires_at, created_at) 
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                reason = VALUES(reason), 
                expires_at = VALUES(expires_at),
                updated_at = NOW()";
        
        $this->db->query($sql, [$key, $type, $reason, $expiresAt]);
        unset($this->cache["blacklist:{$type}:{$key}"]);
        
        $this->logger->warning('rate_limit.blacklist.added', [
            'key' => $key,
            'type' => $type,
            'reason' => $reason,
            'duration' => $duration
        ]);
    }

    /**
     * دریافت تنظیمات Rate Limit
     */
    private function getRateLimitConfig(string $action): array
    {
        // تنظیمات پیش‌فرض برای هر action
        $defaults = [
            'login' => [
                'limit' => 5,
                'window' => 300, // 5 minutes
                'capacity' => 10,
                'refill_rate' => 0.033 // ~1 token per 30 seconds
            ],
            'api_call' => [
                'limit' => 100,
                'window' => 60, // 1 minute
                'capacity' => 100,
                'refill_rate' => 1.67 // ~100 tokens per minute
            ],
            'task_execute' => [
                'limit' => 10,
                'window' => 3600, // 1 hour
                'capacity' => 10,
                'refill_rate' => 0.0028 // ~10 per hour
            ],
            'withdrawal' => [
                'limit' => 3,
                'window' => 86400, // 24 hours
                'capacity' => 3,
                'refill_rate' => 0.000035 // ~3 per day
            ],
            'registration' => [
                'limit' => 1,
                'window' => 3600, // 1 hour
                'capacity' => 1,
                'refill_rate' => 0.00028
            ]
        ];
        
        // بررسی تنظیمات سفارشی از پالیسی
        $customLimit = $this->policy->getInt('rate_limit', "{$action}.limit", null);
        $customWindow = $this->policy->getInt('rate_limit', "{$action}.window", null);
        
        $config = $defaults[$action] ?? $defaults['api_call'];
        
        if ($customLimit !== null) {
            $config['limit'] = $customLimit;
            $config['capacity'] = $customLimit;
        }
        
        if ($customWindow !== null) {
            $config['window'] = $customWindow;
            $config['refill_rate'] = $config['capacity'] / $config['window'];
        }
        
        return $config;
    }

    /**
     * دریافت Token Bucket
     */
    private function getBucket(string $key, array $config): array
    {
        $result = $this->db->fetch(
            "SELECT tokens, last_refill 
             FROM rate_limit_buckets 
             WHERE bucket_key = ?",
            [$key]
        );
        
        if ($result) {
            return [
                'tokens' => (float)$result->tokens,
                'last_refill' => (int)strtotime($result->last_refill)
            ];
        }
        
        // Bucket جدید
        return [
            'tokens' => (float)$config['capacity'],
            'last_refill' => time()
        ];
    }

    /**
     * ذخیره Token Bucket
     */
    private function saveBucket(string $key, array $bucket, int $ttl): void
    {
        $sql = "INSERT INTO rate_limit_buckets 
                (bucket_key, tokens, last_refill, expires_at) 
                VALUES (?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE 
                tokens = VALUES(tokens), 
                last_refill = VALUES(last_refill),
                expires_at = VALUES(expires_at)";
        
        $this->db->query($sql, [
            $key,
            $bucket['tokens'],
            $bucket['last_refill'],
            time() + $ttl
        ]);
    }

    /**
     * لاگ محدودیت
     */
    private function logRateLimit(
        string $key,
        string $action,
        string $method,
        array $details
    ): void {
        $this->db->query(
            "INSERT INTO rate_limit_violations 
             (identifier_key, action, method, details, created_at) 
             VALUES (?, ?, ?, ?, NOW())",
            [$key, $action, $method, json_encode($details, JSON_UNESCAPED_UNICODE)]
        );
        
        $this->logger->warning('rate_limit.exceeded', [
            'key' => $key,
            'action' => $action,
            'method' => $method,
            'details' => $details
        ]);
    }

    /**
     * دریافت آمار Rate Limit
     */
    public function getStats(string $key, string $action, int $period = 3600): array
    {
        $since = date('Y-m-d H:i:s', time() - $period);
        
        // تعداد محدودیت‌ها
        $violations = $this->db->fetch(
            "SELECT COUNT(*) as count 
             FROM rate_limit_violations 
             WHERE identifier_key = ? 
             AND action = ? 
             AND created_at >= ?",
            [$key, $action, $since]
        );
        
        // تعداد درخواست‌ها
        $requests = $this->db->fetch(
            "SELECT COUNT(*) as count 
             FROM rate_limit_requests 
             WHERE identifier_key = ? 
             AND action = ? 
             AND created_at >= ?",
            [$key, $action, $since]
        );
        
        return [
            'total_requests' => $requests ? (int)$requests->count : 0,
            'total_violations' => $violations ? (int)$violations->count : 0,
            'period_seconds' => $period,
            'violation_rate' => $requests && $requests->count > 0
                ? round(($violations->count / $requests->count) * 100, 2)
                : 0
        ];
    }

    /**
     * Reset کردن محدودیت برای یک کاربر (Admin action)
     */
    public function reset(string $key, string $action): void
    {
        // حذف bucket
        $this->db->query(
            "DELETE FROM rate_limit_buckets 
             WHERE bucket_key LIKE ?",
            ["%{$action}:{$key}"]
        );
        
        // حذف requests
        $this->db->query(
            "DELETE FROM rate_limit_requests 
             WHERE identifier_key = ? AND action = ?",
            [$key, $action]
        );
        
        $this->logger->info('rate_limit.reset', [
            'key' => $key,
            'action' => $action
        ]);
    }

    /**
     * پاکسازی رکوردهای منقضی شده
     */
    public function cleanup(): int
    {
        $deleted = 0;
        
        // پاک کردن buckets منقضی شده
        $result = $this->db->query(
            "DELETE FROM rate_limit_buckets 
             WHERE expires_at < NOW()"
        );
        $deleted += $result ? 1 : 0;
        
        // پاک کردن requests قدیمی (بیش از 24 ساعت)
        $result = $this->db->query(
            "DELETE FROM rate_limit_requests 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $deleted += $result ? 1 : 0;
        
        // پاک کردن violations قدیمی (بیش از 7 روز)
        $result = $this->db->query(
            "DELETE FROM rate_limit_violations 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $deleted += $result ? 1 : 0;
        
        return $deleted;
    }
}
