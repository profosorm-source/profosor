<?php

use Core\Session;
use App\Services\SystemSettingService;
/**
 * توابع کمکی سراسری
 * 
 * این فایل شامل 40+ تابع کاربردی است
 */

if (!function_exists('env')) {
    /**
     * دریافت مقدار از .env
     * استفاده از اپی‌کش برای بهینه‌سازی
     */
    function env($key, $default = null)
    {
        static $env = null;
        
        if ($env === null) {
            $envFile = __DIR__ . '/../.env';
            
            // اگر فایل کش موجود باشد و از .env جدیدتر باشد، استفاده کن
            $cacheFile = __DIR__ . '/../storage/cache/.env.php';
            $useCached = false;
            
            if (file_exists($cacheFile) && file_exists($envFile)) {
                // اگر کش file جدیدتر است، استفاده کن
                if (filemtime($cacheFile) > filemtime($envFile)) {
                    $useCached = true;
                }
            } elseif (file_exists($cacheFile) && !file_exists($envFile)) {
                // اگر .env وجود ندارد اما کش وجود دارد
                $useCached = true;
            }
            
            if ($useCached) {
                $env = include $cacheFile;
            } else {
                // Parse .env و کش کن
                if (file_exists($envFile)) {
                    $env = parse_ini_file($envFile);
                    
                    // کش کردن برای requests بعدی
                    try {
                        $cachePath = dirname($cacheFile);
                        if (!is_dir($cachePath)) {
                            mkdir($cachePath, 0755, true);
                        }
                        file_put_contents($cacheFile, '<?php return ' . var_export($env, true) . ';');
                    } catch (\Exception $e) {
                        // اگر کش نشد، ادامه بده (بدون خطا)
                    }
                } else {
                    $env = [];
                }
            }
        }
        
        if (isset($env[$key])) {
            $value = $env[$key];
            
            // تبدیل string boolean به boolean واقعی
            if ($value === 'true') return true;
            if ($value === 'false') return false;
            if ($value === 'null') return null;
            
            return $value;
        }
        
        return $default;
    }
}

if (!function_exists('config')) {
    /**
     * دریافت مقدار از config
     * 
     * @param string $key کلید به صورت 'file.key' مثل 'app.name' یا 'session.lifetime'
     * @param mixed $default مقدار پیش‌فرض
     * @return mixed
     */
    function config(string $key, $default = null)
    {
        static $config = null;
        
        // بارگذاری تنها یک‌بار
        if ($config === null) {
            $configFile = __DIR__ . '/../config/config.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
            } else {
                $config = [];
            }
        }
        
        // جدا کردن file.key به parts
        $keys = explode('.', $key);
        $value = $config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
}


if (!function_exists('feature')) {
    /**
     * بررسی فیچر
     */
    function feature(string $name, ?int $userId = null): bool
    {
        static $service = null;
        
        if ($service === null) {
            $container = \Core\Container::getInstance();
            $service = $container->make(\App\Services\FeatureFlagService::class);
        }
        
        return $service->isEnabled($name, $userId);
    }
}



/**
 * بررسی احراز هویت کاربر
 */
function is_kyc_verified(?int $userId = null): bool
{
    $userId = $userId ?? user_id();
    if (!$userId) return false;

    $user = db()->query("SELECT kyc_status FROM users WHERE id = ?", [$userId])->fetch();
    return $user && $user->kyc_status === 'verified';
}

/**
 * دریافت وضعیت KYC کاربر
 */

/**
 * بررسی اینکه آیا کاربر می‌تواند برداشت کند
 */

/**
     * دریافت Application Instance
     */
if (!function_exists('app')) {
    
    function app()
    {
        return \Core\Application::getInstance();
    }
}

/**
 * ساخت URL کامل (سازگار با localhost و هاست)
 */
function url(string $path = ''): string
{
    // تشخیص خودکار Base URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // تشخیص خودکار Base Path
    $scriptName = $_SERVER['SCRIPT_NAME']; // "/chortke/public/index.php"
    $basePath = str_replace('/public/index.php', '', $scriptName); // "/chortke"
    $basePath = str_replace('\\', '/', $basePath); // Fix Windows
    
    // ساخت Base URL
    $baseUrl = $protocol . '://' . $host . $basePath;
    
    // اضافه کردن path
    $path = '/' . ltrim($path, '/');
    
    return rtrim($baseUrl, '/') . $path;
}


