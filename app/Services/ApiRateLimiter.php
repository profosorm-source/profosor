<?php

namespace App\Services;

use Core\RateLimiter;
use Core\Response;

/**
 * ApiRateLimiter - Rate limiting تخصصی برای endpoint های حساس
 *
 * اکنون از FeatureFlags برای کنترل محدودیت‌ها استفاده می‌کند
 * به جای preset های هاردکد، از تنظیمات پویا استفاده می‌شود
 *
 * نحوه استفاده در Controller:
 *
 *   $limiter = new ApiRateLimiter();
 *   $limiter->forWithdrawal(user_id()) || die; // یا redirect
 *
 *   // یا در یک خط:
 *   ApiRateLimiter::check('withdrawal', user_id()); // محدودیت‌ها از FeatureFlag می‌آید
 */
class ApiRateLimiter
{
    private RateLimiter $limiter;

    /** تعریف محدودیت‌های از پیش تعریف‌شده - حالا از FeatureFlag می‌آید */
    private const ACTIONS = [
        // مالی
        'withdrawal'         => 'withdrawal_limits',
        'manual_deposit'     => 'financial_limits',
        'crypto_deposit'     => 'financial_limits',
        'bank_card_add'      => 'financial_limits',

        // تسک
        'task_submit'        => 'task_limits',
        'task_dispute'       => 'task_limits',
        'custom_task_submit' => 'task_limits',

        // حساب کاربری
        'kyc_submit'         => 'security_limits',
        'profile_update'     => 'user_limits',
        'password_change'    => 'security_limits',

        // لاتاری
        'lottery_vote'       => 'lottery_limits',
        'lottery_participate'=> 'lottery_limits',

        // تیکت
        'ticket_create'      => 'support_limits',
        'ticket_reply'       => 'support_limits',

        // سوشال
        'social_account_add' => 'social_limits',

        // سرمایه‌گذاری
        'investment_create'  => 'investment_limits',
    ];

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * بررسی با تنظیمات از FeatureFlag
     *
     * @param string $action نام action (مثل 'withdrawal', 'task_submit')
     * @param int $userId
     * @param string|null $limitKey کلید محدودیت در FeatureFlag (مثل 'per_hour_limit')
     * @return bool true = مجاز، false = محدود شده
     */
    public function check(string $action, int $userId, ?string $limitKey = null): bool
    {
        $featureName = self::ACTIONS[$action] ?? 'rate_limiting';

        // اگر limitKey مشخص نشده، بر اساس action حدس بزن
        if (!$limitKey) {
            $limitKey = $this->guessLimitKey($action);
        }

        // دریافت تنظیمات از FeatureFlag
        $config = $this->getFeatureConfig($featureName, $limitKey);
        $maxAttempts = $config['max_attempts'];
        $decayMinutes = $config['decay_minutes'];

        $key = "rl_{$action}_user_{$userId}";

        return $this->limiter->attempt($key, $maxAttempts, $decayMinutes);
    }

    /**
     * دریافت تنظیمات از FeatureFlag
     * استفاده از rollout_percentage برای max_attempts و allowed_users برای decay_minutes
     */
    private function getFeatureConfig(string $featureName, string $limitKey): array
    {
        $feature = \App\Models\FeatureFlagUltimate::findByName($featureName);
        
        if (!$feature) {
            return [
                'max_attempts' => $this->getDefaultMaxAttempts($this->getActionFromFeature($featureName)),
                'decay_minutes' => $this->getDefaultDecayMinutes($this->getActionFromFeature($featureName))
            ];
        }
        
        // استفاده از rollout_percentage برای max_attempts
        $maxAttempts = $feature->rollout_percentage ?? $this->getDefaultMaxAttempts($this->getActionFromFeature($featureName));
        
        // استفاده از allowed_users (JSON) برای تنظیمات پیشرفته
        $decayMinutes = $this->getDefaultDecayMinutes($this->getActionFromFeature($featureName));
        
        if ($feature->allowed_users) {
            $config = json_decode($feature->allowed_users, true);
            if (is_array($config) && isset($config[$limitKey . '_decay_minutes'])) {
                $decayMinutes = (int) $config[$limitKey . '_decay_minutes'];
            }
        }
        
        return [
            'max_attempts' => $maxAttempts,
            'decay_minutes' => $decayMinutes
        ];
    }
    
    /**
     * تبدیل feature name به action
     */
    private function getActionFromFeature(string $featureName): string
    {
        $map = [
            'financial_limits' => 'withdrawal',
            'task_limits' => 'task_submit',
            'security_limits' => 'kyc_submit',
            'user_limits' => 'profile_update',
            'lottery_limits' => 'lottery_vote',
            'support_limits' => 'ticket_create',
            'social_limits' => 'social_account_add',
            'investment_limits' => 'investment_create',
        ];
        
        return $map[$featureName] ?? 'withdrawal';
    }

