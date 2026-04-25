<?php
namespace App\Services;

use App\Models\User;
use App\Models\PasswordReset;
use App\Models\ActivityLog;
use Core\Logger;
use Core\Session;
use Core\RateLimiter;
use App\Services\AuditTrail;
/**
 * Authentication Service
 */
class AuthService
{
    private User $userModel;
    private PasswordReset $passwordResetModel;
    private ActivityLog $activityLog;
    private Session $session;
    private RateLimiter $rateLimiter;
    private SessionService $sessionService;
    private ?EmailService $emailService;
    private Logger $logger;
    private AuditTrail $auditTrail;

    /**
     * Container این‌ها را auto-wire می‌کند (type-hint کافی است)
     */
   public function __construct(
    User $userModel,
    PasswordReset $passwordResetModel,
    ActivityLog $activityLog,
    Session $session,
    RateLimiter $rateLimiter,
    SessionService $sessionService,
    Logger $logger,
    AuditTrail $auditTrail,
    ?EmailService $emailService = null
) {
    $this->userModel          = $userModel;
    $this->passwordResetModel = $passwordResetModel;
    $this->activityLog        = $activityLog;
    $this->session            = $session;
    $this->rateLimiter        = $rateLimiter;
    $this->sessionService     = $sessionService;
    $this->emailService       = $emailService;
    $this->logger             = $logger;
    $this->auditTrail         = $auditTrail;
}

    /**
     * ثبت‌نام کاربر
     */
    public function register(array $data): array
    {
        if ($this->userModel->findByEmail($data['email'])) {
            return ['success' => false, 'message' => 'این ایمیل قبلاً ثبت شده است.'];
        }

        if (!empty($data['referral_code'])) {
            $referrer = $this->userModel->findByReferralCode($data['referral_code']);
            if ($referrer) {
                $data['referred_by'] = $referrer->id;
            }
        }

        $userId = $this->userModel->createUser($data);
        if (!$userId) {
            return ['success' => false, 'message' => 'خطا در ثبت‌نام. لطفاً دوباره تلاش کنید.'];
        }

        $this->logger->activity('auth.register', 'ثبت‌نام کاربر جدید', $userId, [
    'channel' => 'auth',
    'email' => $data['email'],
]);

        // ارسال ایمیل تأیید حساب (مستقیم، حیاتی)
        if ($this->emailService) {
            try {
                $newUser  = $this->userModel->find($userId);
                $verToken = $newUser->email_verification_token ?? null;
                if ($verToken) {
                    // تأیید ایمیل — ارسال مستقیم (sendDirect)
                    $this->emailService->sendVerificationEmail($userId, $verToken);
                } else {
                    // بدون token → فقط خوش‌آمدگویی از صف
                    $this->emailService->sendWelcomeEmail($userId);
                }
            } catch (\Throwable $e) {
                $this->logger->error('auth.register.email_failed', ['err' => $e->getMessage()]);
            }
        }

        return [
            'success' => true,
            'message' => 'ثبت‌نام با موفقیت انجام شد.',
            'user_id' => $userId,
        ];
    }

