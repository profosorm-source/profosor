<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;

/**
 * Security Headers Middleware
 * 
 * اضافه کردن هدرهای امنیتی ضروری به تمام پاسخ‌های HTTP
 * این middleware باید در اول middleware stack قرار بگیرد
 */
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        $env = config('app.env', env('APP_ENV', 'production'));
        
        // ═══════════════════════════════════════════════════════
        // Content Security Policy (CSP)
        // ═══════════════════════════════════════════════════════
        $csp = $this->buildCSP($env);
        header("Content-Security-Policy: {$csp}");
        
        // ═══════════════════════════════════════════════════════
        // X-Frame-Options - جلوگیری از Clickjacking
        // ═══════════════════════════════════════════════════════
        header('X-Frame-Options: SAMEORIGIN');
        
        // ═══════════════════════════════════════════════════════
        // X-Content-Type-Options - جلوگیری از MIME Sniffing
        // ═══════════════════════════════════════════════════════
        header('X-Content-Type-Options: nosniff');
        
        // ═══════════════════════════════════════════════════════
        // X-XSS-Protection - محافظت در برابر XSS (برای مرورگرهای قدیمی)
        // ═══════════════════════════════════════════════════════
        header('X-XSS-Protection: 1; mode=block');
        
        // ═══════════════════════════════════════════════════════
        // Referrer-Policy - کنترل اطلاعات Referer
        // ═══════════════════════════════════════════════════════
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // ═══════════════════════════════════════════════════════
        // Permissions-Policy (Feature Policy)
        // ═══════════════════════════════════════════════════════
        $permissionsPolicy = implode(', ', [
            'camera=()',
            'microphone=()',
            'geolocation=(self)',
            'payment=(self)',
            'usb=()',
            'magnetometer=()',
            'gyroscope=()',
            'accelerometer=()'
        ]);
        header("Permissions-Policy: {$permissionsPolicy}");
        
        // ═══════════════════════════════════════════════════════
        // HSTS (فقط در production و با HTTPS)
        // ═══════════════════════════════════════════════════════
        if ($env === 'production' && $this->isSecure($request)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        
        // ═══════════════════════════════════════════════════════
        // Cross-Origin Policies
        // ═══════════════════════════════════════════════════════
        header('Cross-Origin-Embedder-Policy: require-corp');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        
        // ═══════════════════════════════════════════════════════
        // Server Header - پنهان کردن اطلاعات سرور
        // ═══════════════════════════════════════════════════════
        header_remove('X-Powered-By');
        header('Server: Chortke');
        
        return true;
    }
    
    /**
     * ساخت Content Security Policy
     */
    private function buildCSP(string $env): string
    {
        $nonce = $this->generateNonce();
        
        // در development، CSP کمی شل‌تر است
        if ($env === 'development') {
            return implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: https: blob:",
                "connect-src 'self' https://api.chortke.ir",
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "form-action 'self'"
            ]);
        }
        
        // در production، CSP سخت‌گیرانه‌تر
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "upgrade-insecure-requests"
        ]);
    }
    
    /**
     * تولید Nonce برای CSP
     */
    private function generateNonce(): string
    {
        if (!isset($_SESSION['csp_nonce'])) {
            $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
        }
        return $_SESSION['csp_nonce'];
    }
    
    /**
     * بررسی اینکه درخواست از طریق HTTPS است یا نه
     */
    private function isSecure(Request $request): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        }
        
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        
        // بررسی X-Forwarded-Proto فقط از IP‌های معتبر proxy
        $trustedProxies = array_filter(array_map('trim', explode(',', (string)env('TRUSTED_PROXIES', ''))));
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (!empty($trustedProxies) && in_array($remoteIp, $trustedProxies, true)) {
            $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            if (strtolower($forwardedProto) === 'https') {
                return true;
            }
        }
        
        return false;
    }
}
