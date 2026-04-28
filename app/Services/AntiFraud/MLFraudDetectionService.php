<?php

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Logger;
use App\Models\User;
use App\Models\Transaction;

/**
 * سرویس تشخیص تقلب بر اساس Machine Learning
 * 
 * این سرویس از الگوریتم‌های ML برای تشخیص رفتارهای مشکوک استفاده می‌کند
 * بدون نیاز به کتابخانه‌های خارجی، با استفاده از الگوریتم‌های ساده و کارآمد
 */
class MLFraudDetectionService
{
    private Database $db;
    private Logger $logger;
    
    // آستانه‌های تصمیم‌گیری
    private const RISK_THRESHOLD_HIGH = 0.75;
    private const RISK_THRESHOLD_MEDIUM = 0.50;
    private const RISK_THRESHOLD_LOW = 0.25;
    
    // وزن‌های Feature ها
    private const WEIGHTS = [
        'transaction_velocity' => 0.25,
        'amount_anomaly' => 0.20,
        'time_pattern' => 0.15,
        'device_diversity' => 0.15,
        'behavior_change' => 0.15,
        'network_risk' => 0.10,
    ];
    
    // حافظه الگوها (برای caching)
    private array $userPatterns = [];
    
    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * تحلیل اصلی تقلب برای یک کاربر
     * 
     * @param int $userId شناسه کاربر
     * @param array $context اطلاعات اضافی (transaction_amount, action_type, etc)
     * @return array ['risk_score' => float, 'risk_level' => string, 'factors' => array]
     */
    public function analyzeUser(int $userId, array $context = []): array
    {
        $this->logger->info('ml_fraud.analysis_started', [
            'user_id' => $userId,
            'context' => $context
        ]);
        
        // استخراج ویژگی‌ها (Feature Extraction)
        $features = $this->extractFeatures($userId, $context);
        
        // محاسبه امتیاز ریسک
        $riskScore = $this->calculateRiskScore($features);
        
        // تعیین سطح ریسک
        $riskLevel = $this->determineRiskLevel($riskScore);
        
        // شناسایی فاکتورهای مشکوک
        $suspiciousFactors = $this->identifySuspiciousFactors($features);
        
        // ذخیره نتیجه برای یادگیری
        $this->storePrediction($userId, $riskScore, $features);
        
        $result = [
            'risk_score' => round($riskScore, 4),
            'risk_level' => $riskLevel,
            'factors' => $suspiciousFactors,
            'recommendation' => $this->getRecommendation($riskLevel),
            'features' => $features, // برای debugging
        ];
        
        $this->logger->info('ml_fraud.analysis_completed', [
            'user_id' => $userId,
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel
        ]);
        
        return $result;
    }
    
    /**
     * استخراج ویژگی‌ها از داده‌های کاربر
     */
    private function extractFeatures(int $userId, array $context): array
    {
        $features = [];
        
        // 1. Transaction Velocity (سرعت تراکنش‌ها)
        $features['transaction_velocity'] = $this->calculateTransactionVelocity($userId);
        
        // 2. Amount Anomaly (ناهنجاری مبلغ)
        $features['amount_anomaly'] = $this->detectAmountAnomaly($userId, $context['transaction_amount'] ?? 0);
        
        // 3. Time Pattern (الگوی زمانی)
        $features['time_pattern'] = $this->analyzeTimePattern($userId);
        
        // 4. Device Diversity (تنوع دستگاه‌ها)
        $features['device_diversity'] = $this->analyzeDeviceDiversity($userId);
        
        // 5. Behavior Change (تغییر رفتار)
        $features['behavior_change'] = $this->detectBehaviorChange($userId);
        
        // 6. Network Risk (ریسک شبکه)
        $features['network_risk'] = $this->analyzeNetworkRisk($userId);
        
        return $features;
    }
    