/**
 * دریافت URL فایل‌های استاتیک
 * 
 * مثال:
 * asset('assets/css/style.css') → http://localhost/chortke/assets/css/style.css
 * asset('uploads/avatars/user.jpg') → http://localhost/chortke/uploads/avatars/user.jpg
 * 
 * @param string $path مسیر نسبی فایل (از داخل public/)
 * @return string URL کامل
 */
function asset(string $path): string
{
    // حذف slash های اضافی
    $path = ltrim($path, '/');
    
    // دریافت URL پایه از config
    $baseUrl = config('app.url', 'http://localhost/chortke');
    $baseUrl = rtrim($baseUrl, '/');
    
    // ترکیب مستقیم
    return $baseUrl . '/' . $path;
}
if (!function_exists('redirect')) {
    /**
     * هدایت به URL
     *
     * امنیت: از Open Redirect جلوگیری می‌شود.
     * URL‌های خارجی فقط به دامنه‌های مجاز ریدایرکت می‌شوند.
     */
    function redirect(string $path): void
{
    // ذخیره session قبل از redirect
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // اگه با http/https شروع میشه، دامنه را بررسی کن
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        $appUrl  = env('APP_URL', '');
        $appHost = parse_url($appUrl, PHP_URL_HOST) ?? '';
        $pathHost = parse_url($path, PHP_URL_HOST) ?? '';

        // فقط به دامنه خودمان یا subdomainهای آن اجازه خروجی بده
        $isSameHost = ($pathHost === $appHost)
            || str_ends_with($pathHost, '.' . $appHost);

        if (!$isSameHost) {
            // خارجی — برگشت به صفحه اصلی
            header('Location: ' . rtrim($appUrl, '/') . '/');
            exit;
        }

        header("Location: {$path}");
        exit;
    }
    
    // اگه با / شروع میشه، base path رو اضافه کن
    $basePath = env('APP_BASE_PATH', '');
    $url = rtrim($basePath, '/') . '/' . ltrim($path, '/');
    
    header("Location: {$url}");
    exit;
}
}

if (!function_exists('back')) {
    /**
     * برگشت به صفحه قبل
     */
    function back()
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? url();
        redirect($referer);
    }
}

function old(string $key, $default = ''): string
{
    $old = app()->session->getFlash('old');
    
    if ($old === null) {
        return e($default);
    }
    
    if (!is_array($old)) {
        return e($default);
    }
    
    return e($old[$key] ?? $default);
}

/**
 * تولید Device Fingerprint
 */
function generate_device_fingerprint(): string
{
    $data = [
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
    ];
    
    return hash('sha256', implode('|', $data));
}


/**
 * تاریخ امروز
 */
function today(): string
{
    return date('Y-m-d');
}
/**
 * بررسی وجود خطا
 */

 /**
     * error
     */

function error(string $field): ?string
{
    $errors = app()->session->getFlash('errors');
    
    if ($errors === null || !is_array($errors)) {
        return null;
    }
    
    return $errors[$field] ?? null;
}
 /**
     * فیلد CSRF برای فرم
     */
function csrf_token(): string
{
    $session = app()->session;

    $token = $session->get('_csrf_token');

    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $session->set('_csrf_token', $token);
    }

    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
}