    /**
     * ورود کاربر
     */
    public function login(string $identifier, string $password, bool $remember = false): array
    {
        $rateLimitCheck = $this->rateLimiter->checkLoginAttempt($identifier);
        if (!$rateLimitCheck['allowed']) {
            return ['success' => false, 'message' => $rateLimitCheck['message']];
        }

        $user = $this->userModel->findByCredentials($identifier);
        if (!$user) {
            $this->logger->warning('auth.login.failed.user_not_found', [
    'channel' => 'auth',
    'identifier' => $identifier,
]);
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if (!verify_password($password, $user->password)) {
            $this->logger->warning('auth.login.failed.invalid_password', [
    'channel' => 'auth',
    'user_id' => $user->id,
]);
            $this->userModel->incrementFraudScore($user->id, 5);
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ($user->status === 'banned') {
            return ['success' => false, 'message' => 'حساب کاربری شما مسدود شده است.'];
        }

        if ($user->status === 'suspended') {
            return ['success' => false, 'message' => 'حساب کاربری شما به صورت موقت تعلیق شده است.'];
        }

        // بررسی تأیید ایمیل — ورود بدون تأیید ایمیل ممنوع
        if (empty($user->email_verified_at)) {
            return [
                'success' => false,
                'message' => 'ایمیل شما هنوز تأیید نشده است. لطفاً ایمیل ارسال‌شده را بررسی کنید.',
                'email_unverified' => true,
                'email' => $user->email,
            ];
        }

        if ($this->userModel->isBlacklisted($user->id)) {
            return ['success' => false, 'message' => 'دسترسی شما محدود شده است. لطفاً با پشتیبانی تماس بگیرید.'];
        }

        $this->rateLimiter->clearLoginAttempts($identifier);
        $this->userModel->updateLastLogin($user->id);
        $this->createSession($user, $remember);

        // لاگ فعالیت کاربر
        $this->logger->activity('auth.login', 'ورود موفق کاربر', $user->id, [
    'channel' => 'auth',
    'ip' => $this->getClientIp(),
]);

        // لاگ Audit Trail
        $this->auditTrail->record('auth.login', $user->id, [
            'ip' => $this->getClientIp()
        ]);
        
        return [
            'success'      => true,
            'message'      => 'ورود موفقیت‌آمیز بود.',
            'user'         => $user,
            'requires_2fa' => isset($user->two_factor_enabled) && $user->two_factor_enabled == 1,
        ];
    }

    /**
     * ایجاد Session
     */
    private function createSession(object $user, bool $remember = false): void
    {
        // ۱. ابتدا regenerate — جلوگیری از Session Fixation Attack
        $this->session->regenerate();

        // ۲. سپس مقداردهی session با ID جدید
        $this->session->set('user_id',  $user->id);
        $this->session->set('username', $user->username ?? '');
        $this->session->set('email',    $user->email);
        $this->session->set('role',     $user->role);
        $this->session->set('user_role', $user->role);
        $this->session->set('is_admin', in_array($user->role, ['admin', 'super_admin'], true));
        $this->session->set('logged_in', true);

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $this->userModel->updateRememberToken($user->id, $token);
            setcookie('remember_token', $token, time() + (30 * 86400), '/', '', true, true);
        }

        // ۳. ذخیره session ID جدید در دیتابیس (بعد از regenerate)
        $this->sessionService->recordSession($user->id, $this->session->getId());
    }

    /**
     * خروج کاربر
     */
   public function logout(): array
{
    $userId = $this->session->get('user_id');

    if ($userId) {
        $this->logger->activity('auth.logout', 'خروج کاربر', $userId, [
    'channel' => 'auth',
]);
        $this->session->remove('user_id'); // فقط حذف شناسه کاربر
    }

    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }

    $this->session->destroy();

    return [
        'success' => true,
        'message' => 'خروج موفقیت‌آمیز بود.'
    ];
}

    /**
     * درخواست بازیابی رمز عبور
     */
    public function requestPasswordReset(string $email): array
    {
        $user = $this->userModel->findByEmail($email);
        // پیام یکسان برای امنیت (email enumeration prevention)
        $genericMsg = 'اگر این ایمیل در سیستم ثبت شده باشد، لینک بازیابی برای شما ارسال می‌شود.';

        if (!$user) {
            return ['success' => true, 'message' => $genericMsg];
        }

        $token = $this->passwordResetModel->createToken($email);
        
        $this->logger->info('Password reset token created', [
            'email' => $email,
            'token' => env('APP_DEBUG') ? $token : '***',
        ]);

        // ارسال ایمیل بازیابی رمز عبور (مستقیم، حیاتی)
        if ($this->emailService) {
            try {
                $this->emailService->sendPasswordResetEmail((int)$user->id, $token);
            } catch (\Throwable $e) {
                $this->logger->error('auth.password_reset.email_failed', ['err' => $e->getMessage()]);
            }
        }

        $this->logger->activity('auth.password_reset.requested', 'درخواست بازیابی رمز عبور', $user->id, [
    'channel' => 'auth',
]);
        return [
            'success' => true,
            'message' => $genericMsg,
            'token'   => env('APP_DEBUG') ? $token : null,
        ];
    }

