<?php

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Logger;
use Core\Cache;

/**
 * سرویس بررسی سرعت تراکنش‌ها (Velocity Checks)
 * 
 * این سرویس محدودیت‌های سرعت برای انواع مختلف عملیات را بررسی می‌کند
 * و از سوءاستفاده‌های احتمالی جلوگیری می‌کند
 */
class VelocityCheckService
{
    private Database $db;
    private Logger $logger;
    private Cache $cache;
    
    // قوانین پیش‌فرض Velocity
    private const DEFAULT_RULES = [
        // واریز
        'deposit' => [
            '1h' => ['limit' => 5, 'period' => 3600],
            '24h' => ['limit' => 20, 'period' => 86400],
            '7d' => ['limit' => 50, 'period' => 604800],
        ],
        
        // برداشت
        'withdrawal' => [
            '1h' => ['limit' => 3, 'period' => 3600],
            '24h' => ['limit' => 10, 'period' => 86400],
            '7d' => ['limit' => 30, 'period' => 604800],
        ],
        
        // انتقال
        'transfer' => [
            '1h' => ['limit' => 10, 'period' => 3600],
            '24h' => ['limit' => 50, 'period' => 86400],
            '7d' => ['limit' => 200, 'period' => 604800],
        ],
        
        // تسک‌های اجتماعی
        'social_task' => [
            '1h' => ['limit' => 20, 'period' => 3600],
            '24h' => ['limit' => 100, 'period' => 86400],
            '7d' => ['limit' => 500, 'period' => 604800],
        ],
        
        // لاگین
        'login' => [
            '5m' => ['limit' => 5, 'period' => 300],
            '1h' => ['limit' => 10, 'period' => 3600],
            '24h' => ['limit' => 30, 'period' => 86400],
        ],
        
        // تغییر رمز
        'password_change' => [
            '1h' => ['limit' => 2, 'period' => 3600],
            '24h' => ['limit' => 5, 'period' => 86400],
            '7d' => ['limit' => 10, 'period' => 604800],
        ],
    ];
    
    // محدودیت‌های مبلغ
    private const AMOUNT_LIMITS = [
        'deposit' => [
            '1h' => 50000000, // 50 میلیون تومان در ساعت
            '24h' => 200000000, // 200 میلیون در روز
            '7d' => 1000000000, // 1 میلیارد در هفته
        ],
        'withdrawal' => [
            '1h' => 20000000,
            '24h' => 100000000,
            '7d' => 500000000,
        ],
    ];
    