if (!function_exists('e')) {
    /**
     * Escape برای جلوگیری از XSS
     */
    function e($value)
    {
        return e($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
     * نمایش View
     */
if (!function_exists('view')) {

    function view($viewName, $data = [])
    {
        $session = \Core\Session::getInstance();

        $globals = [];

        $globals['isLoggedIn'] = $session->has('user_id');

        $globals['currentUser'] = null;

        if ($globals['isLoggedIn']) {
            // از طریق User Model — بدون new مستقیم در helper
            $globals['currentUser'] = (new \App\Models\User())->findById(
                (int)$session->get('user_id')
            ) ?: null;
        }

        $globals['flashSuccess'] = $session->getFlash('success');
        $globals['flashError']   = $session->getFlash('error');
        $globals['flashWarning'] = $session->getFlash('warning');
        $globals['errors']       = $session->getFlash('errors') ?? [];
        $globals['old']          = $session->getFlash('old')    ?? [];

        // تأیید ایمیل — ارسال مجدد
        $globals['showResendVerification'] = $session->getFlash('show_resend_verification') ?? false;
        $globals['resendEmail']            = $session->getFlash('resend_email') ?? '';

        $data = array_merge($globals, (array)$data);

        extract($data);

        $viewPath = __DIR__ . '/../views/' . str_replace('.', '/', $viewName) . '.php';

        if (!file_exists($viewPath)) {
            throw new \Exception("View not found: {$viewName}");
        }

        require $viewPath;
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and Die (برای دیباگ)
     */
    function dd(...$vars)
    {
        echo '<pre style="background: #1e1e1e; color: #ddd; padding: 20px; direction: ltr; text-align: left;">';
        foreach ($vars as $var) {
            if ((bool) env('APP_DEBUG', false)) {
    var_dump($var);
}
        }
        echo '</pre>';
        die(1);
    }
}


if (!function_exists('hash_password')) {
    /**
     * Hash کردن رمز عبور — Argon2id (ترجیحی) یا Bcrypt
     * OWASP 2026: Argon2id با memory=64MB, time=4 یا Bcrypt با cost≥14
     */
    function hash_password($password)
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536, // 64 MB
                'time_cost'   => 4,     // 4 iterations
                'threads'     => 2,     // 2 parallel threads
            ]);
        }

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 14]);
    }
}

if (!function_exists('verify_password')) {
    /**
     * تایید رمز عبور
     */
    function verify_password($password, $hash)
    {
        return password_verify($password, $hash);
    }
}


if (!function_exists('is_ajax')) {
    /**
     * بررسی درخواست AJAX
     */
    function is_ajax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('json_response')) {
    /**
     * پاسخ JSON
     */
    function json_response($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('path')) {
    function path(string $path = ''): string
    {
        return \Core\PathResolver::getInstance()->path($path);
    }
}





if (!function_exists('now')) {
    /**
     * تاریخ و زمان فعلی
     */
    function now()
    {
        return date('Y-m-d H:i:s');
    }
}

/**
 * دریافت Database Instance
 */
function db(): \Core\Database
{
    return \Core\Container::getInstance()->make(\Core\Database::class);
}
/**
     * تاریخ امروز — تعریف اصلی بالاتر در خط ~324 وجود دارد
     * FIX: حذف تعریف تکراری today()
     */
// today() already defined above

if (!function_exists('to_jalali')) {
    /**
     * تبدیل میلادی به شمسی و اعداد فارسی
     */
    function to_jalali($date, $format = 'Y/m/d', $persianNumbers = true)
    {
        if (empty($date)) return '';

        require_once __DIR__ . '/JalaliDate.php';

        $timestamp = is_numeric($date) ? $date : strtotime($date);
        $jalali = \Helpers\JalaliDate::format($format, $timestamp);

        if ($persianNumbers) {
            $englishNumbers = ['0','1','2','3','4','5','6','7','8','9'];
            $farsiNumbers   = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            $jalali = str_replace($englishNumbers, $farsiNumbers, $jalali);
        }

        return $jalali;
    }
}

if (!function_exists('fa_number')) {
    function fa_number($value): string
    {
        $english = ['0','1','2','3','4','5','6','7','8','9'];
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

        return str_replace($english, $persian, (string)$value);
    }
}

if (!function_exists('to_gregorian')) {
    /**
     * تبدیل شمسی به میلادی
     */
    function to_gregorian($jalaliDate)
    {
        if (empty($jalaliDate)) return '';
        
        require_once __DIR__ . '/JalaliDate.php';
        
        return \Helpers\JalaliDate::toGregorian($jalaliDate);
    }
}


if (!function_exists('upload_file')) {
    /**
     * آپلود فایل
     */
    function upload_file($file, $directory = 'general')
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('خطا در آپلود فایل');
        }
        
        $uploadPath = __DIR__ . '/../public/uploads/' . $directory . '/';
        
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $destination = $uploadPath . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new \Exception('خطا در ذخیره فایل');
        }
        
        // بدون پیشوند public/ — چون سرور از داخل public/ serve می‌کند
        return 'uploads/' . $directory . '/' . $filename;
    }
}

