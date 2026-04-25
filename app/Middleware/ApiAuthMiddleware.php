<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Database;
use Core\RateLimiter;

/**
 * ApiAuthMiddleware — احراز هویت API
 *
 * Database از Container inject می‌شود (نه مستقیم)
 */
class ApiAuthMiddleware
{
   private Database $db;
   private RateLimiter $rateLimiter;

    public function __construct(Database $db, RateLimiter $rateLimiter)
{
    $this->db = $db;
    $this->rateLimiter = $rateLimiter;
}

    public function handle(Request $request, Response $response): bool
    {
        $token = $this->extractToken($request);

        if (!$token) {
            $this->unauthorized('توکن API ارائه نشده');
            return false;
        }

        $user = $this->validateToken($token);

        if (!$user) {
            $this->unauthorized('توکن نامعتبر یا منقضی شده');
            return false;
        }

        if ((int)($user->status ?? 1) !== 1) {
            $this->unauthorized('حساب کاربری غیرفعال است');
            return false;
        }

        // ✅ Rate Limiting برای API
        $rateLimitResult = $this->checkRateLimit($user->id, $request);
        if (!$rateLimitResult['allowed']) {
            $this->rateLimitExceeded($rateLimitResult);
            return false;
        }

        $request->setUser($user);

        // update based on token_id to avoid writing by raw token
        $this->db->query(
            "UPDATE api_tokens SET last_used_at = NOW(), use_count = use_count + 1 WHERE id = ?",
            [(int)$user->token_id]
        );

        return true;
    }
    
    /**
     * بررسی Rate Limit برای API
     */
    private function checkRateLimit(int $userId, Request $request): array
    {
        // استفاده از RateLimiter موجود
       $key = 'api:user:' . $userId;

// محدودیت: ۲۰۰ درخواست در دقیقه برای کاربران احراز هویت شده
$maxAttempts = (int)config('rate_limits.api.authenticated.max_attempts', 200);
$decayMinutes = (int)config('rate_limits.api.authenticated.decay_minutes', 1);

if (!$this->rateLimiter->attempt($key, $maxAttempts, $decayMinutes)) {
    return [
        'allowed' => false,
        'retry_after' => $this->rateLimiter->availableIn($key),
        'limit' => $maxAttempts,
        'remaining' => 0,
    ];
}

$remaining = $maxAttempts - $this->rateLimiter->hits($key);
        
        return [
            'allowed' => true,
            'limit' => $maxAttempts,
            'remaining' => max(0, $remaining),
            'reset_at' => time() + ($decayMinutes * 60),
        ];
    }
    
    /**
     * پاسخ برای Rate Limit Exceeded
     */
    private function rateLimitExceeded(array $result): void
    {
        http_response_code(429); // Too Many Requests
        
        // اضافه کردن هدرهای استاندارد Rate Limit
        header('X-RateLimit-Limit: ' . ($result['limit'] ?? 200));
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . (time() + ($result['retry_after'] ?? 60)));
        header('Retry-After: ' . ($result['retry_after'] ?? 60));
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'تعداد درخواست‌های شما از حد مجاز گذشته است.',
            'error'   => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $result['retry_after'] ?? 60,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function extractToken(Request $request): ?string
    {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        // Fallback for some SAPIs/proxies
        if ($authHeader === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                $authHeader = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
            }
        }

        if (!preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authHeader, $m)) {
            return null;
        }

        $token = trim((string)$m[1]);

        // Token format hardening (issue() uses bin2hex(random_bytes(32)))
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        return $token;
    }

    private function validateToken(string $token): ?object
    {
        return $this->db->fetch(
            "SELECT u.*, at.id AS token_id, at.scopes
             FROM api_tokens at
             JOIN users u ON u.id = at.user_id
             WHERE at.token = ?
               AND (at.expires_at IS NULL OR at.expires_at > NOW())
               AND at.revoked = 0
             LIMIT 1",
            [$token]
        ) ?: null;
    }

    private function unauthorized(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'error'   => 'UNAUTHORIZED',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}