    /**
     * محاسبه سرعت تراکنش‌ها (Velocity Check)
     * بررسی تعداد تراکنش‌ها در بازه‌های زمانی مختلف
     */
    private function calculateTransactionVelocity(int $userId): float
    {
        $velocityScore = 0.0;
        
        // تعداد تراکنش‌ها در 1 ساعت گذشته
        $txn1h = $this->getTransactionCount($userId, 1);
        // تعداد تراکنش‌ها در 24 ساعت گذشته
        $txn24h = $this->getTransactionCount($userId, 24);
        // تعداد تراکنش‌ها در 7 روز گذشته
        $txn7d = $this->getTransactionCount($userId, 168);
        
        // محاسبه میانگین معمولی کاربر
        $avgDaily = $this->getUserAverageDaily($userId);
        
        // اگر بیش از 10 تراکنش در 1 ساعت → خیلی مشکوک
        if ($txn1h > 10) {
            $velocityScore += 0.5;
        } elseif ($txn1h > 5) {
            $velocityScore += 0.3;
        }
        
        // اگر تراکنش امروز 3 برابر میانگین باشد → مشکوک
        if ($avgDaily > 0 && $txn24h > ($avgDaily * 3)) {
            $velocityScore += 0.3;
        }
        
        // اگر افزایش ناگهانی در هفته اخیر
        if ($avgDaily > 0 && ($txn7d / 7) > ($avgDaily * 2)) {
            $velocityScore += 0.2;
        }
        
        return min(1.0, $velocityScore);
    }
    
    /**
     * تشخیص ناهنجاری در مبلغ تراکنش
     */
    private function detectAmountAnomaly(int $userId, float $currentAmount): float
    {
        if ($currentAmount <= 0) {
            return 0.0;
        }
        
        // محاسبه میانگین و انحراف معیار مبالغ
        $stats = $this->getTransactionAmountStats($userId);
        
        if ($stats['count'] < 5) {
            // اگر تاریخچه کافی نداریم
            return 0.1;
        }
        
        $mean = $stats['mean'];
        $stdDev = $stats['std_dev'];
        
        if ($stdDev == 0) {
            return 0.0;
        }
        
        // محاسبه Z-Score
        $zScore = abs(($currentAmount - $mean) / $stdDev);
        
        // اگر بیش از 3 انحراف معیار باشد → خیلی غیرعادی
        if ($zScore > 3.0) {
            return 0.9;
        } elseif ($zScore > 2.0) {
            return 0.6;
        } elseif ($zScore > 1.5) {
            return 0.3;
        }
        
        return 0.0;
    }
    
    /**
     * تحلیل الگوی زمانی (آیا در ساعات غیرعادی فعالیت می‌کند؟)
     */
    private function analyzeTimePattern(int $userId): float
    {
        $sql = "
            SELECT HOUR(created_at) as hour, COUNT(*) as count
            FROM transactions
            WHERE user_id = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY HOUR(created_at)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $hourlyActivity = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        $suspicionScore = 0.0;
        $totalActivity = array_sum($hourlyActivity);
        
        if ($totalActivity == 0) {
            return 0.0;
        }
        
        // ساعات 2-6 صبح → مشکوک‌تر
        $lateNightActivity = 0;
        for ($h = 2; $h <= 6; $h++) {
            $lateNightActivity += $hourlyActivity[$h] ?? 0;
        }
        
        $lateNightRatio = $lateNightActivity / $totalActivity;
        
        if ($lateNightRatio > 0.4) {
            $suspicionScore = 0.7;
        } elseif ($lateNightRatio > 0.2) {
            $suspicionScore = 0.4;
        }
        
        return $suspicionScore;
    }
    
    /**
     * تحلیل تنوع دستگاه‌ها
     */
    private function analyzeDeviceDiversity(int $userId): float
    {
        $sql = "
            SELECT COUNT(DISTINCT device_fingerprint) as device_count
            FROM transactions
            WHERE user_id = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND device_fingerprint IS NOT NULL
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $deviceCount = (int)$stmt->fetchColumn();
        
        // اگر بیش از 5 دستگاه مختلف در هفته → مشکوک
        if ($deviceCount > 5) {
            return 0.8;
        } elseif ($deviceCount > 3) {
            return 0.5;
        }
        
        return 0.0;
    }
    
