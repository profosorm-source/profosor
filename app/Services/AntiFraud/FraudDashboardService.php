<?php

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Logger;

/**
 * FraudDashboardService
 * 
 * سرویس داشبورد Real-time برای مانیتورینگ تهدیدات
 * 
 * Features:
 * - Real-time fraud statistics
 * - Active alerts management
 * - Trend analysis
 * - Geographic threat map data
 * - Performance metrics
 */
class FraudDashboardService
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * دریافت آمار کلی (Overview)
     */
    public function getOverview(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        // تعداد کل fraud
        $totalFrauds = $this->db->fetch(
            "SELECT COUNT(*) as count FROM fraud_logs WHERE created_at >= ?",
            [$since]
        );
        
        // تعداد Alert های فعال
        $activeAlerts = $this->db->fetch(
            "SELECT COUNT(*) as count FROM fraud_alerts 
             WHERE status = 'pending' AND created_at >= ?",
            [$since]
        );
        
        // تعداد کاربران مسدود شده
        $blockedUsers = $this->db->fetch(
            "SELECT COUNT(DISTINCT user_id) as count FROM fraud_logs 
             WHERE action_taken = 'block' AND created_at >= ?",
            [$since]
        );
        
        // نرخ تشخیص (Detection Rate)
        $totalSessions = $this->db->fetch(
            "SELECT COUNT(*) as count FROM user_sessions WHERE created_at >= ?",
            [$since]
        );
        
        $detectionRate = ($totalSessions && $totalSessions->count > 0)
            ? round(($totalFrauds->count / $totalSessions->count) * 100, 2)
            : 0;
        
        return [
            'total_frauds' => (int)($totalFrauds->count ?? 0),
            'active_alerts' => (int)($activeAlerts->count ?? 0),
            'blocked_users' => (int)($blockedUsers->count ?? 0),
            'total_sessions' => (int)($totalSessions->count ?? 0),
            'detection_rate_percent' => $detectionRate,
            'period_hours' => $hours
        ];
    }

    /**
     * دریافت Alert های اخیر
     */
    public function getRecentAlerts(int $limit = 50, ?string $severity = null): array
    {
        $sql = "SELECT * FROM fraud_alerts WHERE 1=1";
        $params = [];
        
        if ($severity) {
            $sql .= " AND severity = ?";
            $params[] = $severity;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $alerts = $this->db->fetchAll($sql, $params);
        
        return array_map(function($alert) {
            return [
                'id' => $alert->id,
                'type' => $alert->alert_type,
                'severity' => $alert->severity,
                'user_id' => $alert->user_id,
                'title' => $alert->title,
                'description' => $alert->description,
                'details' => json_decode($alert->details ?? '{}', true),
                'status' => $alert->status,
                'created_at' => $alert->created_at
            ];
        }, $alerts);
    }

    /**
     * توزیع Fraud بر اساس نوع
     */
    public function getFraudTypeDistribution(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        $sql = "SELECT 
                    fraud_type,
                    COUNT(*) as count,
                    AVG(risk_score) as avg_risk_score
                FROM fraud_logs 
                WHERE created_at >= ?
                GROUP BY fraud_type
                ORDER BY count DESC";
        
        $results = $this->db->fetchAll($sql, [$since]);
        
        return array_map(function($row) {
            return [
                'type' => $row->fraud_type,
                'count' => (int)$row->count,
                'avg_risk_score' => round((float)$row->avg_risk_score, 2)
            ];
        }, $results);
    }

    /**
     * روند Fraud در طول زمان (Hourly)
     */
    public function getHourlyTrend(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        $sql = "SELECT 
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
                    COUNT(*) as count,
                    AVG(risk_score) as avg_risk
                FROM fraud_logs 
                WHERE created_at >= ?
                GROUP BY hour
                ORDER BY hour ASC";
        
        $results = $this->db->fetchAll($sql, [$since]);
        
        return array_map(function($row) {
            return [
                'hour' => $row->hour,
                'count' => (int)$row->count,
                'avg_risk' => round((float)$row->avg_risk, 2)
            ];
        }, $results);
    }

    /**
     * نقشه جغرافیایی تهدیدات
     */
    public function getGeographicThreats(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        $sql = "SELECT 
                    s.country,
                    s.country_name,
                    COUNT(DISTINCT f.id) as fraud_count,
                    COUNT(DISTINCT f.user_id) as affected_users,
                    AVG(f.risk_score) as avg_risk_score
                FROM fraud_logs f
                JOIN user_sessions s ON f.session_id = s.session_id
                WHERE f.created_at >= ?
                AND s.country IS NOT NULL
                GROUP BY s.country, s.country_name
                ORDER BY fraud_count DESC";
        
        $results = $this->db->fetchAll($sql, [$since]);
        
        return array_map(function($row) {
            return [
                'country_code' => $row->country,
                'country_name' => $row->country_name,
                'fraud_count' => (int)$row->fraud_count,
                'affected_users' => (int)$row->affected_users,
                'avg_risk_score' => round((float)$row->avg_risk_score, 2)
            ];
        }, $results);
    }

    /**
     * Top کاربران مشکوک
     */
    public function getTopSuspiciousUsers(int $limit = 20): array
    {
        $sql = "SELECT 
                    u.id,
                    u.username,
                    u.email,
                    COUNT(f.id) as fraud_count,
                    AVG(f.risk_score) as avg_risk_score,
                    MAX(f.created_at) as last_fraud_at,
                    u.fraud_score,
                    u.is_blacklisted
                FROM users u
                JOIN fraud_logs f ON u.id = f.user_id
                WHERE f.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY u.id
                ORDER BY fraud_count DESC, avg_risk_score DESC
                LIMIT ?";
        
        $results = $this->db->fetchAll($sql, [$limit]);
        
        return array_map(function($row) {
            return [
                'user_id' => (int)$row->id,
                'username' => $row->username,
                'email' => $row->email,
                'fraud_count' => (int)$row->fraud_count,
                'avg_risk_score' => round((float)$row->avg_risk_score, 2),
                'last_fraud_at' => $row->last_fraud_at,
                'fraud_score' => (int)$row->fraud_score,
                'is_blacklisted' => (bool)$row->is_blacklisted
            ];
        }, $results);
    }

    /**
     * IP های مشکوک
     */
    public function getTopSuspiciousIPs(int $limit = 20): array
    {
        $sql = "SELECT 
                    ip_address,
                    COUNT(DISTINCT user_id) as user_count,
                    COUNT(*) as fraud_count,
                    AVG(risk_score) as avg_risk_score,
                    MAX(created_at) as last_seen
                FROM fraud_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND ip_address IS NOT NULL
                GROUP BY ip_address
                ORDER BY fraud_count DESC, user_count DESC
                LIMIT ?";
        
        $results = $this->db->fetchAll($sql, [$limit]);
        
        return array_map(function($row) {
            return [
                'ip_address' => $row->ip_address,
                'user_count' => (int)$row->user_count,
                'fraud_count' => (int)$row->fraud_count,
                'avg_risk_score' => round((float)$row->avg_risk_score, 2),
                'last_seen' => $row->last_seen
            ];
        }, $results);
    }

    /**
     * Rate Limit Violations
     */
    public function getRateLimitViolations(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        $sql = "SELECT 
                    action,
                    COUNT(*) as count,
                    COUNT(DISTINCT identifier_key) as unique_identifiers
                FROM rate_limit_violations
                WHERE created_at >= ?
                GROUP BY action
                ORDER BY count DESC";
        
        $results = $this->db->fetchAll($sql, [$since]);
        
        return array_map(function($row) {
            return [
                'action' => $row->action,
                'violation_count' => (int)$row->count,
                'unique_identifiers' => (int)$row->unique_identifiers
            ];
        }, $results);
    }

    /**
     * Device Intelligence Stats
     */
    public function getDeviceStats(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(is_emulator) as emulator_count,
                    SUM(is_vm) as vm_count,
                    SUM(is_automation) as automation_count,
                    AVG(risk_score) as avg_risk_score
                FROM device_intelligence
                WHERE created_at >= ?";
        
        $result = $this->db->fetch($sql, [$since]);
        
        return [
            'total_devices' => (int)($result->total ?? 0),
            'emulator_count' => (int)($result->emulator_count ?? 0),
            'vm_count' => (int)($result->vm_count ?? 0),
            'automation_count' => (int)($result->automation_count ?? 0),
            'avg_risk_score' => round((float)($result->avg_risk_score ?? 0), 2),
            'emulator_percentage' => $result && $result->total > 0
                ? round(($result->emulator_count / $result->total) * 100, 2)
                : 0
        ];
    }

    /**
     * ایجاد Alert جدید
     */
    public function createAlert(
        string $alertType,
        string $severity,
        string $title,
        ?int $userId = null,
        ?string $description = null,
        ?array $details = null
    ): int {
        $sql = "INSERT INTO fraud_alerts 
                (alert_type, severity, user_id, title, description, details, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";
        
        $this->db->query($sql, [
            $alertType,
            $severity,
            $userId,
            $title,
            $description,
            json_encode($details ?? [], JSON_UNESCAPED_UNICODE)
        ]);
        
        $alertId = $this->db->lastInsertId();
        
        $this->logger->warning('fraud.alert.created', [
            'alert_id' => $alertId,
            'type' => $alertType,
            'severity' => $severity,
            'user_id' => $userId
        ]);
        
        return $alertId;
    }

    /**
     * به‌روزرسانی وضعیت Alert
     */
    public function updateAlertStatus(
        int $alertId,
        string $status,
        ?int $assignedTo = null
    ): bool {
        $sql = "UPDATE fraud_alerts SET status = ?, assigned_to = ?, updated_at = NOW()";
        $params = [$status, $assignedTo];
        
        if ($status === 'resolved') {
            $sql .= ", resolved_at = NOW()";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $alertId;
        
        return $this->db->query($sql, $params);
    }

    /**
     * Performance Metrics
     */
    public function getPerformanceMetrics(): array
    {
        // میانگین زمان تشخیص
        $avgDetectionTime = $this->db->fetch(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, f.created_at, a.created_at)) as avg_seconds
             FROM fraud_logs f
             LEFT JOIN fraud_alerts a ON a.user_id = f.user_id 
             WHERE f.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             AND a.created_at IS NOT NULL"
        );
        
        // نرخ False Positive (Alert هایی که false_positive شدند)
        $falsePositiveRate = $this->db->fetch(
            "SELECT 
                COUNT(CASE WHEN status = 'false_positive' THEN 1 END) as fp_count,
                COUNT(*) as total_count
             FROM fraud_alerts
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        $fpRate = ($falsePositiveRate && $falsePositiveRate->total_count > 0)
            ? round(($falsePositiveRate->fp_count / $falsePositiveRate->total_count) * 100, 2)
            : 0;
        
        // میانگین زمان حل Alert
        $avgResolutionTime = $this->db->fetch(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as avg_minutes
             FROM fraud_alerts
             WHERE status = 'resolved'
             AND resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        return [
            'avg_detection_time_seconds' => round((float)($avgDetectionTime->avg_seconds ?? 0), 2),
            'false_positive_rate_percent' => $fpRate,
            'avg_resolution_time_minutes' => round((float)($avgResolutionTime->avg_minutes ?? 0), 2)
        ];
    }

    /**
     * داده برای نمودار Real-time (آخرین 60 دقیقه)
     */
    public function getRealTimeChartData(): array
    {
        $sql = "SELECT 
                    DATE_FORMAT(created_at, '%H:%i') as minute,
                    COUNT(*) as count
                FROM fraud_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
                GROUP BY minute
                ORDER BY minute ASC";
        
        $results = $this->db->fetchAll($sql);
        
        $labels = [];
        $data = [];
        
        foreach ($results as $row) {
            $labels[] = $row->minute;
            $data[] = (int)$row->count;
        }
        
        return [
            'labels' => $labels,
            'data' => $data
        ];
    }

    /**
     * خلاصه کامل داشبورد
     */
    public function getCompleteDashboard(): array
    {
        return [
            'overview' => $this->getOverview(24),
            'recent_alerts' => $this->getRecentAlerts(10),
            'fraud_type_distribution' => $this->getFraudTypeDistribution(24),
            'hourly_trend' => $this->getHourlyTrend(24),
            'geographic_threats' => $this->getGeographicThreats(24),
            'top_suspicious_users' => $this->getTopSuspiciousUsers(10),
            'top_suspicious_ips' => $this->getTopSuspiciousIPs(10),
            'rate_limit_violations' => $this->getRateLimitViolations(24),
            'device_stats' => $this->getDeviceStats(24),
            'performance_metrics' => $this->getPerformanceMetrics(),
            'realtime_chart' => $this->getRealTimeChartData(),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
}
