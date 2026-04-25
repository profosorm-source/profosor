<?php

use Core\Container;
use Core\Application;
use Core\Session;
use Core\Database;
use Core\Logger;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CaptchaService;
use App\Models\SystemSetting;
use App\Models\ActivityLog;
use App\Models\TwoFactorCode;
use App\Services\AuditTrail;
use App\Services\WalletService;
use App\Services\NotificationService;
use App\Services\UploadService;
use App\Services\WithdrawalLimitService;
use App\Services\WithdrawalService;
use App\Services\ReferralCommissionService;
use App\Services\UserLevelService;
use App\Services\ContentService;
use App\Services\InvestmentService;
use App\Services\LotteryService;
use App\Services\ManualDepositService;
use App\Services\CryptoDepositService;
use App\Services\CryptoVerificationService;
use App\Services\PaymentService;
use App\Services\StoryPromotionService;
use App\Services\KYCService;
use App\Services\BannerService;
use App\Services\TwoFactorService;
use App\Services\CustomTaskService;
use App\Services\SEOTaskService;
use App\Services\UserDashboardService;
use App\Models\TaskExecution;
use App\Models\Transaction;
use App\Models\ReferralCommission;
use App\Models\Notification;
use App\Models\SocialAccount;
use App\Models\Investment;
use App\Models\LotteryRound;
use App\Models\CustomTaskDispute;
use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;


// BASE_PATH
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Autoloader — vendor/autoload.php + PSR-4 (Core, App)
require_once BASE_PATH . '/core/Autoloader.php';
\Core\Autoloader::register();

// Helpers از طریق composer autoload (files section) لود می‌شوند
// نیازی به require_once دستی نیست

// Container Singleton
$container = Container::getInstance();

// ─── Logger — Singleton مرکزی لاگ ────────────────────────────────────────────
// PSR-3 Compatible Logging System
$container->singleton(\App\Services\LogService::class, function($c) {
    return new \App\Services\LogService(
        $c->make(\Core\Database::class),
        $c->make(\App\Models\ActivityLog::class)
    );
});

$container->singleton(\Core\Logger::class, function($c) {
    return new \Core\Logger(
        $c->make(\App\Services\LogService::class)
    );
});