    /**
     * بررسی بر اساس IP (برای کاربران مهمان)
     */
    public function checkByIp(string $action, string $ip, ?string $limitKey = null): bool
    {
        $featureName = self::ACTIONS[$action] ?? 'rate_limiting';

        if (!$limitKey) {
            $limitKey = $this->guessLimitKey($action);
        }

        // دریافت تنظیمات از FeatureFlag
        $config = $this->getFeatureConfig($featureName, $limitKey);
        $maxAttempts = $config['max_attempts'];
        $decayMinutes = $config['decay_minutes'];

        $key = "rl_{$action}_ip_" . sha1($ip);

        return $this->limiter->attempt($key, $maxAttempts, $decayMinutes);
    }

    /**
     * حدس زدن کلید محدودیت بر اساس action
     */
    private function guessLimitKey(string $action): string
    {
        if (str_contains($action, 'hour')) {
            return 'per_hour';
        }
        if (str_contains($action, 'day')) {
            return 'per_day';
        }
        if (str_contains($action, 'minute')) {
            return 'per_minute';
        }

        // defaults بر اساس نوع action
        if (in_array($action, ['withdrawal', 'kyc_submit', 'password_change'])) {
            return 'strict'; // محدودیت سختگیرانه
        }

        return 'standard'; // محدودیت استاندارد
    }

    /**
     * مقدار پیش‌فرض max attempts
     */
    private function getDefaultMaxAttempts(string $action): int
    {
        $defaults = [
            'withdrawal' => 3,
            'manual_deposit' => 5,
            'crypto_deposit' => 10,
            'bank_card_add' => 3,
            'task_submit' => 30,
            'task_dispute' => 5,
            'custom_task_submit' => 10,
            'kyc_submit' => 3,
            'profile_update' => 10,
            'password_change' => 3,
            'lottery_vote' => 5,
            'lottery_participate' => 3,
            'ticket_create' => 5,
            'ticket_reply' => 20,
            'social_account_add' => 5,
            'investment_create' => 3,
        ];

        return $defaults[$action] ?? 10;
    }

    /**
     * مقدار پیش‌فرض decay minutes
     */
    private function getDefaultDecayMinutes(string $action): int
    {
        $defaults = [
            'withdrawal' => 60,      // 1 hour
            'manual_deposit' => 60,  // 1 hour
            'crypto_deposit' => 60,  // 1 hour
            'bank_card_add' => 1440, // 1 day
            'task_submit' => 60,     // 1 hour
            'task_dispute' => 1440,  // 1 day
            'custom_task_submit' => 60, // 1 hour
            'kyc_submit' => 1440,    // 1 day
            'profile_update' => 60,  // 1 hour
            'password_change' => 60, // 1 hour
            'lottery_vote' => 60,    // 1 hour
            'lottery_participate' => 1440, // 1 day
            'ticket_create' => 1440, // 1 day
            'ticket_reply' => 60,    // 1 hour
            'social_account_add' => 1440, // 1 day
            'investment_create' => 1440, // 1 day
        ];

        return $defaults[$action] ?? 60;
    }

    /**
     * باقی‌مانده تلاش‌ها
     */
    public function remaining(string $action, int $userId): int
    {
        $featureName = self::ACTIONS[$action] ?? 'rate_limiting';
        $limitKey = $this->guessLimitKey($action);

        $config = $this->getFeatureConfig($featureName, $limitKey);
        $max = $config['max_attempts'];

        $key = "rl_{$action}_user_{$userId}";
        $used = $this->limiter->getAttempts($key);

        return max(0, $max - $used);
    }

    /**
     * زمان تا بازنشینی (ثانیه)
     */
    public function retryAfter(string $action, int $userId): int
    {
        $key = "rl_{$action}_user_{$userId}";
        return $this->limiter->availableIn($key) ?? 0;
    }

    /**
     * پاسخ 429 استاندارد (JSON یا HTML)
     */
    public function tooManyResponse(string $action, int $userId, bool $isAjax = false): never
    {
        $retryAfter  = $this->retryAfter($action, $userId);
        $retryMins   = (int)ceil($retryAfter / 60);

        http_response_code(429);
        header('Retry-After: ' . $retryAfter);

        if ($isAjax || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'     => false,
                'message'     => "تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً {$retryMins} دقیقه دیگر تلاش کنید.",
                'retry_after' => $retryAfter,
                'error_code'  => 'RATE_LIMITED',
            ]);
        } else {
            echo "<h1>429 - تعداد درخواست‌ها بیش از حد</h1>";
            echo "<p>لطفاً {$retryMins} دقیقه دیگر تلاش کنید.</p>";
        }

        exit;
    }

    /**
     * Static shorthand - بررسی و در صورت محدودیت، خروج خودکار
     *
     * مثال: ApiRateLimiter::enforce('withdrawal', user_id());
     */
    public static function enforce(string $action, int $userId, bool $isAjax = false): void
    {
        $instance = \Core\Container::getInstance()->make(self::class);
        if (!$instance->check($action, $userId)) {
            $instance->tooManyResponse($action, $userId, $isAjax);
        }
    }
}