/**
 * دریافت حالت ارزی فعلی
 */

/**
 * فرمت کردن مبلغ
 */
function format_amount(float $amount): string
{
    return \App\Services\CurrencyService::formatAmount($amount);
}

/**
 * آیا سایت در حالت تومان است؟
 */

/**
 * آیا سایت در حالت تتر است؟
 */

/**
 * دریافت ارز قسمت فعلی (برای سرمایه‌گذاری همیشه USDT)
 */

if (!function_exists('delete_file')) {
    /**
     * حذف فایل
     */
    function delete_file($path)
    {
        $fullPath = __DIR__ . '/../' . $path;
        
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        
        return false;
    }
}

// FIX B-16: تعریف تکراری get_client_ip حذف شد.
// تعریف اصلی و امن بالاتر در همین فایل (خط ~289) وجود دارد.

// FIX: تعریف تکراری get_user_agent() حذف شد — تعریف اصلی بالاتر در خط ~316 وجود دارد


if (!function_exists('abort')) {
    /**
     * نمایش خطا و خروج
     */
    function abort($code = 404, $message = '')
    {
        http_response_code($code);
        
        $errorPage = __DIR__ . '/../views/errors/' . $code . '.php';
        
        if (file_exists($errorPage)) {
            require $errorPage;
        } else {
            echo "<h1>Error {$code}</h1>";
            if ($message) {
                echo "<p>{$message}</p>";
            }
        }
        
        exit;
    }
}

if (!function_exists('auth_user')) {
    /**
     * دریافت کاربر لاگین شده
     */
    function auth_user()
    {
        $userId = app()->session->get('user_id');
        
        if (!$userId) {
            return null;
        }
        
        static $user = null;

        if ($user === null) {
            // از طریق User Model
            $user = (new \App\Models\User())->findById($userId);
        }

        return $user;
    }
}

if (!function_exists('is_admin')) {
    /**
     * بررسی ادمین بودن کاربر
     */
   function is_admin(): bool
{
    $session = Session::getInstance();
    return ($session->get('user_role') === 'admin');
}
}

/**
 * تبدیل تاریخ به فرمت نسبی (مثلاً "2 ساعت پیش")
 */
function time_ago(string $datetime): string
{
    $timestamp = \strtotime($datetime);
    $now = \time();
    $diff = $now - $timestamp;

    if ($diff < 60) {
        return 'همین الان';
    } elseif ($diff < 3600) {
        $minutes = \floor($diff / 60);
        return to_jalali((string)$minutes, '', true) . ' دقیقه پیش';
    } elseif ($diff < 86400) {
        $hours = \floor($diff / 3600);
        return to_jalali((string)$hours, '', true) . ' ساعت پیش';
    } elseif ($diff < 2592000) {
        $days = \floor($diff / 86400);
        return to_jalali((string)$days, '', true) . ' روز پیش';
    } elseif ($diff < 31536000) {
        $months = \floor($diff / 2592000);
        return to_jalali((string)$months, '', true) . ' ماه پیش';
    } else {
        $years = \floor($diff / 31536000);
        return to_jalali((string)$years, '', true) . ' سال پیش';
    }
}

/**
     * تولید اثر انگشت دستگاه
     */
	 
// FIX: تعریف تکراری generate_device_fingerprint() حذف شد — تعریف اصلی بالاتر در خط ~270 وجود دارد
if (!function_exists('site_logo')) {
    /**
     * دریافت لوگوی سایت
     * 
     * @param string $type نوع لوگو: 'main', 'dark', 'footer'
     * @return string|null
     */
    function site_logo(string $type = 'main'): ?string
    {
        $key = $type === 'main' ? 'site_logo' : 'site_logo_' . $type;
        $path = setting($key);
        
        return $path ? url($path) : null;
    }
}

