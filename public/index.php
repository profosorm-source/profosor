<?php

/**
 * چرتکه (Chortke) — Entry Point
 *
 * ترتیب صحیح boot:
 *   ۱. BASE_PATH + ob_start + timezone
 *   ۲. بارگذاری .env
 *   ۳. Security Headers
 *   ۴. Autoloader (Core\Autoloader)
 *   ۵. Helpers
 *   ۶. Application::getInstance()   ← همه چیز از اینجا شروع می‌شه
 *        └─ ExceptionHandler (یک‌بار)
 *        └─ Session::getInstance + start()
 *        └─ Database
 *        └─ Container + registerCoreBindings
 *        └─ Maintenance check
 *   ۷. Routes
 *   ۸. Application::run()
 */

// ── ۱. پایه ─────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('VIEW_PATH', BASE_PATH . '/views');

ob_start();
ob_implicit_flush(false);

date_default_timezone_set('Asia/Tehran');

// ── ۲. بارگذاری .env ────────────────────────────────────────────
$envPath = BASE_PATH . '/.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if ($env === false) {
        die('.env file is invalid');
    }
    $appDebug = filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($appDebug) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        error_reporting(0);
        ini_set('display_errors', '0');
    }
} else {
    // قبل از لود config، حداقل خطاها رو نشان بده
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// ── ۳. Security Headers & HTTPS Enforcement ──────────────────────
// ✅ HTTPS Enforcement - production میں required ہے
$isProduction = ($env['APP_ENV'] ?? 'production') === 'production';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
           (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

if ($isProduction && !$isHttps && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // ✅ Redirect to HTTPS
    $url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $url, true, 301);
    exit;
} elseif ($isProduction && !$isHttps) {
    // POST/PUT/DELETE over HTTP in production - reject
    http_response_code(403);
    die('HTTPS required');
}

// ✅ Strict security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// ✅ HSTS - HTTP Strict Transport Security (production only)
if ($isProduction && $isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// ✅ CORS - single source of truth (no wildcard with credentials)
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOriginsRaw = (string)($env['CORS_ALLOWED_ORIGINS'] ?? '');
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedOriginsRaw))));

// fallback سازگار با تنظیم قبلی
$appUrl = trim((string)($env['APP_URL'] ?? ''));
if ($appUrl !== '') {
    $allowedOrigins[] = $appUrl;
}
$allowedOrigins = array_values(array_unique($allowedOrigins));

$isAllowedOrigin = $requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true);

// چون پاسخ بر اساس Origin تغییر می‌کند
header('Vary: Origin');

if ($isAllowedOrigin) {
    header("Access-Control-Allow-Origin: {$requestOrigin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept, Origin');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
// ✅ Content-Security-Policy
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' https://cdn.jsdelivr.net https://code.jquery.com https://www.google.com https://www.gstatic.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data: https:; " .
    "frame-src https://www.google.com; " .
    "connect-src 'self' https://www.google.com;"
);

// ── ۴. Autoloader ────────────────────────────────────────────────
// Autoloader داخلی vendor/autoload.php (Composer) را لود می‌کند
// که شامل PHPMailer، Core\\ و App\\ و helpers می‌شود
require_once BASE_PATH . '/core/Autoloader.php';
\Core\Autoloader::register();

// ── ۵. Helpers ───────────────────────────────────────────────────
// از طریق composer autoload (files section در composer.json) لود می‌شوند

// ── ۶. Application ───────────────────────────────────────────────
//
//  ✅ تمام این‌ها داخل Application::__construct() انجام می‌شه:
//      - ExceptionHandler::register()   (یک‌بار، نه دوبار)
//      - Session::getInstance()->start()
//      - Database::getInstance()
//      - Container::getInstance() + registerCoreBindings()
//      - Maintenance Mode check
//
//  ❌ قبلاً اینجا بودند و اشتباه بود:
//      - new SettingService()->clearCache()   → قبل از DB init
//      - Session::getInstance()->start()      → قبل از Application
//      - ExceptionHandler::register()         → دوبار register
//
$app = \Core\Application::getInstance();

// ── ۷. اطمینان از وجود storage directories ──────────────────────
//    (فقط mkdir — هیچ DB call نیست)
$storageDirs = [
    BASE_PATH . '/storage/uploads/kyc',
    BASE_PATH . '/storage/cache',
    BASE_PATH . '/storage/logs',
];
// ✅ درست
foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
    if (!is_writable($dir)) {
        throw new \RuntimeException("Directory not writable: {$dir}");
    }
}

// ── ۸. Routes ────────────────────────────────────────────────────
require_once BASE_PATH . '/routes/routes.php';

// ── ۹. Run ───────────────────────────────────────────────────────
$app->run();

// ── ۱۰. Flush ───────────────────────────────────────────────────
ob_end_flush();
