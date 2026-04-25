<?php
namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\RateLimiter;

/**
 * Rate Limit Middleware
 *
 * محدودسازی تعداد درخواست‌ها با تنظیمات per-route
 *
 * BUG FIX:
 *  - Cache::getRedis() static وجود ندارد → از RateLimiter موجود (که خودش Redis/File را مدیریت می‌کند) استفاده می‌شود
 *  - new RateLimiter($redis) → RateLimiter بدون argument است
 *  - متد resolveLimit و resolveRequestSignature تعریف‌شده بودند اما استفاده نمی‌شدند
 */
class RateLimitMiddleware
{
    private RateLimiter $rateLimiter;
    private int         $maxAttempts;
    private int         $decayMinutes;

    /**
     * محدودیت‌های per-route: [max_attempts, decay_minutes]
     */
    private const ROUTE_LIMITS = [
        '/login'            => [5,   5],
        '/admin/login'      => [3,  10],
        '/register'         => [3,  30],
        '/forgot-password'  => [3,  60],
        '/reset-password'   => [3,  60],
        '/payment'          => [10,  1],
        '/withdrawal'       => [5,  60],
        '/deposit'          => [10, 60],
        '/kyc'              => [5,  60],
        '/api/'             => [100,  1],
        '/notifications/poll' => [60, 1], // long polling — یک بار در ثانیه کافی است
    ];

    public function __construct(int $maxAttempts = 60, int $decayMinutes = 1)
    {
        // RateLimiter بدون argument — به‌صورت خودکار Redis یا File انتخاب می‌کند
        $this->rateLimiter  = new RateLimiter();
        $this->maxAttempts  = $maxAttempts;
        $this->decayMinutes = $decayMinutes;
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        try {
            [$maxAttempts, $decayMinutes] = $this->resolveLimit($request);
            $key = $this->resolveRequestSignature($request);

            if (!$this->rateLimiter->attempt($key, $maxAttempts, $decayMinutes)) {
                $retryAfter = $this->rateLimiter->availableIn($key);

                $this->logger->warning('Rate limit exceeded', [
                    'ip'          => get_client_ip(),
                    'uri'         => $request->uri(),
                    'retry_after' => $retryAfter,
                ]);

                $response = new Response();
                return $response->json([
                    'success'     => false,
                    'message'     => 'تعداد درخواست‌های شما بیش از حد مجاز است.',
                    'retry_after' => $retryAfter,
                ], 429)
                ->header('Retry-After', (string)$retryAfter)
                ->header('X-RateLimit-Limit', (string)$maxAttempts)
                ->header('X-RateLimit-Remaining', '0');
            }

            $remaining = max(0, $maxAttempts - $this->rateLimiter->hits($key));
            $response  = $next($request);

            if ($response instanceof Response) {
                $response
                    ->header('X-RateLimit-Limit',     (string)$maxAttempts)
                    ->header('X-RateLimit-Remaining', (string)$remaining);
            }

            return $response;

        } catch (\Throwable $e) {
    $this->logger->error('middleware.rate_limit.failed', [
        'channel' => 'security',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    // در صورت خطا، درخواست را رد نمی‌کنیم
    return $next($request);
}
    }

    private function resolveLimit(Request $request): array
    {
        $uri = $request->uri();

        foreach (self::ROUTE_LIMITS as $pattern => $limits) {
            if (str_contains($uri, $pattern)) {
                return $limits;
            }
        }

        return [$this->maxAttempts, $this->decayMinutes];
    }

    private function resolveRequestSignature(Request $request): string
    {
        $uri    = $request->uri();
        $userId = app()->session->get('user_id');

        if ($userId) {
            return 'rl_user_' . $userId . '_' . md5($uri);
        }

        return 'rl_ip_' . sha1(get_client_ip()) . '_' . md5($uri);
    }
}
