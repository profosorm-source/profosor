<?php

namespace App\Services\AntiFraud;

use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Models\SeoExecution;
use Core\Database;

/**
 * SeoFraudDetector — تشخیص تقلب در تعاملات SEO
 */
class SeoFraudDetector
{
    private BrowserFingerprintService $fingerprintService;
    private SessionAnomalyService $anomalyService;
    private SeoExecution $executionModel;
    private Database $db;

    public function __construct(
        BrowserFingerprintService $fingerprintService,
        SessionAnomalyService $anomalyService,
        SeoExecution $executionModel,
        Database $db
    ) {
        $this->fingerprintService = $fingerprintService;
        $this->anomalyService = $anomalyService;
        $this->executionModel = $executionModel;
        $this->db = $db;
    }

    /**
     * بررسی جامع تقلب
     * 
     * @param int $userId
     * @param int $adId
     * @param array $engagementData
     * @return array ['is_fraud' => bool, 'flags' => array, 'risk_score' => float]
     */
    public function detect(int $userId, int $adId, array $engagementData): array
    {
        $flags = [];
        $riskScore = 0;

        // 1. بررسی Device Fingerprint
        $fingerprintCheck = $this->checkFingerprint($userId);
        if ($fingerprintCheck['suspicious']) {
            $flags[] = $fingerprintCheck['reason'];
            $riskScore += 25;
        }

        // 2. بررسی IP
        $ipCheck = $this->checkIP($userId);
        if ($ipCheck['suspicious']) {
            $flags[] = $ipCheck['reason'];
            $riskScore += 20;
        }

        // 3. بررسی الگوی رفتاری
        $behaviorCheck = $this->checkBehaviorPattern($engagementData);
        if ($behaviorCheck['suspicious']) {
            $flags = array_merge($flags, $behaviorCheck['reasons']);
            $riskScore += $behaviorCheck['risk_score'];
        }

        // 4. بررسی تکرار
        $repetitionCheck = $this->checkRepetition($userId, $adId);
        if ($repetitionCheck['suspicious']) {
            $flags[] = $repetitionCheck['reason'];
            $riskScore += 15;
        }

        // 5. بررسی سرعت اجرا
        $velocityCheck = $this->checkVelocity($userId);
        if ($velocityCheck['suspicious']) {
            $flags[] = $velocityCheck['reason'];
            $riskScore += 20;
        }

        $isFraud = $riskScore >= 50; // حد آستانه تقلب

        return [
            'is_fraud' => $isFraud,
            'flags' => $flags,
            'risk_score' => min(100, $riskScore),
            'details' => [
                'fingerprint' => $fingerprintCheck,
                'ip' => $ipCheck,
                'behavior' => $behaviorCheck,
                'repetition' => $repetitionCheck,
                'velocity' => $velocityCheck,
            ]
        ];
    }

