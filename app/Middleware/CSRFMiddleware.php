<?php
namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\CSRF;

/**
 * CSRF Middleware
 * 
 * بررسی CSRF Token
 */
class CSRFMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        // فقط برای POST, PUT, DELETE, PATCH
        if (!in_array($request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return true;
        }

        if (!CSRF::check()) {
   if (function_exists('logger')) {
    try {
        logger()->warning('CSRF token validation failed', [
            'channel' => 'security',
            'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
            'uri' => $request->uri(),
            'method' => $request->method(),
        ]);
    } catch (\Throwable $e) {
        // do not break security flow on logger failure
    }
}

            if (function_exists('is_ajax') && is_ajax()) {
                return $response->error('توکن امنیتی نامعتبر است. لطفاً صفحه را رفرش کنید.', [], 403);
            }

            abort(403, 'توکن امنیتی نامعتبر است.');
        }

        return true;
    }
}