$container->singleton(\App\Controllers\Admin\VitrineController::class, function($c) {
    return new \App\Controllers\Admin\VitrineController(
        $c->make(\App\Models\VitrineListing::class),
        $c->make(\App\Models\VitrineRequest::class),
        $c->make(\App\Services\VitrineService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

// =========================
// Sentry-like Services
// =========================

$container->singleton(\App\Services\Sentry\Alerting\AlertDispatcher::class, function($c) {
    return new \App\Services\Sentry\Alerting\AlertDispatcher(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Sentry\Alerting\AlertRulesEngine::class, function($c) {
    return new \App\Services\Sentry\Alerting\AlertRulesEngine(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Sentry\Alerting\AlertDispatcher::class)
    );
});

$container->singleton(\App\Services\Sentry\Alerting\EscalationManager::class, function($c) {
    return new \App\Services\Sentry\Alerting\EscalationManager(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Sentry\Alerting\AlertDispatcher::class)
    );
});

$container->singleton(\App\Controllers\Admin\ManualDepositController::class, function($c) {
    return new \App\Controllers\Admin\ManualDepositController(
        $c->make(\App\Models\UserBankCard::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\ManualDeposit::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\ManualDepositService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\CryptoDepositService::class, function($c) {
    return new \App\Services\CryptoDepositService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\NotificationService::class),
        $c->make(\App\Models\CryptoDepositIntent::class),
        $c->make(\App\Models\CryptoDeposit::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\User\WithdrawalController::class, function($c) {
    return new \App\Controllers\User\WithdrawalController(
        $c->make(\App\Models\Withdrawal::class),
        $c->make(\App\Models\UserBankCard::class),
        $c->make(\App\Services\WithdrawalLimitService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\RiskDecisionService::class),
        $c->make(\App\Services\WithdrawalService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\CryptoDepositController::class, function($c) {
    return new \App\Controllers\Admin\CryptoDepositController(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\CryptoDeposit::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\CryptoDepositService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor::class, function($c) {
    return new \App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        []
    );
});

$container->singleton(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class, function($c) {
    return new \App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Sentry\Alerting\AlertDispatcher::class),
        $c->make(\App\Services\AuditTrail::class),
        []
    );
});

$container->singleton(\App\Services\Sentry\Audit\AdvancedAuditTrail::class, function($c) {
    return new \App\Services\Sentry\Audit\AdvancedAuditTrail(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\AuditTrail::class),
        []
    );
});

$container->singleton(\App\Services\Sentry\SentryExceptionHandler::class, function($c) {
    return new \App\Services\Sentry\SentryExceptionHandler(
        $c->make(\Core\Logger::class)
    );
});


$container->singleton(App\Services\AuditTrail::class, function($c) {
    return new App\Services\AuditTrail(
        $c->make(Database::class),
        $c->make(Core\Logger::class)
    );
});



// ثبت سرویس‌ها و مدل‌ها
$container->singleton(Session::class, function() {
    return Session::getInstance();
});

$container->singleton(Database::class, function() {
    return Database::getInstance();
});


$container->singleton(\App\Services\GeoIPService::class, function($c) {
    return new \App\Services\GeoIPService(
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(CustomTaskAnalyticsService::class, function($c) {
    return new CustomTaskAnalyticsService(
        $c->make(Database::class),
        $c->make(Cache::class)
    );
});

$container->singleton(SystemSetting::class, function($c) {
    return new SystemSetting($c->make(Database::class));
});

$container->singleton(AuthService::class, function($c) {
    return new AuthService(
        $c->make(User::class),
        $c->make(\App\Models\PasswordReset::class),
        $c->make(ActivityLog::class),
        $c->make(Session::class),
        $c->make(\Core\RateLimiter::class),
        $c->make(\App\Services\SessionService::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\EmailService::class)
    );
});


$container->singleton(CaptchaService::class, function($c) {
    return new CaptchaService(
        $c->make(Database::class),
        $c->make(SystemSetting::class),
        $c->make(Session::class)
    );
});

$container->singleton(User::class, function($c) {
    return new User($c->make(Database::class));
});

$container->singleton(\App\Services\SettingService::class, function($c) {
    return new \App\Services\SettingService(
        $c->make(\App\Models\Setting::class),
        $c->make(Database::class)
    );
});

$container->singleton(\App\Models\Setting::class, function($c) {
    return new \App\Models\Setting($c->make(Database::class));
});


// ─── Singletons: Simple Services ─────────────────────────────────────────
$container->singleton(App\Services\WalletService::class, function($c) {
    return new App\Services\WalletService(
        $c->make(Database::class),
        $c->make(App\Models\Wallet::class),
        $c->make(App\Models\Transaction::class),
        $c->make(\Core\IdempotencyKey::class),
        $c->make(Core\Logger::class),
        $c->make(App\Services\AuditTrail::class)
    );
});

$container->singleton(\App\Services\GeoIPService::class, function($c) {
    return new \App\Services\GeoIPService(
        $c->make(Database::class)
    );
});

// ─── Distributed Lock Service ─────────────────────────────
$container->singleton(\App\Services\DistributedLockService::class, function($c) {
    return new \App\Services\DistributedLockService();
});

// ─── Query Optimization Service ───────────────────────────
$container->singleton(\App\Services\QueryOptimizationService::class, function($c) {
    return new \App\Services\QueryOptimizationService(
        $c->make(Database::class)
    );
});

// ─── Log Rotation Service ─────────────────────────────────
$container->singleton(\App\Services\LogRotationService::class, function($c) {
    return new \App\Services\LogRotationService(
        $c->make(Database::class)
    );
});

$container->singleton(\App\Services\EmailService::class, function($c) {
    return new \App\Services\EmailService(
        $c->make(\App\Models\EmailQueue::class),
        $c->make(\App\Models\NotificationPreference::class),
        $c->make(\App\Models\Setting::class),
        $c->make(\App\Models\User::class),
        $c->make(Logger::class)
    );
});

$container->singleton(NotificationService::class, function($c) {
    return new NotificationService(
        $c->make(\App\Models\Notification::class),
        $c->make(\App\Models\NotificationPreference::class),
        $c->make(Database::class),
        $c->make(Logger::class)
    );
});
$container->singleton(UploadService::class, fn() => new UploadService());
$container->singleton(WithdrawalLimitService::class, fn() => new WithdrawalLimitService());
$container->singleton(ActivityLog::class, fn() => new ActivityLog());
$container->singleton(TwoFactorCode::class, fn() => new TwoFactorCode());

// ─── Singletons: Services with dependencies ───────────────────────────────
$container->singleton(\App\Services\ReferralCommissionService::class, function($c) {
    return new \App\Services\ReferralCommissionService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Models\ReferralCommission::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\ReferralTierService::class),
        $c->make(\App\Services\ReferralMilestoneService::class)
    );
});

$container->singleton(\App\Services\UserLevelService::class, function($c) {
    return new \App\Services\UserLevelService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\ReferralCommissionService::class),
        $c->make(\App\Models\UserLevel::class),
        $c->make(\App\Models\UserLevelHistory::class),
        $c->make(\Core\Logger::class)
    );
});


// Models
$container->singleton(App\Models\CustomTask::class, function($c) {
    return new App\Models\CustomTask($c->make(Database::class));
});

$container->singleton(App\Models\CustomTaskSubmission::class, function($c) {
    return new App\Models\CustomTaskSubmission($c->make(Database::class));
});

$container->singleton(App\Models\CustomTaskDispute::class, function($c) {
    return new App\Models\CustomTaskDispute($c->make(Database::class));
});

// Service - با استفاده از Anti-Fraud موجود
$container->singleton(App\Services\CustomTaskService::class, function($c) {
    return new App\Services\CustomTaskService(
        $c->make(Database::class),
        $c->make(App\Services\WalletService::class),
        $c->make(App\Services\UserLevelService::class),
        $c->make(App\Services\ReferralCommissionService::class),
        $c->make(App\Models\CustomTask::class),
        $c->make(App\Models\CustomTaskSubmission::class),
        $c->make(App\Models\CustomTaskDispute::class),
        $c->make(App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(App\Services\AntiFraud\IPQualityService::class),
        $c->make(App\Services\AntiFraud\SessionAnomalyService::class)
    );
});

// Controllers
$container->singleton(App\Controllers\User\CustomTaskController::class, function($c) {
    return new App\Controllers\User\CustomTaskController(
        $c->make(App\Models\CustomTask::class),
        $c->make(App\Models\CustomTaskSubmission::class),
        $c->make(App\Services\CustomTaskService::class),
        $c->make(App\Services\UploadService::class)
    );
});

$container->singleton(App\Controllers\Admin\CustomTaskController::class, function($c) {
    return new App\Controllers\Admin\CustomTaskController(
        $c->make(App\Services\CustomTaskService::class),
        $c->make(App\Services\WalletService::class),
        $c->make(App\Models\CustomTask::class),
        $c->make(App\Models\CustomTaskSubmission::class)
    );
});

$container->singleton(ContentService::class, function($c) {
    return new ContentService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(\App\Services\InvestmentService::class, function($c) {
    return new \App\Services\InvestmentService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\NotificationService::class),
        $c->make(\App\Models\Investment::class),
        $c->make(\App\Models\TradingRecord::class),
        $c->make(\App\Models\InvestmentProfit::class),
        $c->make(\App\Models\InvestmentWithdrawal::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(LotteryService::class, function($c) {
    return new LotteryService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(ManualDepositService::class, function($c) {
    return new ManualDepositService(
        $c->make(Database::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Models\ManualDeposit::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\App\Models\User::class),
        $c->make(AuditTrail::class),
        $c->make(Logger::class)
    );
});

$container->singleton(CryptoDepositService::class, function($c) {
    return new CryptoDepositService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(CryptoVerificationService::class, function($c) {
    return new CryptoVerificationService(
        $c->make(Database::class),
        $c->make(Logger::class),
        $c->make(\App\Models\Setting::class),
        $c->make(\App\Models\CryptoDeposit::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(\App\Services\PaymentService::class, function($c) {
    return new \App\Services\PaymentService(
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\NotificationService::class),
        $c->make(\App\Models\PaymentLog::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\IdempotencyKey::class)
    );
});

$container->singleton(\App\Controllers\Admin\BankCardController::class, function($c) {
    return new \App\Controllers\Admin\BankCardController(
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\BankCardService::class),
        $c->make(\App\Models\BankCard::class)
    );
});

$container->singleton(WithdrawalService::class, function($c) {
    return new WithdrawalService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(WithdrawalLimitService::class),
        $c->make(AuditTrail::class),
        $c->make(Logger::class)
    );
});

$container->singleton(StoryPromotionService::class, function($c) {
    return new StoryPromotionService(
        $c->make(Database::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(ReferralCommissionService::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\InfluencerProfile::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Services\InfluencerReputationService::class)
    );
});

$container->singleton(\App\Models\InfluencerDispute::class, function($c) {
    return new \App\Models\InfluencerDispute($c->make(Database::class));
});

$container->singleton(\App\Models\InfluencerReputation::class, function($c) {
    return new \App\Models\InfluencerReputation($c->make(Database::class));
});

$container->singleton(\App\Services\InfluencerReputationService::class, function($c) {
    return new \App\Services\InfluencerReputationService(
        $c->make(Database::class),
        $c->make(\App\Models\InfluencerReputation::class),
        $c->make(\App\Models\InfluencerProfile::class)
    );
});

$container->singleton(\App\Services\InfluencerDisputeService::class, function($c) {
    return new \App\Services\InfluencerDisputeService(
        $c->make(Database::class),
        $c->make(\App\Models\InfluencerDispute::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(NotificationService::class),
        $c->make(StoryPromotionService::class),
        $c->make(\App\Services\InfluencerReputationService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\User\InfluencerController::class, function($c) {
    return new \App\Controllers\User\InfluencerController(
        $c->make(\App\Models\InfluencerProfile::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Models\InfluencerDispute::class),
        $c->make(\App\Models\InfluencerReputation::class),
        $c->make(\App\Services\StoryPromotionService::class),
        $c->make(\App\Services\InfluencerDisputeService::class),
        $c->make(\App\Services\InfluencerReputationService::class),
        $c->make(\App\Services\VerificationService::class),
        $c->make(UploadService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\InfluencerController::class, function($c) {
    return new \App\Controllers\Admin\InfluencerController(
        $c->make(\App\Models\InfluencerProfile::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Models\InfluencerDispute::class),
        $c->make(\App\Services\StoryPromotionService::class),
        $c->make(\App\Services\InfluencerDisputeService::class),
        $c->make(\App\Services\VerificationService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Api\InfluencerController::class, function($c) {
    return new \App\Controllers\Api\InfluencerController(
        $c->make(\App\Models\InfluencerProfile::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Models\InfluencerDispute::class),
        $c->make(\App\Services\StoryPromotionService::class),
        $c->make(\App\Services\InfluencerDisputeService::class),
        $c->make(\App\Services\InfluencerReputationService::class),
        $c->make(\App\Services\VerificationService::class),
        $c->make(UploadService::class)
    );
});

$container->singleton(KYCService::class, function($c) {
    return new KYCService(
        $c->make(\App\Models\KYCVerification::class),
        $c->make(\App\Models\User::class),
        $c->make(Database::class),
        $c->make(UploadService::class),
        $c->make(AuditTrail::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(BannerService::class, function($c) {
    return new BannerService($c->make(UploadService::class));
});

$container->singleton(TwoFactorService::class, function($c) {
    return new TwoFactorService(
        $c->make(User::class),
        $c->make(TwoFactorCode::class),
        $c->make(Session::class)
    );
});


$container->singleton(SEOTaskService::class, function($c) {
    return new SEOTaskService($c->make(WalletService::class));
});


$container->singleton(UserDashboardService::class, function($c) {
    return new UserDashboardService(
        $c->make(WalletService::class),
        $c->make(UserLevelService::class),
        $c->make(ReferralCommission::class),
        $c->make(Transaction::class),
        $c->make(ActivityLog::class),
        $c->make(Advertisement::class),
        $c->make(TaskExecution::class),
        $c->make(Notification::class),
        $c->make(SocialAccount::class),
        $c->make(Investment::class),
        $c->make(LotteryRound::class)
    );
});


// ─── Auto-generated Model Bindings ─────────────────────────────────────
$container->singleton(App\Models\AdTask::class, function($c) {
    return new App\Models\AdTask($c->make(Database::class));
});

$container->singleton(App\Models\BankCard::class, function($c) {
    return new App\Models\BankCard($c->make(Database::class));
});

$container->singleton(App\Models\Banner::class, function($c) {
    return new App\Models\Banner($c->make(Database::class));
});

$container->singleton(App\Models\BannerPlacement::class, function($c) {
    return new App\Models\BannerPlacement($c->make(Database::class));
});

$container->singleton(App\Models\BugReport::class, function($c) {
    return new App\Models\BugReport($c->make(Database::class));
});

$container->singleton(App\Models\BugReportComment::class, function($c) {
    return new App\Models\BugReportComment($c->make(Database::class));
});

$container->singleton(App\Models\ContentAgreement::class, function($c) {
    return new App\Models\ContentAgreement($c->make(Database::class));
});

$container->singleton(App\Models\ContentRevenue::class, function($c) {
    return new App\Models\ContentRevenue($c->make(Database::class));
});

$container->singleton(App\Models\ContentSubmission::class, function($c) {
    return new App\Models\ContentSubmission($c->make(Database::class));
});

$container->singleton(App\Models\CryptoDeposit::class, function($c) {
    return new App\Models\CryptoDeposit($c->make(Database::class));
});

$container->singleton(App\Models\CryptoDepositIntent::class, function($c) {
    return new App\Models\CryptoDepositIntent($c->make(Database::class));
});


$container->singleton(App\Models\EmailQueue::class, function($c) {
    return new App\Models\EmailQueue($c->make(Database::class));
});

$container->singleton(App\Models\InfluencerProfile::class, function($c) {
    return new App\Models\InfluencerProfile($c->make(Database::class));
});

$container->singleton(App\Models\InvestmentProfit::class, function($c) {
    return new App\Models\InvestmentProfit($c->make(Database::class));
});

$container->singleton(App\Models\InvestmentWithdrawal::class, function($c) {
    return new App\Models\InvestmentWithdrawal($c->make(Database::class));
});

$container->singleton(App\Models\KYCVerification::class, function($c) {
    return new App\Models\KYCVerification($c->make(Database::class));
});

$container->singleton(App\Models\LotteryDailyNumber::class, function($c) {
    return new App\Models\LotteryDailyNumber($c->make(Database::class));
});

$container->singleton(App\Models\LotteryParticipation::class, function($c) {
    return new App\Models\LotteryParticipation($c->make(Database::class));
});

$container->singleton(App\Models\LotteryVote::class, function($c) {
    return new App\Models\LotteryVote($c->make(Database::class));
});

$container->singleton(App\Models\ManualDeposit::class, function($c) {
    return new App\Models\ManualDeposit($c->make(Database::class));
});

$container->singleton(App\Models\NotificationPreference::class, function($c) {
    return new App\Models\NotificationPreference($c->make(Database::class));
});

$container->singleton(App\Models\Page::class, function($c) {
    return new App\Models\Page($c->make(Database::class));
});

$container->singleton(App\Models\PasswordReset::class, function($c) {
    return new App\Models\PasswordReset($c->make(Database::class));
});

$container->singleton(App\Models\SEOExecution::class, function($c) {
    return new App\Models\SEOExecution($c->make(Database::class));
});

$container->singleton(App\Models\SEOKeyword::class, function($c) {
    return new App\Models\SEOKeyword($c->make(Database::class));
});

$container->singleton(App\Models\StoryOrder::class, function($c) {
    return new App\Models\StoryOrder($c->make(Database::class));
});

$container->singleton(App\Models\TaskDispute::class, function($c) {
    return new App\Models\TaskDispute($c->make(Database::class));
});

$container->singleton(App\Models\TaskRecheck::class, function($c) {
    return new App\Models\TaskRecheck($c->make(Database::class));
});

$container->singleton(App\Models\Ticket::class, function($c) {
    return new App\Models\Ticket($c->make(Database::class));
});

$container->singleton(App\Models\TicketCategory::class, function($c) {
    return new App\Models\TicketCategory($c->make(Database::class));
});

$container->singleton(App\Models\TicketMessage::class, function($c) {
    return new App\Models\TicketMessage($c->make(Database::class));
});

$container->singleton(App\Models\TradingRecord::class, function($c) {
    return new App\Models\TradingRecord($c->make(Database::class));
});

$container->singleton(App\Models\UserBankCard::class, function($c) {
    return new App\Models\UserBankCard($c->make(Database::class));
});

$container->singleton(App\Models\Withdrawal::class, function($c) {
    return new App\Models\Withdrawal($c->make(Database::class));
});

$container->singleton(App\Models\WithdrawalLimit::class, function($c) {
    return new App\Models\WithdrawalLimit($c->make(Database::class));
});

// ─── Auto-generated Service Bindings ───────────────────────────────────
$container->singleton(App\Services\AdvertiserDashboardService::class, function($c) {
    return new App\Services\AdvertiserDashboardService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\BankCardService::class, function($c) {
    return new App\Services\BankCardService(
        $c->make(Database::class),
        $c->make(\App\Models\UserBankCard::class)
    );
});

$container->singleton(App\Services\BugReportService::class, function($c) {
    return new App\Services\BugReportService(
        $c->make(Database::class),
        $c->make(\App\Models\BugReport::class)
    );
});

$container->singleton(\App\Controllers\Admin\AuditTrailController::class, function($c) {
    return new \App\Controllers\Admin\AuditTrailController(
        $c->make(\App\Services\ExportService::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

$container->singleton(\App\Controllers\Admin\AuthController::class, function($c) {
    return new \App\Controllers\Admin\AuthController(
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

$container->singleton(App\Services\ExportService::class, function($c) {
    return new App\Services\ExportService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\GlobalSearchService::class, function($c) {
    return new App\Services\GlobalSearchService(
        $c->make(Database::class)
    );
});

$container->singleton(\App\Services\SearchService::class, function($c) {
    return new \App\Services\SearchService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\CacheService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AdvancedSearchService::class, function($c) {
    return new \App\Services\AdvancedSearchService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\CacheService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\FraudDetectionService::class, function($c) {
    return new \App\Services\FraudDetectionService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AppealService::class, function($c) {
    return new \App\Services\AppealService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\FraudController::class, function($c) {
    return new \App\Controllers\Admin\FraudController(
        $c->make(\App\Services\FraudDetectionService::class)
    );
});

$container->singleton(\App\Controllers\Admin\AppealAdminController::class, function($c) {
    return new \App\Controllers\Admin\AppealAdminController(
        $c->make(\App\Services\AppealService::class)
    );
});

$container->singleton(\App\Controllers\User\AppealController::class, function($c) {
    return new \App\Controllers\User\AppealController(
        $c->make(\App\Services\AppealService::class),
        $c->make(\App\Services\UploadService::class)
    );
});

$container->singleton(\App\Services\DirectMessageService::class, function($c) {
    return new \App\Services\DirectMessageService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Redis::class)
    );
});

$container->singleton(\App\Controllers\User\MessageController::class, function($c) {
    return new \App\Controllers\User\MessageController(
        $c->make(\App\Services\DirectMessageService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\MessageModerationController::class, function($c) {
    return new \App\Controllers\Admin\MessageModerationController(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\User\SocialTaskController::class, function($c) {
    return new \App\Controllers\User\SocialTaskController(
        $c->make(\App\Services\SocialTask\SocialTaskService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\RatingService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\CacheService::class, function($c) {
    return new \App\Services\CacheService();
});

$container->singleton(\App\Services\AnalyticsService::class, function($c) {
    return new \App\Services\AnalyticsService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\ReportService::class, function($c) {
    return new \App\Services\ReportService();
});

$container->singleton(\App\Controllers\Admin\AdminAnalyticsController::class, function($c) {
    return new \App\Controllers\Admin\AdminAnalyticsController(
        $c->make(\App\Services\AnalyticsService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(App\Services\KpiService::class, function($c) {
    return new App\Services\KpiService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\SEOExecutionService::class, function($c) {
    return new App\Services\SEOExecutionService(
        $c->make(Database::class),
        $c->make(\App\Models\SEOExecution::class)
    );
});

$container->singleton(App\Services\SEOKeywordService::class, function($c) {
    return new App\Services\SEOKeywordService(
        $c->make(Database::class),
        $c->make(\App\Models\SEOKeyword::class)
    );
});

$container->singleton(App\Services\SessionService::class, function($c) {
    return new App\Services\SessionService(
        $c->make(Database::class),
        $c->make(\App\Models\UserSession::class)
    );
});

$container->singleton(App\Services\SitemapService::class, function($c) {
    return new App\Services\SitemapService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\SocialAccountService::class, function($c) {
    return new App\Services\SocialAccountService(
        $c->make(Database::class),
        $c->make(\App\Models\SocialAccount::class)
    );
});

$container->singleton(App\Services\UserService::class, function($c) {
    return new App\Services\UserService(
        $c->make(Database::class),
        $c->make(User::class)
    );
});

$container->singleton(\App\Services\UserSettingsService::class, function($c) {
    return new \App\Services\UserSettingsService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\User::class)
    );
});

$container->singleton(\App\Controllers\User\SettingsController::class, function($c) {
    return new \App\Controllers\User\SettingsController(
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\UserSettingsService::class),
        $c->make(\Core\Logger::class)
    );
});

// ─── AntiFraud Services ───────────────────────────────────────────────────
$container->singleton(\App\Services\AntiFraud\IPQualityService::class, function($c) {
    return new \App\Services\AntiFraud\IPQualityService($c->make(Database::class));
});

$container->singleton(\App\Services\AntiFraud\BrowserFingerprintService::class, function($c) {
    return new \App\Services\AntiFraud\BrowserFingerprintService($c->make(Database::class));
});

$container->singleton(\App\Services\AntiFraud\SessionAnomalyService::class, function($c) {
    return new \App\Services\AntiFraud\SessionAnomalyService($c->make(Database::class));
});

$container->singleton(\App\Services\AntiFraud\AccountTakeoverService::class, function($c) {
    return new \App\Services\AntiFraud\AccountTakeoverService(
        $c->make(Database::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Services\AntiFraud\IPQualityService::class)
    );
});

// ─── UserSession Model ────────────────────────────────────────────────────
$container->singleton(\App\Models\UserSession::class, function($c) {
    return new \App\Models\UserSession($c->make(Database::class));
});

// ─── FeatureFlagService ───────────────────────────────────────────────────
$container->singleton(\App\Services\FeatureFlagService::class, function($c) {
    return new \App\Services\FeatureFlagService(
        $c->make(\App\Models\FeatureFlag::class),
        $c->make(Database::class)
    );
});

// ─── Core Services ────────────────────────────────────────────────────────
$container->singleton(\Core\RateLimiter::class, function($c) {
    return new \Core\RateLimiter();
});



// ─── AdminDashboardService ────────────────────────────────────────────────────
$container->singleton(\App\Services\AdminDashboardService::class, function($c) {
    return new \App\Services\AdminDashboardService(
        $c->make(Database::class),
        $c->make(Logger::class)
    );
});

// ─── VitrineService ───────────────────────────────────────────────────────────
$container->singleton(\App\Services\VitrineService::class, function($c) {
    return new \App\Services\VitrineService(
        $c->make(\App\Models\VitrineListing::class),
        $c->make(\App\Models\VitrineRequest::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Services\FeatureFlagService::class),
        $c->make(Database::class),
        $c->make(Logger::class)
    );
});

// ─── SocialTask Module ────────────────────────────────────────────────────
$container->singleton(\App\Services\SocialTask\TrustScoreService::class, function($c) {
    return new \App\Services\SocialTask\TrustScoreService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\UserScoreService::class)
    );
});

$container->singleton(\App\Services\SocialTask\SocialTaskScoringService::class, function($c) {
    return new \App\Services\SocialTask\SocialTaskScoringService();
});

$container->singleton(\App\Services\SocialTask\SilentAntiFraudService::class, function($c) {
    return new \App\Services\SocialTask\SilentAntiFraudService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\AntiFraud\IPQualityService::class),
        $c->make(\App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\SocialTaskScoringService::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\NotificationService::class)
    );
});

$container->singleton(\App\Services\SocialTask\SocialTaskService::class, function($c) {
    return new \App\Services\SocialTask\SocialTaskService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\SocialTask\SocialTaskScoringService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\SilentAntiFraudService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\NotificationService::class),
        $c->make(\App\Services\ApiRateLimiter::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\SocialTask\RatingService::class, function($c) {
    return new \App\Services\SocialTask\RatingService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});
 
// ─── Admin SocialTask Controller ─────────────────────────────────────────
$container->singleton(\App\Controllers\Admin\SocialTaskController::class, function($c) {
    return new \App\Controllers\Admin\SocialTaskController(
        $c->make(\App\Services\SocialTask\SocialTaskService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\RatingService::class),
        $c->make(\App\Services\SocialTask\SilentAntiFraudService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

$container->singleton(\App\Controllers\PaymentController::class, function($c) {
    return new \App\Controllers\PaymentController(
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\PaymentService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\Core\Database::class, function () {
    return \Core\Database::Instance();
});

// ─── Phase 3 Services ─────────────────────────────────────────────────────
// ✅ Real-time, Caching, Verification, Performance Optimization

$container->singleton(\App\Services\WebSocketService::class, function($c) {
    return new \App\Services\WebSocketService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\VerificationService::class, function($c) {
    return new \App\Services\VerificationService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\PerformanceOptimizationService::class, function($c) {
    return new \App\Services\PerformanceOptimizationService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

// Real-time API Controllers
$container->singleton(\App\Controllers\Api\RealTimeController::class, function($c) {
    return new \App\Controllers\Api\RealTimeController(
        $c->make(\App\Services\WebSocketService::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Request::class),
        $c->make(\Core\Response::class)
    );
});

$container->singleton(\App\Controllers\Api\VerificationController::class, function($c) {
    return new \App\Controllers\Api\VerificationController(
        $c->make(\App\Services\VerificationService::class),
        $c->make(\App\Models\InfluencerProfile::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Request::class),
        $c->make(\Core\Response::class)
    );
});

$container->singleton(\App\Controllers\Admin\WithdrawalController::class, function($c) {
    return new \App\Controllers\Admin\WithdrawalController(
        $c->make(\App\Models\Withdrawal::class),
        $c->make(\App\Services\BankCardService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\UserService::class),
        $c->make(\App\Services\WithdrawalService::class),
		$c->make(\Core\Logger::class)
    );
});

// ─── Phase 5e: Advanced Settings & Management ─────────────────────────────
$container->singleton(\App\Models\UserSetting::class, function($c) {
    return new \App\Models\UserSetting($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\DataExport::class, function($c) {
    return new \App\Models\DataExport($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\AccountDeletionLog::class, function($c) {
    return new \App\Models\AccountDeletionLog($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\SettingsAuditTrail::class, function($c) {
    return new \App\Models\SettingsAuditTrail($c->make(\Core\Database::class));
});

$container->singleton(\App\Services\DataExportService::class, function($c) {
    return new \App\Services\DataExportService(
        $c->make(\App\Models\DataExport::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AccountDeletionService::class, function($c) {
    return new \App\Services\AccountDeletionService(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\AccountDeletionLog::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\AccountDeletionManagementController::class, function($c) {
    return new \App\Controllers\Admin\AccountDeletionManagementController(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\AccountDeletionLog::class),
        $c->make(\App\Services\AccountDeletionService::class),
        $c->make(\Core\Logger::class)
    );
});

// ─── BackupService (Phase 5e) ─────────────────────────────────────────────
$container->singleton(\App\Services\BackupService::class, function($c) {
    return new \App\Services\BackupService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\BackupManagementController::class, function($c) {
    return new \App\Controllers\Admin\BackupManagementController(
        $c->make(\App\Services\BackupService::class),
        $c->make(\Core\Logger::class)
    );
});

// ═════════════════════════════════════════════════════════════════
// ─── Contracts Bindings برای DI و تست‌پذیری ────────────────────
// ═════════════════════════════════════════════════════════════════

// Cache Interface
$container->singleton(\App\Contracts\CacheInterface::class, function($c) {
    return new \App\Services\Cache\CacheManager(
        \Core\Cache::getInstance(),
        $c->make(\Core\Logger::class)
    );
});

// Rate Limiter Interface
$container->singleton(\App\Contracts\RateLimiterInterface::class, function($c) {
    return $c->make(\Core\RateLimiter::class);
});

// Feature Flag Repository Interface
$container->singleton(\App\Contracts\FeatureFlagRepositoryInterface::class, function($c) {
    return $c->make(\App\Models\FeatureFlagUltimate::class);
});

// ─── Policies ─────────────────────────────────────────────────────
$container->singleton(\App\Policies\FeatureFlagPolicy::class, function($c) {
    return new \App\Policies\FeatureFlagPolicy();
});

// Application — باید آخرین خط باشد
$app = Application::getInstance();
return $app;