    /**
     * بررسی Device Fingerprint
     */
    private function checkFingerprint(int $userId): array
    {
        try {
            $fingerprint = $this->fingerprintService->generate();
            
            // بررسی تعداد دستگاه‌های مختلف کاربر
            $stmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT device_fingerprint) AS device_count
                 FROM seo_executions
                 WHERE user_id = ? AND device_fingerprint IS NOT NULL
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $stmt->execute([$userId]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            
            $deviceCount = $result->device_count ?? 0;
            
            // بیش از 5 دستگاه مختلف در هفته = مشکوک
            if ($deviceCount > 5) {
                return [
                    'suspicious' => true,
                    'reason' => 'استفاده از دستگاه‌های متعدد',
                    'device_count' => $deviceCount,
                ];
            }

            return ['suspicious' => false];
            
        } catch (\Exception $e) {
            return ['suspicious' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * بررسی IP Address
     */
    private function checkIP(int $userId): array
    {
        $ip = get_client_ip();
        
        // بررسی VPN/Proxy/Tor
        if ($this->isVpnOrProxy($ip)) {
            return [
                'suspicious' => true,
                'reason' => 'استفاده از VPN/Proxy',
                'ip' => $ip,
            ];
        }

        // بررسی تعداد IP های مختلف
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT ip_address) AS ip_count
             FROM seo_executions
             WHERE user_id = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        
        $ipCount = $result->ip_count ?? 0;
        
        // بیش از 3 IP مختلف در روز = مشکوک
        if ($ipCount > 3) {
            return [
                'suspicious' => true,
                'reason' => 'IP های متعدد در 24 ساعت',
                'ip_count' => $ipCount,
            ];
        }

        return ['suspicious' => false];
    }

    /**
     * بررسی الگوی رفتاری
     */
    private function checkBehaviorPattern(array $data): array
    {
        $suspicious = false;
        $reasons = [];
        $riskScore = 0;

        // رفتار Bot-like: زمان کوتاه + امتیاز بالا
        $duration = $data['duration'] ?? 0;
        $score = $data['final_score'] ?? 0;
        
        if ($duration < 30 && $score > 80) {
            $suspicious = true;
            $reasons[] = 'امتیاز بالا در زمان خیلی کوتاه';
            $riskScore += 30;
        }

        // عدم تعامل واقعی
        $interactions = $data['interactions'] ?? 0;
        if ($interactions === 0 && $duration > 60) {
            $suspicious = true;
            $reasons[] = 'عدم تعامل با حضور طولانی';
            $riskScore += 25;
        }

        // اسکرول غیرطبیعی
        $scrollSpeed = $data['behavior']['scroll_speed'] ?? 0;
        if ($scrollSpeed > 5000) {
            $suspicious = true;
            $reasons[] = 'سرعت اسکرول غیرطبیعی';
            $riskScore += 20;
        }

        // الگوی حرکت خطی
        $mousePattern = $data['behavior']['mouse_pattern'] ?? 'normal';
        if ($mousePattern === 'linear' || $mousePattern === 'none') {
            $suspicious = true;
            $reasons[] = 'الگوی حرکت موس مشکوک';
            $riskScore += 15;
        }

        return [
            'suspicious' => $suspicious,
            'reasons' => $reasons,
            'risk_score' => $riskScore,
        ];
    }

    /**
     * بررسی تکرار
     */
    private function checkRepetition(int $userId, int $adId): array
    {
        // بررسی تکراری بودن امروز
        if ($this->executionModel->existsByAdAndUserToday($adId, $userId)) {
            return [
                'suspicious' => true,
                'reason' => 'تلاش برای اجرای مجدد در یک روز',
            ];
        }

        return ['suspicious' => false];
    }

    /**
     * بررسی سرعت اجرا (Velocity Check)
     */
    private function checkVelocity(int $userId): array
    {
        $hourlyCount = $this->executionModel->countByUserLastHour($userId);
        
        // بیش از 10 تسک در ساعت = Bot
        if ($hourlyCount > 10) {
            return [
                'suspicious' => true,
                'reason' => 'تعداد درخواست بیش از حد در ساعت',
                'hourly_count' => $hourlyCount,
            ];
        }

        return ['suspicious' => false];
    }

    /**
     * بررسی VPN/Proxy
     */
    private function isVpnOrProxy(string $ip): bool
    {
        // بررسی IP های محلی
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // TODO: یکپارچه‌سازی با IPQualityService
        // در حال حاضر فقط چک ساده
        
        return false;
    }

    /**
     * علامت‌گذاری کاربر در بلک‌لیست
     */
    public function addToBlacklist(int $userId, string $reason): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO seo_fraud_blacklist 
             (user_id, reason, created_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE
             strike_count = strike_count + 1,
             updated_at = NOW()"
        );

        return $stmt->execute([$userId, $reason]);
    }

    /**
     * بررسی بلک‌لیست
     */
    public function isBlacklisted(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_fraud_blacklist
             WHERE user_id = ? AND is_active = 1"
        );
        $stmt->execute([$userId]);
        
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Score Smoothing — جلوگیری از امتیازهای غیرواقعی
     */
    public function smoothScore(float $score, array $history): float
    {
        // اگر تاریخچه کمتر از 3 تسک دارد، امتیاز کاهش پیدا کند
        if (count($history) < 3) {
            return $score * 0.8; // 20% کاهش
        }

        // محاسبه میانگین امتیازات قبلی
        $avgScore = array_sum(array_column($history, 'final_score')) / count($history);
        
        // اگر امتیاز فعلی خیلی بیشتر از میانگین باشد
        if ($score > $avgScore + 30) {
            return min($score, $avgScore + 20); // حداکثر 20 امتیاز بیشتر از میانگین
        }

        return $score;
    }
}
