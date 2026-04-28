<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Cache;

/**
 * سرویس تحلیل‌های پیشرفته
 * 
 * ارائه تحلیل‌های آماری و پیش‌بینی برای کل سیستم
 * قابل استفاده برای هر Module (Users, Content, Transactions, etc.)
 * 
 * @package App\Services
 */
class AdvancedAnalyticsService
{
    private Database $db;
    private Cache $cache;

    public function __construct(Database $db, Cache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * تحلیل روند داده‌ها در یک بازه زمانی
     * 
     * @param string $table نام جدول
     * @param string $dateColumn ستون تاریخ
     * @param int $days تعداد روز
     * @param array $conditions شرایط اضافی ['column' => 'value']
     * @param array $groupByColumns ستون‌های دسته‌بندی
     * @return array
     */
    public function getTrend(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30,
        array $conditions = [],
        array $groupByColumns = []
    ): array {
        $cacheKey = "analytics:trend:{$table}:{$days}:" . md5(serialize($conditions));
        
        return $this->cache->remember($cacheKey, 300, function() use (
            $table, $dateColumn, $days, $conditions, $groupByColumns
        ) {
            $where = ["{$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
            $params = [$days];

            foreach ($conditions as $column => $value) {
                if ($value === null) {
                    $where[] = "{$column} IS NULL";
                } else {
                    $where[] = "{$column} = ?";
                    $params[] = $value;
                }
            }

            $groupBy = empty($groupByColumns) 
                ? "DATE({$dateColumn})" 
                : "DATE({$dateColumn}), " . implode(', ', $groupByColumns);

            $select = "DATE({$dateColumn}) as date, COUNT(*) as total";
            
            if (!empty($groupByColumns)) {
                $select .= ', ' . implode(', ', $groupByColumns);
            }

            $sql = "SELECT {$select}
                    FROM {$table}
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY {$groupBy}
                    ORDER BY date ASC";

            $stmt = $this->db->query($sql, $params);
            $data = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            return [
                'period_days' => $days,
                'data' => $data,
                'summary' => $this->calculateTrendSummary($data),
            ];
        });
    }

    /**
     * مقایسه دو بازه زمانی
     * 
     * @param string $table
     * @param string $dateColumn
     * @param int $currentPeriodDays
     * @param array $conditions
     * @return array
     */
    public function comparePeriods(
        string $table,
        string $dateColumn = 'created_at',
        int $currentPeriodDays = 7,
        array $conditions = []
    ): array {
        $current = $this->getPeriodStats(
            $table, 
            $dateColumn, 
            0, 
            $currentPeriodDays, 
            $conditions
        );
        
        $previous = $this->getPeriodStats(
            $table, 
            $dateColumn, 
            $currentPeriodDays, 
            $currentPeriodDays, 
            $conditions
        );

        $change = $previous['total'] > 0 
            ? (($current['total'] - $previous['total']) / $previous['total']) * 100 
            : 0;

        return [
            'current' => $current,
            'previous' => $previous,
            'change_percent' => round($change, 2),
            'trend' => $change > 5 ? 'increasing' : ($change < -5 ? 'decreasing' : 'stable'),
        ];
    }

    /**
     * توزیع داده‌ها بر اساس یک ستون
     * 
     * @param string $table
     * @param string $column
     * @param array $conditions
     * @param int $limit
     * @return array
     */
    public function getDistribution(
        string $table,
        string $column,
        array $conditions = [],
        int $limit = 10
    ): array {
        $cacheKey = "analytics:dist:{$table}:{$column}:" . md5(serialize($conditions));
        
        return $this->cache->remember($cacheKey, 600, function() use (
            $table, $column, $conditions, $limit
        ) {
            $where = ['1=1'];
            $params = [];

            foreach ($conditions as $col => $value) {
                if ($value === null) {
                    $where[] = "{$col} IS NULL";
                } else {
                    $where[] = "{$col} = ?";
                    $params[] = $value;
                }
            }

            $sql = "SELECT 
                        {$column} as label,
                        COUNT(*) as count,
                        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where) . "), 2) as percentage
                    FROM {$table}
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY {$column}
                    ORDER BY count DESC
                    LIMIT ?";

            $params[] = $limit;

            $stmt = $this->db->query($sql, $params);
            return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        });
    }

    /**
     * رتبه‌بندی رکوردها بر اساس یک متریک
     * 
     * @param string $sql کوئری SQL کامل
     * @param array $params
     * @param int $limit
     * @return array
     */
    public function getRanking(string $sql, array $params = [], int $limit = 10): array
    {
        $sql .= " LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /**
     * محاسبه آمار توصیفی
     * 
     * @param string $table
     * @param string $column
     * @param array $conditions
     * @return array
     */
    public function getDescriptiveStats(
        string $table,
        string $column,
        array $conditions = []
    ): array {
        $where = ['1=1'];
        $params = [];

        foreach ($conditions as $col => $value) {
            $where[] = "{$col} = ?";
            $params[] = $value;
        }

        $sql = "SELECT 
                    COUNT(*) as count,
                    AVG({$column}) as mean,
                    MIN({$column}) as min,
                    MAX({$column}) as max,
                    STDDEV({$column}) as stddev
                FROM {$table}
                WHERE " . implode(' AND ', $where);

        $stmt = $this->db->query($sql, $params);
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;

        if (!$result) {
            return $this->getEmptyStats();
        }

        return [
            'count' => (int)$result['count'],
            'mean' => round((float)$result['mean'], 2),
            'min' => (float)$result['min'],
            'max' => (float)$result['max'],
            'stddev' => round((float)($result['stddev'] ?? 0), 2),
        ];
    }

    /**
     * پیش‌بینی ساده با Linear Regression
     * 
     * @param array $historicalData مقادیر تاریخی ['date' => 'value']
     * @param int $forecastDays تعداد روزهای پیش‌بینی
     * @return array
     */
    public function forecast(array $historicalData, int $forecastDays = 7): array
    {
        if (count($historicalData) < 2) {
            return [
                'forecast' => [],
                'confidence' => 0,
                'method' => 'insufficient_data',
            ];
        }

        // محاسبه میانگین و روند
        $values = array_column($historicalData, 'value');
        $average = array_sum($values) / count($values);

        // میانگین هفته اخیر
        $recentValues = array_slice($values, -7);
        $recentAverage = array_sum($recentValues) / count($recentValues);

        // ضریب روند
        $trendFactor = $average > 0 ? $recentAverage / $average : 1;

        // پیش‌بینی
        $forecast = [];
        $baseDate = new \DateTime();

        for ($i = 1; $i <= $forecastDays; $i++) {
            $baseDate->modify('+1 day');
            $predicted = round($average * $trendFactor);
            
            $forecast[] = [
                'date' => $baseDate->format('Y-m-d'),
                'predicted_value' => max(0, $predicted),
                'confidence' => max(0, min(1, 1 - ($i * 0.1))),
            ];
        }

        return [
            'forecast' => $forecast,
            'base_average' => round($average, 2),
            'recent_average' => round($recentAverage, 2),
            'trend_factor' => round($trendFactor, 2),
            'method' => 'simple_linear',
        ];
    }

    /**
     * تحلیل Cohort (گروه‌بندی کاربران)
     * 
     * @param string $table
     * @param string $userIdColumn
     * @param string $dateColumn
     * @param int $months
     * @return array
     */
    public function getCohortAnalysis(
        string $table,
        string $userIdColumn = 'user_id',
        string $dateColumn = 'created_at',
        int $months = 6
    ): array {
        $sql = "SELECT 
                    DATE_FORMAT({$dateColumn}, '%Y-%m') as cohort_month,
                    COUNT(DISTINCT {$userIdColumn}) as users_count
                FROM {$table}
                WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                GROUP BY cohort_month
                ORDER BY cohort_month ASC";

        $stmt = $this->db->query($sql, [$months]);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /**
     * محاسبه Retention Rate
     * 
     * @param string $table
     * @param string $userIdColumn
     * @param string $dateColumn
     * @return float
     */
    public function getRetentionRate(
        string $table,
        string $userIdColumn = 'user_id',
        string $dateColumn = 'created_at'
    ): float {
        $sql = "SELECT 
                    COUNT(DISTINCT CASE 
                        WHEN activity_count > 1 THEN {$userIdColumn} 
                    END) * 100.0 / COUNT(DISTINCT {$userIdColumn}) as retention_rate
                FROM (
                    SELECT 
                        {$userIdColumn},
                        COUNT(*) as activity_count
                    FROM {$table}
                    GROUP BY {$userIdColumn}
                ) as user_activities";

        $stmt = $this->db->query($sql);
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;

        return round((float)($result['retention_rate'] ?? 0), 2);
    }

    /**
     * ساعات پرترافیک
     * 
     * @param string $table
     * @param string $dateColumn
     * @param int $days
     * @return array
     */
    public function getPeakHours(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30
    ): array {
        $sql = "SELECT 
                    HOUR({$dateColumn}) as hour,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / (
                        SELECT COUNT(*) FROM {$table} 
                        WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    ), 2) as percentage
                FROM {$table}
                WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY HOUR({$dateColumn})
                ORDER BY count DESC";

        $stmt = $this->db->query($sql, [$days, $days]);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    // ==================== Private Helper Methods ====================

    /**
     * دریافت آمار یک دوره
     */
    private function getPeriodStats(
        string $table,
        string $dateColumn,
        int $offsetDays,
        int $periodDays,
        array $conditions
    ): array {
        $where = [
            "{$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            "{$dateColumn} < DATE_SUB(NOW(), INTERVAL ? DAY)"
        ];
        $params = [$offsetDays + $periodDays, $offsetDays];

        foreach ($conditions as $column => $value) {
            $where[] = "{$column} = ?";
            $params[] = $value;
        }

        $sql = "SELECT COUNT(*) as total FROM {$table} WHERE " . implode(' AND ', $where);

        $stmt = $this->db->query($sql, $params);
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;

        return [
            'total' => (int)($result['total'] ?? 0),
            'period_days' => $periodDays,
        ];
    }

    /**
     * محاسبه خلاصه روند
     */
    private function calculateTrendSummary(array $data): array
    {
        if (empty($data)) {
            return [
                'total' => 0,
                'avg_daily' => 0,
                'trend' => 'stable',
            ];
        }

        $totals = array_column($data, 'total');
        $total = array_sum($totals);
        $avg = $total / count($totals);

        // روند هفته اخیر
        $recent = array_slice($totals, -7);
        $recentAvg = count($recent) > 0 ? array_sum($recent) / count($recent) : 0;

        $trend = 'stable';
        if ($recentAvg > $avg * 1.2) {
            $trend = 'increasing';
        } elseif ($recentAvg < $avg * 0.8) {
            $trend = 'decreasing';
        }

        return [
            'total' => $total,
            'avg_daily' => round($avg, 2),
            'recent_avg' => round($recentAvg, 2),
            'trend' => $trend,
        ];
    }

    /**
     * آمار خالی
     */
    private function getEmptyStats(): array
    {
        return [
            'count' => 0,
            'mean' => 0,
            'min' => 0,
            'max' => 0,
            'stddev' => 0,
        ];
    }

    /**
     * پاک‌سازی Cache
     */
    public function clearCache(string $pattern = 'analytics:*'): void
    {
        // این متد بسته به نوع Cache متفاوت است
        // در Redis: می‌توان از pattern استفاده کرد
        // در File: باید دستی پاک شود
        
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            if ($redis) {
                $keys = $redis->keys($pattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }
        }
    }
}
