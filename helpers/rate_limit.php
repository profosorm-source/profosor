<?php

use Core\RateLimiter;
/**
 * Rate Limiting Helper Functions
 * 
 * توابع کمکی برای استفاده آسان از Rate Limiting
 */

if (!function_exists('get_rate_limit_config')) {
    /**
     * دریافت تنظیمات rate limit برای یک endpoint
     * 
     * @param string $group گروه (auth, financial, upload, etc.)
     * @param string $endpoint نام endpoint (login, deposit, etc.)
     * @return array تنظیمات rate limit
     */
    function get_rate_limit_config(string $group, string $endpoint = 'general'): array
    {
        $config = require __DIR__ . '/../config/rate_limits.php';
        
        // اگر گروه وجود داشت
        if (isset($config[$group][$endpoint])) {
            return $config[$group][$endpoint];
        }
        
        // اگر فقط گروه وجود داشت
        if (isset($config[$group]) && is_array($config[$group])) {
            return $config[$group];
        }
        
        // fallback به default
        return $config['default'];
    }
}

if (!function_exists('check_rate_limit')) {
    /**
     * بررسی rate limit برای یک کاربر/IP
     * 
     * @param string $key کلید منحصر به فرد (مثلاً auth:login:127.0.0.1)
     * @param array $config تنظیمات rate limit
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    function check_rate_limit(string $key, array $config): array
    {
        $limiter = new RateLimiter();

        $maxAttempts = (int)($config['max_attempts'] ?? 60);
        $decayMinutes = (int)($config['decay_minutes'] ?? 1);

        // اگر قبلاً سقف پر شده، دیگر تلاش جدید ثبت نکن
        $currentHits = $limiter->hits($key);
        if ($currentHits >= $maxAttempts) {
            $retryAfter = $limiter->availableIn($key);

            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => time() + $retryAfter,
                'retry_after' => $retryAfter,
                'message' => $config['message'] ?? 'تعداد درخواست‌ها بیش از حد مجاز است.',
            ];
        }

        // تلاش را ثبت کن
        $allowed = $limiter->attempt($key, $maxAttempts, $decayMinutes);
        $hitsAfter = $limiter->hits($key);
        $remaining = max(0, $maxAttempts - $hitsAfter);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => time() + ($decayMinutes * 60),
            'retry_after' => 0,
            'message' => null,
        ];
    }
}

if (!function_exists('rate_limit')) {
    /**
     * بررسی و اعمال rate limit با throw کردن exception
     * 
     * @param string $group گروه
     * @param string $endpoint نام endpoint
     * @param string|null $identifier شناسه کاربر (اگر null باشد از IP استفاده می‌شود)
     * @throws \Exception اگر rate limit رد شود
     */
    function rate_limit(string $group, string $endpoint = 'general', ?string $identifier = null): void
    {
        $config = get_rate_limit_config($group, $endpoint);
        
        // تولید کلید منحصر به فرد
        $identifier = $identifier ?? get_client_ip();
        $key = "{$group}:{$endpoint}:{$identifier}";
        
        $result = check_rate_limit($key, $config);
        
        if (!$result['allowed']) {
            if (function_exists('logger')) {
                try {
                    logger()->warning('Rate limit exceeded', [
                        'group' => $group,
                        'endpoint' => $endpoint,
                        'identifier' => $identifier,
                        'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                    ]);
                } catch (\Throwable $e) {
                    // logging must not break rate limit enforcement
                }
            }

            throw new \Exception($result['message'], 429);
        }
    }
}