if (!function_exists('site_favicon')) {
    /**
     * دریافت فاویکون
     * 
     * @param string $type نوع فاویکون: 'default', 'apple'
     * @return string|null
     */
    function site_favicon(string $type = 'default'): ?string
    {
        $key = $type === 'default' ? 'site_favicon' : 'site_favicon_' . $type;
        $path = setting($key);
        
        return $path ? url($path) : null;
    }
}

if (!function_exists('site_og_image')) {
    /**
     * دریافت تصویر Open Graph
     * 
     * @return string|null
     */
    function site_og_image(): ?string
    {
        $path = setting('site_og_image');
        return $path ? url($path) : null;
    }
}



if (!function_exists('render_site_favicons')) {
    /**
     * رندر تگ‌های فاویکون
     * 
     * @return string
     */
    function render_site_favicons(): string
    {
        $html = '';
        
        // Favicon اصلی
        if ($favicon = site_favicon()) {
            $html .= '<link rel="icon" type="image/x-icon" href="' . e($favicon) . '">' . "\n";
            $html .= '<link rel="icon" type="image/png" href="' . e($favicon) . '">' . "\n";
        }
        
        // Apple Touch Icon
        if ($appleFavicon = site_favicon('apple')) {
            $html .= '<link rel="apple-touch-icon" href="' . e($appleFavicon) . '">' . "\n";
        }
        
        return $html;
    }
}

if (!function_exists('render_site_og_tags')) {
    /**
     * رندر تگ‌های Open Graph
     * 
     * @param string|null $title عنوان
     * @param string|null $description توضیحات
     * @param string|null $customImage تصویر سفارشی
     * @return string
     */
    function render_site_og_tags(?string $title = null, ?string $description = null, ?string $customImage = null): string
    {
        $html = '';
        
        // عنوان
        $ogTitle = $title ?? setting('site_name', 'وب‌سایت');
        $html .= '<meta property="og:title" content="' . e($ogTitle) . '">' . "\n";
        
        // توضیحات
        if ($description) {
            $html .= '<meta property="og:description" content="' . e($description) . '">' . "\n";
        }
        
        // تصویر
        $image = $customImage ?: site_og_image();
        if ($image) {
            $html .= '<meta property="og:image" content="' . e($image) . '">' . "\n";
        }
        
        // نوع
        $html .= '<meta property="og:type" content="website">' . "\n";
        
        // URL فعلی
        $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
                    . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $html .= '<meta property="og:url" content="' . e($currentUrl) . '">' . "\n";
        
        // Twitter Card
        $html .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
        $html .= '<meta name="twitter:title" content="' . e($ogTitle) . '">' . "\n";
        if ($description) {
            $html .= '<meta name="twitter:description" content="' . e($description) . '">' . "\n";
        }
        if ($image) {
            $html .= '<meta name="twitter:image" content="' . e($image) . '">' . "\n";
        }
        
        return $html;
    }
}


/**
 * دریافت پیام Flash
 */
function flash(string $key): ?string
{
    $value = app()->session->getFlash($key);
    
    // Debug (موقتی - بعداً حذف کن)
    // echo "<!-- Flash {$key}: " . var_export($value, true) . " -->\n";
    
    return $value;
}

/**
     * دریافت پیام Flash
     */
if (!function_exists('get_flash')) {
    
    function get_flash($key, $default = null)
    {
        return app()->session->getFlash($key, $default);
    }
}
/**
 * در انتهای فایل helpers/functions.php این تابع را اضافه کنید:
 */
function fa_digits(string $value): string
{
    $map = ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'];
    return \strtr($value, $map);
}

/**
 * بررسی دسترسی کاربر فعلی
 * 
 * @param string $permission slug دسترسی
 * @return bool
 */

/**
 * بررسی چند دسترسی (حداقل یکی)
 * 
 * @param array $permissions
 * @return bool
 */

	/**
 * تبدیل تاریخ میلادی به شمسی
 */