    /**
     * تشخیص تغییر ناگهانی رفتار
     */
    private function detectBehaviorChange(int $userId): float
    {
        // مقایسه رفتار 7 روز اخیر با 30 روز قبل
        
        $recentBehavior = $this->getBehaviorMetrics($userId, 7);
        $historicalBehavior = $this->getBehaviorMetrics($userId, 30, 7);
        
        if ($historicalBehavior['transaction_count'] < 10) {
            return 0.0; // تاریخچه کافی نداریم
        }
        
        $changeScore = 0.0;
        
        // تغییر در میانگین مبلغ
        if ($historicalBehavior['avg_amount'] > 0) {
            $amountChange = abs(
                ($recentBehavior['avg_amount'] - $historicalBehavior['avg_amount']) 
                / $historicalBehavior['avg_amount']
            );
            
            if ($amountChange > 2.0) {
                $changeScore += 0.4;
            } elseif ($amountChange > 1.0) {
                $changeScore += 0.2;
            }
        }
        
        // تغییر در فرکانس
        $recentFrequency = $recentBehavior['transaction_count'] / 7;
        $historicalFrequency = $historicalBehavior['transaction_count'] / 30;
        
        if ($historicalFrequency > 0) {
            $frequencyChange = abs(
                ($recentFrequency - $historicalFrequency) / $historicalFrequency
            );
            
            if ($frequencyChange > 3.0) {
                $changeScore += 0.4;
            } elseif ($frequencyChange > 1.5) {
                $changeScore += 0.2;
            }
        }
        
        return min(1.0, $changeScore);
    }
    
    /**
     * تحلیل ریسک شبکه (آیا با کاربران مشکوک دیگر مرتبط است؟)
     */
    private function analyzeNetworkRisk(int $userId): float
    {
        // بررسی معرف (referrer)
        $sql = "SELECT referred_by, fraud_score FROM users WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_OBJ);
        
        $networkScore = 0.0;
        
        if ($user && $user->referred_by) {
            // بررسی fraud score معرف
            $referrerSql = "SELECT fraud_score, is_blacklisted FROM users WHERE id = ?";
            $stmt = $this->db->prepare($referrerSql);
            $stmt->execute([$user->referred_by]);
            $referrer = $stmt->fetch(\PDO::FETCH_OBJ);
            
            if ($referrer) {
                if ($referrer->is_blacklisted) {
                    $networkScore += 0.6;
                }
                
                if (isset($referrer->fraud_score) && $referrer->fraud_score > 70) {
                    $networkScore += 0.3;
                }
            }
        }
        
        // بررسی IP مشترک
        $sharedIPScore = $this->analyzeSharedIP($userId);
        $networkScore += $sharedIPScore * 0.4;
        
        return min(1.0, $networkScore);
    }
    
    /**
     * بررسی IP های مشترک با کاربران مشکوک
     */
    private function analyzeSharedIP(int $userId): float
    {
        $sql = "
            SELECT t.ip_address, COUNT(DISTINCT t.user_id) as user_count,
                   SUM(CASE WHEN u.fraud_score > 70 THEN 1 ELSE 0 END) as suspicious_users
            FROM transactions t
            JOIN users u ON t.user_id = u.id
            WHERE t.user_id = ?
            AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND t.ip_address IS NOT NULL
            GROUP BY t.ip_address
            HAVING user_count > 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $ipData = $stmt->fetchAll(\PDO::FETCH_OBJ);
        
        $suspicionScore = 0.0;
        
        foreach ($ipData as $ip) {
            // اگر با بیش از 5 کاربر مختلف IP مشترک داشته
            if ($ip->user_count > 5) {
                $suspicionScore += 0.4;
            }
            
            // اگر با کاربران مشکوک IP مشترک داشته
            if ($ip->suspicious_users > 0) {
                $suspicionScore += 0.5;
            }
        }
        
        return min(1.0, $suspicionScore);
    }
    