    /**
     * بازیابی رمز عبور
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        $record = $this->passwordResetModel->findByToken($token);
        if (!$record || $this->passwordResetModel->isExpired($record)) {
            return ['success' => false, 'message' => 'لینک بازیابی نامعتبر یا منقضی شده است.'];
        }

        $user = $this->userModel->findByEmail($record->email);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد.'];
        }

        $this->userModel->changePassword($user->id, $newPassword);
        $this->passwordResetModel->deleteByEmail($record->email);
        $this->logger->activity('auth.password_reset.completed', 'بازیابی رمز عبور انجام شد', $user->id, [
    'channel' => 'auth',
]);
        return ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد. اکنون می‌توانید وارد شوید.'];
    }

    /**
     * تایید ایمیل با توکن
     */
    /**
     * ارسال مجدد ایمیل تأیید
     */
    public function resendVerificationEmail(string $email): bool
    {
        $user = $this->userModel->findByEmail($email);

        if (!$user || !empty($user->email_verified_at)) {
            return false;
        }

        // رفرش token اگر نداشت
        $token = $user->email_verification_token ?? null;
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $this->userModel->update($user->id, ['email_verification_token' => $token]);
        }

        if (!$this->emailService) {
            return false;
        }

        // ارسال مستقیم (حیاتی — بدون صف)
        return $this->emailService->sendVerificationEmail($user->id, $token);
    }

    public function verifyEmail(string $token): array
    {
        $user = $this->userModel->verifyEmailByToken($token);

        if (!$user) {
            return ['success' => false, 'message' => 'لینک تایید نامعتبر است.'];
        }

        $this->logger->activity('auth.email.verified', 'ایمیل تایید شد', $user->id, [
    'channel' => 'auth',
]);
        return ['success' => true, 'message' => 'ایمیل شما با موفقیت تایید شد. اکنون می‌توانید وارد شوید.'];
    }

    /**
     * تأیید ایمیل با کد ۶ رقمی
     */
    public function verifyEmailByCode(string $email, string $code): array
    {
        $user = $this->userModel->verifyEmailByCode($email, $code);

        if (!$user) {
            return ['success' => false, 'message' => 'کد وارد شده اشتباه است یا منقضی شده است.'];
        }

        $this->logger->activity('auth.email.verified_by_code', 'ایمیل با کد تایید شد', $user->id, [
    'channel' => 'auth',
]);
        return ['success' => true, 'message' => 'ایمیل شما با موفقیت تایید شد. اکنون می‌توانید وارد شوید.'];
    }

        /**
     * تایید ایمیل با userId
     */
    public function verifyEmailById(int $userId): array
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد.'];
        }

        $verified = $this->userModel->verifyEmail($userId);
        if (!$verified) {
            return ['success' => false, 'message' => 'خطا در تایید ایمیل.'];
        }

        $this->logger->activity('auth.email.verified', 'ایمیل تایید شد', $userId, [
    'channel' => 'auth',
]);
        return ['success' => true, 'message' => 'ایمیل شما با موفقیت تایید شد.'];
    }

    /**
     * بررسی لاگین بودن
     */
    public function check(): bool
    {
        return $this->session->get('logged_in') === true;
    }

    /**
     * دریافت کاربر لاگین شده
     */
    public function user(): ?object
    {
        if (!$this->check()) {
            return null;
        }
        return $this->userModel->find((int) $this->session->get('user_id'));
    }

    /**
     * دریافت IP کلاینت
     */
    private function getClientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}