if (!function_exists('jdate')) {
    /**
     * تبدیل تاریخ میلادی به شمسی
     *
     * دو فرم قبول می‌کند:
     *   jdate($datetime)                  → تاریخ+ساعت پیش‌فرض
     *   jdate($datetime, 'Y/m/d')         → با فرمت دلخواه
     *   jdate('Y/m/d H:i', $timestamp)   → سازگار با فرم قدیمی (ترتیب برعکس)
     */
    function jdate($datetime, $format = 'Y/m/d H:i')
    {
        // اگر آرگومان اول فرمت بود و دومی timestamp (فرم قدیمی jdf)
        // مثال: jdate('Y/m/d H:i', 1700000000)
        if (
            is_string($datetime)
            && preg_match('/^[YmdHisAaDlNwWtzBuveOPTZ\/\-: ]+$/', $datetime)
            && (is_int($format) || (is_numeric($format) && (int)$format > 1000000000))
        ) {
            [$datetime, $format] = [$format, $datetime];
        }

        if (empty($datetime)) {
            return '-';
        }

        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);

        return jalali_date($format, $timestamp);
    }
}

if (!function_exists('jalali_date')) {
    function jalali_date($format, $timestamp)
    {
        $g_y = date('Y', $timestamp);
        $g_m = date('n', $timestamp);
        $g_d = date('j', $timestamp);

        list($j_y, $j_m, $j_d) = gregorian_to_jalali($g_y, $g_m, $g_d);

        $replacements = [
            'Y' => $j_y,
            'm' => str_pad($j_m, 2, '0', STR_PAD_LEFT),
            'd' => str_pad($j_d, 2, '0', STR_PAD_LEFT),
            'H' => date('H', $timestamp),
            'i' => date('i', $timestamp),
            's' => date('s', $timestamp),
        ];

        return strtr($format, $replacements);
    }
}

if (!function_exists('gregorian_to_jalali')) {
    function gregorian_to_jalali($g_y, $g_m, $g_d)
    {
        $g_days_in_month = [31,28,31,30,31,30,31,31,30,31,30,31];
        $j_days_in_month = [31,31,31,31,31,31,30,30,30,30,30,29];

        $gy = $g_y - 1600;
        $gm = $g_m - 1;
        $gd = $g_d - 1;

        $g_day_no = 365*$gy + intdiv($gy+3,4) - intdiv($gy+99,100) + intdiv($gy+399,400);

        for ($i=0; $i < $gm; ++$i)
            $g_day_no += $g_days_in_month[$i];

        if ($gm > 1 && (($gy%4==0 && $gy%100!=0) || ($gy%400==0)))
            $g_day_no++;

        $g_day_no += $gd;

        $j_day_no = $g_day_no - 79;

        $j_np = intdiv($j_day_no, 12053);
        $j_day_no %= 12053;

        $jy = 979 + 33*$j_np + 4*intdiv($j_day_no,1461);

        $j_day_no %= 1461;

        if ($j_day_no >= 366) {
            $jy += intdiv($j_day_no-1,365);
            $j_day_no = ($j_day_no-1)%365;
        }

        for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i)
            $j_day_no -= $j_days_in_month[$i];

        $jm = $i + 1;
        $jd = $j_day_no + 1;

        return [$jy, $jm, $jd];
    }
}


function jtime(?string $mysqlDateTime, bool $seconds = false, bool $convertNumbers = true): string
{
    if (!$mysqlDateTime) return '-';

    $fmt = $seconds ? 'H:i:s' : 'H:i';
    $t = \date($fmt, \strtotime($mysqlDateTime));

    // تبدیل اعداد ساعت به فارسی (اگر خواستی)
    return $convertNumbers ? to_jalali($t, '', true) : $t;
}


/**
 * بررسی لاگین بودن کاربر
 */
if (!function_exists('auth')) {
    function auth(): ?object
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $session = \Core\Session::getInstance();
        if (!$session->has('user_id')) {
            $cached = null;
            return null;
        }

        $userId = (int)$session->get('user_id');
        // از طریق User Model
        $cached = (new \App\Models\User())->findById($userId) ?: null;

        return $cached;
    }
}
/**
 * دریافت تنظیمات سایت
 */
