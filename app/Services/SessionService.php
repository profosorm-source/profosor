<?php

namespace App\Services;

use App\Models\UserSession;
use App\Services\RiskPolicyService;
use Core\Database;
use Core\Logger;


class SessionService
{
    private UserSession $sessionModel;
    private RiskPolicyService $policy;
    private Logger $logger;

    public function __construct(
        \App\Models\UserSession $sessionModel,
        RiskPolicyService $policy,
        Logger $logger
    )
    {
        $this->sessionModel = $sessionModel;
        $this->policy = $policy;
        $this->logger = $logger;
    }

    /**
     * ثبت نشست جدید
     */
    public function recordSession(int $userId, string $sessionId, ?array $geoData = null): bool
    {
        // پارس اطلاعات دستگاه
        $deviceInfo = $this->parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');

        // اگر session از قبل در دیتابیس موجود است فقط last_activity را آپدیت کن
        $existing = $this->sessionModel->findBySessionId($sessionId);
        if ($existing) {
            return $this->sessionModel->updateActivity($sessionId);
        }

        return (bool)$this->sessionModel->create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'ip_address' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'os' => $deviceInfo['os'],
            'country' => $geoData['country'] ?? null,
            'city' => $geoData['city'] ?? null,
            'latitude' => $geoData['latitude'] ?? null,
            'longitude' => $geoData['longitude'] ?? null,
            'fingerprint' => generate_device_fingerprint()
        ]);
    }

    /**
     * به‌روزرسانی فعالیت
     */
    public function updateActivity(string $sessionId): bool
    {
        return $this->sessionModel->updateActivity($sessionId);
    }

    /**
     * دریافت نشست‌های فعال
     */
    public function getActiveSessions(int $userId): array
    {
        return $this->sessionModel->getActiveSessions($userId);
    }

    /**
     * حذف نشست خاص
     */
    public function terminateSession(int $sessionId, int $userId): array
    {
        $session = $this->sessionModel->findBySessionId($sessionId);

        if (!$session || $session->user_id !== $userId) {
            return ['success' => false, 'message' => 'نشست یافت نشد'];
        }

        $result = $this->sessionModel->deactivate($session->id);

        if ($result) {
            return ['success' => true, 'message' => 'نشست با موفقیت حذف شد'];
        }

        return ['success' => false, 'message' => 'خطا در حذف نشست'];
    }

    /**
     * پاک‌سازی نشست‌های منقضی شده
     */
    public function cleanupSessions(): bool
    {
        $this->sessionModel->deleteExpired();
        return $this->sessionModel->deleteInactive();
    }

    /**
     * پارس User-Agent
     */
    private function parseUserAgent(string $userAgent): array
    {
        $deviceType = 'desktop';
        $browser = 'نامشخص';
        $os = 'نامشخص';

        // تشخیص دستگاه
        if (\preg_match('/mobile|android|iphone|ipad/i', $userAgent)) {
            $deviceType = 'mobile';
        } elseif (\preg_match('/tablet|ipad/i', $userAgent)) {
            $deviceType = 'tablet';
        }

        // تشخیص مرورگر
        if (\preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (\preg_match('/Firefox/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (\preg_match('/Safari/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (\preg_match('/Edge/i', $userAgent)) {
            $browser = 'Edge';
        }

        // تشخیص سیستم‌عامل
        if (\preg_match('/Windows/i', $userAgent)) {
            $os = 'Windows';
        } elseif (\preg_match('/Mac/i', $userAgent)) {
            $os = 'macOS';
        } elseif (\preg_match('/Linux/i', $userAgent)) {
            $os = 'Linux';
        } elseif (\preg_match('/Android/i', $userAgent)) {
            $os = 'Android';
        } elseif (\preg_match('/iOS|iPhone|iPad/i', $userAgent)) {
            $os = 'iOS';
        }

        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'os' => $os
        ];
    }
    
    /**
     * شمارش session های همزمان
     */
    public function countConcurrentSessions(int $userId): int
    {
        return $this->sessionModel->countActive($userId);
    }
    
    /**
     * پایان دادن به session
     */
    public function endSession(string $sessionId): void
    {
        $session = $this->sessionModel->findBySessionId($sessionId);
        if ($session) {
            $this->sessionModel->deactivate($session->id);
        }
    }
    
    /**
     * دریافت آخرین IP کاربر
     */
    public function getLastIP(int $userId): ?string
    {
        $sessions = $this->sessionModel->getActiveSessions($userId);
        return $sessions[0]->ip_address ?? null;
    }
    
    /**
     * دریافت آخرین User-Agent کاربر
     */
    public function getLastUserAgent(int $userId): ?string
    {
        $sessions = $this->sessionModel->getActiveSessions($userId);
        return $sessions[0]->user_agent ?? null;
    }

    /**
     * تحلیل آنومالی نشست (از SessionAnomalyService منتقل شده)
     */
    public function analyzeAnomaly(int $userId, string $sessionId): array
    {
        $this->logger->info('session.anomaly.analyze.started', [
            'user_id' => $userId,
            'session_id' => $sessionId
        ]);
        
        $anomalies = [];
        $score = 0;

        $concurrentCheck = $this->checkConcurrentSessions($userId);
        if ($concurrentCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.concurrent_points', 30);
            $anomalies[] = $concurrentCheck['reason'];
        }

        $uaCheck = $this->checkUserAgentChange($userId);
        if ($uaCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.ua_change_points', 40);
            $anomalies[] = $uaCheck['reason'];
        }

        $geoCheck = $this->checkGeolocationChange($userId);
        if ($geoCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.geo_change_points', 35);
            $anomalies[] = $geoCheck['reason'];
        }

        $timeCheck = $this->checkActivityTime($userId);
        if ($timeCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.activity_time_points', 15);
            $anomalies[] = $timeCheck['reason'];
        }

        $velocityCheck = $this->checkActionVelocity($userId);
        if ($velocityCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.velocity_points', 25);
            $anomalies[] = $velocityCheck['reason'];
        }

        $isAnomaly = $score >= 50;
        
        // لاگ بر اساس سطح خطر
        if ($score >= 80) {
            $this->logger->critical('session.anomaly.high_risk', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'score' => $score,
                'anomalies' => $anomalies
            ]);
        } elseif ($isAnomaly) {
            $this->logger->warning('session.anomaly.detected', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'score' => $score,
                'anomalies' => $anomalies
            ]);
        } else {
            $this->logger->info('session.anomaly.clean', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'score' => $score
            ]);
        }

        return [
            'is_anomaly' => $isAnomaly,
            'score' => min($score, 100),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * لاگ آنومالی نشست
     */
    public function logAnomaly(int $userId, string $sessionId, array $analysis): void
    {
        if (!$analysis['is_anomaly']) {
            return;
        }

        $sql = 'INSERT INTO fraud_logs (user_id, session_id, fraud_type, risk_score, details) VALUES (?, ?, ?, ?, ?)';
        $this->db->query($sql, [
            $userId,
            $sessionId,
            'session_anomaly',
            $analysis['score'],
            json_encode($analysis, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function checkConcurrentSessions(int $userId): array
    {
        $threshold = $this->policy->getInt('fraud', 'session.concurrent_threshold', 3);
        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND is_active = TRUE AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)';
        $result = $this->db->fetch($sql, [$userId]);
        $count = $result ? (int) $result->count : 0;

        if ($count > $threshold) {
            return ['anomaly' => true, 'reason' => "{$count} Session همزمان فعال"];
        }

        return ['anomaly' => false];
    }

    private function checkUserAgentChange(int $userId): array
    {
        $sql = 'SELECT user_agent, created_at FROM user_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 2';
        $sessions = $this->db->fetchAll($sql, [$userId]);

        if (count($sessions) < 2) {
            return ['anomaly' => false];
        }

        $timeDiff = strtotime($sessions[0]->created_at) - strtotime($sessions[1]->created_at);
        if ($timeDiff < 300 && $sessions[0]->user_agent !== $sessions[1]->user_agent) {
            return ['anomaly' => true, 'reason' => 'تغییر ناگهانی User-Agent در کمتر از 5 دقیقه'];
        }

        return ['anomaly' => false];
    }

    private function checkGeolocationChange(int $userId): array
    {
        $sql = 'SELECT country, city, created_at FROM user_sessions WHERE user_id = ? AND country IS NOT NULL ORDER BY created_at DESC LIMIT 2';
        $sessions = $this->db->fetchAll($sql, [$userId]);

        if (count($sessions) < 2) {
            return ['anomaly' => false];
        }

        $timeDiff = strtotime($sessions[0]->created_at) - strtotime($sessions[1]->created_at);
        if ($timeDiff < 3600 && $sessions[0]->country !== $sessions[1]->country) {
            return [
                'anomaly' => true,
                'reason' => "تغییر موقعیت از {$sessions[1]->country} به {$sessions[0]->country} در کمتر از 1 ساعت",
            ];
        }

        return ['anomaly' => false];
    }

    private function checkActivityTime(int $userId): array
    {
        $hour = (int) date('H');
        if ($hour < 2 || $hour > 6) {
            return ['anomaly' => false];
        }

        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND HOUR(created_at) BETWEEN 2 AND 6 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
        $result = $this->db->fetch($sql, [$userId]);
        $count = $result ? (int) $result->count : 0;

        if ($count > 5) {
            return ['anomaly' => true, 'reason' => 'فعالیت مکرر در ساعات غیرمعمول (2-6 صبح)'];
        }

        return ['anomaly' => false];
    }

    private function checkActionVelocity(int $userId): array
    {
        $sql = 'SELECT COUNT(*) as count FROM activity_logs WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)';
        $result = $this->db->fetch($sql, [$userId]);
        $count = $result ? (int) $result->count : 0;

        if ($count > 20) {
            return ['anomaly' => true, 'reason' => "{$count} اقدام در 1 دقیقه (سرعت غیرطبیعی)"];
        }

        return ['anomaly' => false];
    }
}