    public function __construct(Database $db, Logger $logger, Cache $cache)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
    }
    
    /**
     * بررسی سرعت برای یک عملیات
     * 
     * @param int $userId شناسه کاربر
     * @param string $actionType نوع عملیات (deposit, withdrawal, etc.)
     * @param array $context اطلاعات اضافی (amount, currency, etc.)
     * @return array ['allowed' => bool, 'reason' => string, 'remaining' => int, 'reset_at' => int]
     */
    public function check(int $userId, string $actionType, array $context = []): array
    {
        $this->logger->info('velocity.check_started', [
            'user_id' => $userId,
            'action_type' => $actionType
        ]);
        
        // بررسی count-based velocity
        $countCheck = $this->checkCountVelocity($userId, $actionType);
        if (!$countCheck['allowed']) {
            return $countCheck;
        }
        
        // بررسی amount-based velocity (برای تراکنش‌های مالی)
        if (isset($context['amount']) && $this->hasAmountLimit($actionType)) {
            $amountCheck = $this->checkAmountVelocity(
                $userId, 
                $actionType, 
                (float)$context['amount']
            );
            
            if (!$amountCheck['allowed']) {
                return $amountCheck;
            }
        }
        
        // بررسی pattern-based velocity (الگوهای مشکوک)
        $patternCheck = $this->checkPatternVelocity($userId, $actionType, $context);
        if (!$patternCheck['allowed']) {
            return $patternCheck;
        }
        
        return [
            'allowed' => true,
            'reason' => null,
            'remaining' => $this->getRemainingCount($userId, $actionType),
        ];
    }
    
    /**
     * بررسی velocity بر اساس تعداد
     */
    private function checkCountVelocity(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        
        if (empty($rules)) {
            return ['allowed' => true];
        }
        
        foreach ($rules as $period => $config) {
            $limit = $config['limit'];
            $seconds = $config['period'];
            
            // بررسی cache برای سرعت بیشتر
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $count = $this->cache->get($cacheKey);
            
            if ($count === null) {
                // اگر cache نداشتیم، از دیتابیس بگیریم
                $count = $this->getCountFromDB($userId, $actionType, $seconds);
                $this->cache->set($cacheKey, $count, $seconds);
            }
            
            if ($count >= $limit) {
                $this->logger->warning('velocity.limit_exceeded', [
                    'user_id' => $userId,
                    'action_type' => $actionType,
                    'period' => $period,
                    'count' => $count,
                    'limit' => $limit
                ]);
                
                return [
                    'allowed' => false,
                    'reason' => "محدودیت تعداد در {$period} رسیده است",
                    'limit' => $limit,
                    'current' => $count,
                    'period' => $period,
                    'reset_at' => time() + $seconds,
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * بررسی velocity بر اساس مبلغ
     */
    private function checkAmountVelocity(int $userId, string $actionType, float $amount): array
    {
        $limits = $this->getAmountLimits($actionType);
        
        if (empty($limits)) {
            return ['allowed' => true];
        }
        
        foreach ($limits as $period => $maxAmount) {
            $seconds = $this->periodToSeconds($period);
            
            $currentTotal = $this->getTotalAmount($userId, $actionType, $seconds);
            $projectedTotal = $currentTotal + $amount;
            
            if ($projectedTotal > $maxAmount) {
                $this->logger->warning('velocity.amount_limit_exceeded', [
                    'user_id' => $userId,
                    'action_type' => $actionType,
                    'period' => $period,
                    'current_total' => $currentTotal,
                    'requested_amount' => $amount,
                    'projected_total' => $projectedTotal,
                    'limit' => $maxAmount
                ]);
                
                return [
                    'allowed' => false,
                    'reason' => "محدودیت مبلغ در {$period} رسیده است",
                    'limit' => $maxAmount,
                    'current_total' => $currentTotal,
                    'requested_amount' => $amount,
                    'remaining_amount' => max(0, $maxAmount - $currentTotal),
                    'period' => $period,
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * بررسی الگوهای مشکوک
     */
    private function checkPatternVelocity(int $userId, string $actionType, array $context): array
    {
        // 1. بررسی تراکنش‌های یکسان متوالی
        if ($this->detectRepeatedTransactions($userId, $actionType, $context)) {
            return [
                'allowed' => false,
                'reason' => 'الگوی تراکنش‌های تکراری شناسایی شد',
                'pattern' => 'repeated_transactions'
            ];
        }
        
        // 2. بررسی burst pattern (انفجار ناگهانی)
        if ($this->detectBurstPattern($userId, $actionType)) {
            return [
                'allowed' => false,
                'reason' => 'افزایش ناگهانی در تراکنش‌ها شناسایی شد',
                'pattern' => 'burst'
            ];
        }
        
        // 3. بررسی round-number pattern (مبالغ گرد)
        if (isset($context['amount']) && $this->detectRoundNumberPattern($userId, $context['amount'])) {
            return [
                'allowed' => false,
                'reason' => 'الگوی مبالغ گرد مشکوک',
                'pattern' => 'round_numbers'
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * تشخیص تراکنش‌های تکراری
     */
    private function detectRepeatedTransactions(int $userId, string $actionType, array $context): bool
    {
        if (!isset($context['amount'])) {
            return false;
        }
        
        $amount = $context['amount'];
        
        // بررسی آیا 3 تراکنش با همان مبلغ در 10 دقیقه گذشته داشته
        $sql = "
            SELECT COUNT(*) as count
            FROM transactions
            WHERE user_id = ?
            AND type = ?
            AND amount = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $actionType, $amount]);
        $count = (int)$stmt->fetchColumn();
        
        return $count >= 3;
    }
    
    /**
     * تشخیص الگوی انفجاری (burst)
     */
    private function detectBurstPattern(int $userId, string $actionType): bool
    {
        // مقایسه 5 دقیقه اخیر با میانگین 24 ساعت
        
        $recent = $this->getCountFromDB($userId, $actionType, 300); // 5 دقیقه
        $historical = $this->getCountFromDB($userId, $actionType, 86400); // 24 ساعت
        
        $avgPer5Min = $historical / 288; // 24 ساعت = 288 بازه 5 دقیقه‌ای
        
        // اگر 5 دقیقه اخیر 5 برابر میانگین باشد
        if ($avgPer5Min > 0 && $recent > ($avgPer5Min * 5)) {
            $this->logger->warning('velocity.burst_detected', [
                'user_id' => $userId,
                'action_type' => $actionType,
                'recent_count' => $recent,
                'avg_per_5min' => $avgPer5Min
            ]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * تشخیص الگوی مبالغ گرد (معمولاً مشکوک)
     */
    private function detectRoundNumberPattern(int $userId, float $amount): bool
    {
        // بررسی آیا مبلغ عدد گرد است (مثلاً 1000000, 500000, 10000)
        $roundNumbers = [10000, 50000, 100000, 500000, 1000000, 5000000, 10000000];
        
        if (in_array($amount, $roundNumbers)) {
            // بررسی آیا 80% تراکنش‌های اخیر هم گرد بوده
            $sql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN amount IN (10000, 50000, 100000, 500000, 1000000, 5000000, 10000000) 
                        THEN 1 ELSE 0 END) as round_count
                FROM transactions
                WHERE user_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($stats['total'] >= 5) {
                $roundRatio = $stats['round_count'] / $stats['total'];
                
                if ($roundRatio > 0.8) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * ثبت یک عملیات موفق
     */
    public function record(int $userId, string $actionType, array $context = []): void
    {
        // افزایش counter در cache
        $rules = $this->getRules($actionType);
        
        foreach ($rules as $period => $config) {
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $current = $this->cache->get($cacheKey) ?? 0;
            $this->cache->set($cacheKey, $current + 1, $config['period']);
        }
        
        $this->logger->info('velocity.recorded', [
            'user_id' => $userId,
            'action_type' => $actionType,
            'context' => $context
        ]);
    }
    
    /**
     * تنظیم سفارشی قوانین
     */
    public function setCustomRules(string $actionType, array $rules): void
    {
        $cacheKey = "velocity:rules:{$actionType}";
        $this->cache->set($cacheKey, $rules, 86400);
    }
    
    /**
     * Reset کردن velocity برای یک کاربر (استفاده ادمین)
     */
    public function reset(int $userId, string $actionType): void
    {
        $rules = $this->getRules($actionType);
        
        foreach ($rules as $period => $config) {
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $this->cache->delete($cacheKey);
        }
        
        $this->logger->info('velocity.reset', [
            'user_id' => $userId,
            'action_type' => $actionType
        ]);
    }
    
    /**
     * گزارش وضعیت velocity برای یک کاربر
     */
    public function getStatus(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        $status = [];
        
        foreach ($rules as $period => $config) {
            $count = $this->getCountFromDB($userId, $actionType, $config['period']);
            $limit = $config['limit'];
            
            $status[$period] = [
                'count' => $count,
                'limit' => $limit,
                'remaining' => max(0, $limit - $count),
                'percentage' => min(100, round(($count / $limit) * 100, 2)),
            ];
        }
        
        return $status;
    }
    
    // ==================== Helper Methods ====================
    
    private function getRules(string $actionType): array
    {
        // بررسی cache برای قوانین سفارشی
        $cacheKey = "velocity:rules:{$actionType}";
        $customRules = $this->cache->get($cacheKey);
        
        if ($customRules !== null) {
            return $customRules;
        }
        
        return self::DEFAULT_RULES[$actionType] ?? [];
    }
    
    private function getAmountLimits(string $actionType): array
    {
        return self::AMOUNT_LIMITS[$actionType] ?? [];
    }
    
    private function hasAmountLimit(string $actionType): bool
    {
        return isset(self::AMOUNT_LIMITS[$actionType]);
    }
    
    private function getCountFromDB(int $userId, string $actionType, int $seconds): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM transactions 
            WHERE user_id = ? 
            AND type = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $actionType, $seconds]);
        return (int)$stmt->fetchColumn();
    }
    
    private function getTotalAmount(int $userId, string $actionType, int $seconds): float
    {
        $sql = "
            SELECT COALESCE(SUM(amount), 0)
            FROM transactions
            WHERE user_id = ?
            AND type = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $actionType, $seconds]);
        return (float)$stmt->fetchColumn();
    }
    
    private function getRemainingCount(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        $remaining = [];
        
        foreach ($rules as $period => $config) {
            $count = $this->getCountFromDB($userId, $actionType, $config['period']);
            $remaining[$period] = max(0, $config['limit'] - $count);
        }
        
        return $remaining;
    }
    
    private function periodToSeconds(string $period): int
    {
        return match($period) {
            '5m' => 300,
            '1h' => 3600,
            '24h' => 86400,
            '7d' => 604800,
            default => 0
        };
    }
}