function settings(bool $forceReload = false): array
{
    static $data = null;

    if ($forceReload) {
        $data = null;
    }

    if ($data !== null) return $data;

    $container = \Core\Container::getInstance();
    $service = $container->make(\App\Services\SettingService::class);
    $data = $service->load(); // public only
    return $data;
}

function setting(string $key, mixed $default = null): mixed
{
    $all = settings();
    return $all[$key] ?? $default;
}
/**
 * دریافت ID کاربر فعلی
 */
function user_id(): ?int
{
    $session = Session::getInstance();
    $id = $session->get('user_id');
    return $id ? (int)$id : null;
}

/**
 * در انتهای فایل helpers/functions.php این خطوط را اضافه کنید:
 */

// بارگذاری auth Helpers
if (file_exists(__DIR__ . '/auth_helper.php')) {
    require_once __DIR__ . '/auth_helper.php';
}

// بارگذاری banner Helpers
if (file_exists(__DIR__ . '/banner_helpers.php')) {
    require_once __DIR__ . '/banner_helpers.php';
}

// بارگذاری captcha_helper
if (file_exists(__DIR__ . '/captcha_helper.php')) {
    require_once __DIR__ . '/captcha_helper.php';
}

// بارگذاری config_helper
if (file_exists(__DIR__ . '/config_helper.php')) {
    require_once __DIR__ . '/config_helper.php';
}

// بارگذاری csrf_helper
if (file_exists(__DIR__ . '/csrf_helper.php')) {
    require_once __DIR__ . '/csrf_helper.php';
}


// بارگذاری notifications.php
if (file_exists(__DIR__ . '/notifications.php')) {
    require_once __DIR__ . '/notifications.php';
}


// بارگذاری date_helper
if (file_exists(__DIR__ . '/date_helper.php')) {
    require_once __DIR__ . '/date_helper.php';
}

// بارگذاری device_helper
if (file_exists(__DIR__ . '/device_helper.php')) {
    require_once __DIR__ . '/device_helper.php';

}
// بارگذاری rate_limit.php
if (file_exists(__DIR__ . '/rate_limit.php')) {
    require_once __DIR__ . '/rate_limit.php';

}
// بارگذاری fraud_helper
if (file_exists(__DIR__ . '/fraud_helper.php')) {
    require_once __DIR__ . '/fraud_helper.php';

}
// بارگذاری JalaliDate
if (file_exists(__DIR__ . '/JalaliDate.php')) {
    require_once __DIR__ . '/JalaliDate.php';

}
// بارگذاری label_helpers
if (file_exists(__DIR__ . '/label_helpers.php')) {
    require_once __DIR__ . '/label_helpers.php';

}

// بارگذاری logging
if (file_exists(__DIR__ . '/logging.php')) {
    require_once __DIR__ . '/logging.php';

}
// بارگذاری password_helper
if (file_exists(__DIR__ . '/password_helper.php')) {
    require_once __DIR__ . '/password_helper.php';

}
// بارگذاری response_helper
if (file_exists(__DIR__ . '/response_helper.php')) {
    require_once __DIR__ . '/response_helper.php';

}
// بارگذاری security
if (file_exists(__DIR__ . '/security.php')) {
    require_once __DIR__ . '/security.php';

}
// بارگذاری site_helper
if (file_exists(__DIR__ . '/site_helper.php')) {
    require_once __DIR__ . '/site_helper.php';

}
// بارگذاری url_helper
if (file_exists(__DIR__ . '/url_helper.php')) {
    require_once __DIR__ . '/url_helper.php';

}
// بارگذاری view_helper
if (file_exists(__DIR__ . '/view_helper.php')) {
    require_once __DIR__ . '/view_helper.php';

}
// ─────────────────────────────────────────────────────────
//  Cache Helper
// ─────────────────────────────────────────────────────────

/**
 * دسترسی سریع به Cache singleton
 *
 * مثال‌ها:
 *   cache()->put('key', $val, 10);
 *   cache()->get('key');
 *   cache()->remember('key', 10, fn() => expensiveQuery());
 *   cache()->tags(['users'])->flush();
 *   cache()->driver();  // 'redis' | 'file'
 */
function cache(): \Core\Cache
{
    return \Core\Cache::getInstance();
}