    /**
     * محاسبه امتیاز نهایی ریسک با استفاده از وزن‌ها
     */
    private function calculateRiskScore(array $features): float
    {
        $totalScore = 0.0;
        
        foreach (self::WEIGHTS as $feature => $weight) {
            $totalScore += ($features[$feature] ?? 0.0) * $weight;
        }
        
        return min(1.0, max(0.0, $totalScore));
    }
    
    /**
     * تعیین سطح ریسک
     */
    private function determineRiskLevel(float $score): string
    {
        if ($score >= self::RISK_THRESHOLD_HIGH) {
            return 'high';
        } elseif ($score >= self::RISK_THRESHOLD_MEDIUM) {
            return 'medium';
        } elseif ($score >= self::RISK_THRESHOLD_LOW) {
            return 'low';
        }
        
        return 'safe';
    }
    
    /**
     * شناسایی فاکتورهای مشکوک
     */
    private function identifySuspiciousFactors(array $features): array
    {
        $suspicious = [];
        
        foreach ($features as $feature => $score) {
            if ($score > 0.5) {
                $suspicious[] = [
                    'factor' => $feature,
                    'score' => round($score, 2),
                    'severity' => $score > 0.75 ? 'high' : 'medium'
                ];
            }
        }
        
        return $suspicious;
    }
    
    /**
     * توصیه عملیاتی بر اساس سطح ریسک
     */
    private function getRecommendation(string $riskLevel): string
    {
        return match($riskLevel) {
            'high' => 'block_transaction',
            'medium' => 'manual_review',
            'low' => 'monitor',
            default => 'allow'
        };
    }
    
    // ==================== Helper Methods ====================
    
    private function getTransactionCount(int $userId, int $hours): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM transactions 
            WHERE user_id = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $hours]);
        return (int)$stmt->fetchColumn();
    }
    
    private function getUserAverageDaily(int $userId): float
    {
        $sql = "
            SELECT COUNT(*) / GREATEST(DATEDIFF(NOW(), MIN(created_at)), 1) as avg_daily
            FROM transactions
            WHERE user_id = ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return (float)$stmt->fetchColumn();
    }
    
    private function getTransactionAmountStats(int $userId): array
    {
        $sql = "
            SELECT 
                COUNT(*) as count,
                AVG(amount) as mean,
                STDDEV(amount) as std_dev
            FROM transactions
            WHERE user_id = ?
            AND amount > 0
            AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return [
            'count' => (int)($result['count'] ?? 0),
            'mean' => (float)($result['mean'] ?? 0),
            'std_dev' => (float)($result['std_dev'] ?? 0),
        ];
    }
    
    private function getBehaviorMetrics(int $userId, int $days, int $offset = 0): array
    {
        $sql = "
            SELECT 
                COUNT(*) as transaction_count,
                AVG(amount) as avg_amount,
                SUM(amount) as total_amount
            FROM transactions
            WHERE user_id = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $days + $offset, $offset]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return [
            'transaction_count' => (int)($result['transaction_count'] ?? 0),
            'avg_amount' => (float)($result['avg_amount'] ?? 0),
            'total_amount' => (float)($result['total_amount'] ?? 0),
        ];
    }
    
    /**
     * ذخیره پیش‌بینی برای یادگیری و بهبود مدل
     */
    private function storePrediction(int $userId, float $riskScore, array $features): void
    {
        try {
            $sql = "
                INSERT INTO ml_fraud_predictions 
                (user_id, risk_score, features, created_at)
                VALUES (?, ?, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $userId,
                $riskScore,
                json_encode($features)
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('ml_fraud.store_prediction_failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * به‌روزرسانی مدل با feedback (برای یادگیری)
     */
    public function provideFeedback(int $userId, string $actualOutcome): void
    {
        // actualOutcome: 'fraud' یا 'legitimate'
        
        $sql = "
            UPDATE ml_fraud_predictions
            SET actual_outcome = ?, updated_at = NOW()
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$actualOutcome, $userId]);
        
        $this->logger->info('ml_fraud.feedback_received', [
            'user_id' => $userId,
            'outcome' => $actualOutcome
        ]);
    }
}
