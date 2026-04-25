<?php

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Logger;

/**
 * سرویس تحلیل گراف برای تشخیص شبکه‌های تقلب
 * 
 * این سرویس از تکنیک‌های Graph Theory برای شناسایی:
 * - شبکه‌های کاربران مرتبط
 * - کلاسترهای مشکوک
 * - الگوهای انتقال پول
 * - حلقه‌های مشکوک (Suspicious Rings)
 */
class GraphAnalysisService
{
    private Database $db;
    private Logger $logger;
    
    // آستانه‌های تشخیص
    private const CLUSTER_MIN_SIZE = 3;
    private const CLUSTER_FRAUD_RATIO = 0.5; // 50% از اعضا مشکوک باشند
    private const MAX_SHARED_IP_USERS = 5;
    private const CIRCULAR_TRANSACTION_THRESHOLD = 3;
    
    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * تحلیل شبکه یک کاربر
     * 
     * @param int $userId شناسه کاربر
     * @param int $depth عمق جستجو (پیش‌فرض: 2 hop)
     * @return array اطلاعات شبکه و ریسک
     */
    public function analyzeUserNetwork(int $userId, int $depth = 2): array
    {
        $this->logger->info('graph.analyze_started', [
            'user_id' => $userId,
            'depth' => $depth
        ]);
        
        // ساخت گراف
        $graph = $this->buildUserGraph($userId, $depth);
        
        // تحلیل‌های مختلف
        $analysis = [
            'user_id' => $userId,
            'network_size' => count($graph['nodes']),
            'connection_count' => count($graph['edges']),
            
            // تحلیل کلاستر
            'cluster_analysis' => $this->analyzeCluster($graph),
            
            // تحلیل IP مشترک
            'ip_sharing' => $this->analyzeIPSharing($userId),
            
            // تحلیل تراکنش‌های دایره‌ای
            'circular_transactions' => $this->detectCircularTransactions($userId),
            
            // محاسبه Centrality
            'centrality' => $this->calculateCentrality($userId, $graph),
            
            // تشخیص Bot Network
            'bot_network_risk' => $this->detectBotNetwork($graph),
        ];
        
        // محاسبه ریسک کلی
        $analysis['overall_risk'] = $this->calculateNetworkRisk($analysis);
        
        $this->logger->info('graph.analyze_completed', [
            'user_id' => $userId,
            'risk_score' => $analysis['overall_risk']
        ]);
        
        return $analysis;
    }
    
    /**
     * ساخت گراف کاربران (BFS)
     */
    private function buildUserGraph(int $userId, int $maxDepth): array
    {
        $graph = [
            'nodes' => [],
            'edges' => [],
        ];
        
        $visited = [];
        $queue = [['id' => $userId, 'depth' => 0]];
        
        while (!empty($queue)) {
            $current = array_shift($queue);
            $currentId = $current['id'];
            $currentDepth = $current['depth'];
            
            if (isset($visited[$currentId]) || $currentDepth > $maxDepth) {
                continue;
            }
            
            $visited[$currentId] = true;
            
            // اضافه کردن node
            $userInfo = $this->getUserInfo($currentId);
            $graph['nodes'][$currentId] = $userInfo;
            
            if ($currentDepth < $maxDepth) {
                // پیدا کردن همسایه‌ها
                $neighbors = $this->getNeighbors($currentId);
                
                foreach ($neighbors as $neighbor) {
                    $neighborId = $neighbor['user_id'];
                    
                    // اضافه کردن edge
                    $graph['edges'][] = [
                        'from' => $currentId,
                        'to' => $neighborId,
                        'type' => $neighbor['connection_type'],
                        'weight' => $neighbor['strength'],
                    ];
                    
                    // اضافه به صف
                    if (!isset($visited[$neighborId])) {
                        $queue[] = [
                            'id' => $neighborId,
                            'depth' => $currentDepth + 1
                        ];
                    }
                }
            }
        }
        
        return $graph;
    }
    
    /**
     * دریافت اطلاعات یک کاربر
     */
    private function getUserInfo(int $userId): array
    {
        $sql = "
            SELECT 
                id, 
                fraud_score, 
                is_blacklisted,
                status,
                created_at
            FROM users
            WHERE id = ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return [
            'id' => $user['id'],
            'fraud_score' => (int)($user['fraud_score'] ?? 0),
            'is_blacklisted' => (bool)($user['is_blacklisted'] ?? false),
            'status' => $user['status'],
            'age_days' => $this->calculateAccountAge($user['created_at']),
        ];
    }
    
    /**
     * پیدا کردن همسایه‌های یک کاربر
     */
    private function getNeighbors(int $userId): array
    {
        $neighbors = [];
        
        // 1. Referral connections
        $referralNeighbors = $this->getReferralConnections($userId);
        $neighbors = array_merge($neighbors, $referralNeighbors);
        
        // 2. Transaction connections
        $transactionNeighbors = $this->getTransactionConnections($userId);
        $neighbors = array_merge($neighbors, $transactionNeighbors);
        
        // 3. IP-based connections
        $ipNeighbors = $this->getIPConnections($userId);
        $neighbors = array_merge($neighbors, $ipNeighbors);
        
        // حذف تکراری‌ها
        $uniqueNeighbors = [];
        foreach ($neighbors as $neighbor) {
            $key = $neighbor['user_id'];
            if (!isset($uniqueNeighbors[$key])) {
                $uniqueNeighbors[$key] = $neighbor;
            } else {
                // اگر تکراری بود، وزن رو جمع می‌کنیم
                $uniqueNeighbors[$key]['strength'] += $neighbor['strength'];
            }
        }
        
        return array_values($uniqueNeighbors);
    }
    
    /**
     * ارتباطات referral
     */
    private function getReferralConnections(int $userId): array
    {
        $sql = "
            SELECT id as user_id, 'referral' as connection_type, 3 as strength
            FROM users
            WHERE referred_by = ?
            
            UNION
            
            SELECT referred_by as user_id, 'referred_by' as connection_type, 2 as strength
            FROM users
            WHERE id = ? AND referred_by IS NOT NULL
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * ارتباطات تراکنشی
     */
    private function getTransactionConnections(int $userId): array
    {
        // کاربرانی که با آن‌ها تراکنش مالی داشته
        $sql = "
            SELECT 
                CASE 
                    WHEN from_user_id = ? THEN to_user_id
                    ELSE from_user_id
                END as user_id,
                'transaction' as connection_type,
                COUNT(*) as strength
            FROM transactions
            WHERE (from_user_id = ? OR to_user_id = ?)
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY user_id
            HAVING strength >= 2
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId, $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * ارتباطات IP مشترک
     */
    private function getIPConnections(int $userId): array
    {
        $sql = "
            SELECT DISTINCT t2.user_id, 'shared_ip' as connection_type, 1 as strength
            FROM transactions t1
            JOIN transactions t2 ON t1.ip_address = t2.ip_address
            WHERE t1.user_id = ?
            AND t2.user_id != ?
            AND t1.ip_address IS NOT NULL
            AND t1.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * تحلیل کلاستر (خوشه)
     */
    private function analyzeCluster(array $graph): array
    {
        $nodes = $graph['nodes'];
        
        if (count($nodes) < self::CLUSTER_MIN_SIZE) {
            return [
                'is_cluster' => false,
                'size' => count($nodes),
            ];
        }
        
        // شمارش کاربران مشکوک
        $suspiciousCount = 0;
        $blacklistedCount = 0;
        $totalFraudScore = 0;
        
        foreach ($nodes as $node) {
            if ($node['fraud_score'] > 70) {
                $suspiciousCount++;
            }
            
            if ($node['is_blacklisted']) {
                $blacklistedCount++;
            }
            
            $totalFraudScore += $node['fraud_score'];
        }
        
        $avgFraudScore = $totalFraudScore / count($nodes);
        $fraudRatio = $suspiciousCount / count($nodes);
        
        $isSuspiciousCluster = (
            $fraudRatio >= self::CLUSTER_FRAUD_RATIO ||
            $blacklistedCount >= 2 ||
            $avgFraudScore > 60
        );
        
        return [
            'is_cluster' => true,
            'is_suspicious' => $isSuspiciousCluster,
            'size' => count($nodes),
            'suspicious_count' => $suspiciousCount,
            'blacklisted_count' => $blacklistedCount,
            'avg_fraud_score' => round($avgFraudScore, 2),
            'fraud_ratio' => round($fraudRatio, 2),
        ];
    }
    
    /**
     * تحلیل اشتراک IP
     */
    private function analyzeIPSharing(int $userId): array
    {
        $sql = "
            SELECT 
                t.ip_address,
                COUNT(DISTINCT t.user_id) as user_count,
                GROUP_CONCAT(DISTINCT t.user_id) as user_ids
            FROM transactions t
            WHERE t.ip_address IN (
                SELECT DISTINCT ip_address 
                FROM transactions 
                WHERE user_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            )
            AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY t.ip_address
            HAVING user_count > 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $sharedIPs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $suspicious = [];
        foreach ($sharedIPs as $ip) {
            if ($ip['user_count'] > self::MAX_SHARED_IP_USERS) {
                $suspicious[] = [
                    'ip' => $ip['ip_address'],
                    'user_count' => $ip['user_count'],
                ];
            }
        }
        
        return [
            'shared_ip_count' => count($sharedIPs),
            'suspicious_ips' => $suspicious,
            'is_suspicious' => !empty($suspicious),
        ];
    }
    
    /**
     * تشخیص تراکنش‌های دایره‌ای (Circular Transactions)
     */
    private function detectCircularTransactions(int $userId): array
    {
        // الگوریتم: A -> B -> C -> A
        
        $circles = [];
        
        // پیدا کردن مسیرهای دایره‌ای با DFS
        $sql = "
            WITH RECURSIVE paths AS (
                -- شروع از کاربر
                SELECT 
                    from_user_id as start_user,
                    to_user_id as current_user,
                    CAST(from_user_id AS CHAR(1000)) as path,
                    1 as depth,
                    amount
                FROM transactions
                WHERE from_user_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                
                UNION ALL
                
                -- ادامه مسیر
                SELECT 
                    p.start_user,
                    t.to_user_id,
                    CONCAT(p.path, '->', t.to_user_id),
                    p.depth + 1,
                    p.amount
                FROM paths p
                JOIN transactions t ON p.current_user = t.from_user_id
                WHERE p.depth < 5
                AND FIND_IN_SET(t.to_user_id, REPLACE(p.path, '->', ',')) = 0
                AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            )
            SELECT * FROM paths
            WHERE current_user = start_user
            AND depth >= ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, self::CIRCULAR_TRANSACTION_THRESHOLD]);
        $circularPaths = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return [
            'detected' => !empty($circularPaths),
            'count' => count($circularPaths),
            'paths' => array_slice($circularPaths, 0, 5), // فقط 5 مورد اول
        ];
    }
    
    /**
     * محاسبه Centrality (اهمیت در شبکه)
     */
    private function calculateCentrality(int $userId, array $graph): array
    {
        $edges = $graph['edges'];
        
        // Degree Centrality (تعداد اتصالات)
        $degree = 0;
        foreach ($edges as $edge) {
            if ($edge['from'] == $userId || $edge['to'] == $userId) {
                $degree++;
            }
        }
        
        // Weighted Degree
        $weightedDegree = 0;
        foreach ($edges as $edge) {
            if ($edge['from'] == $userId || $edge['to'] == $userId) {
                $weightedDegree += $edge['weight'];
            }
        }
        
        return [
            'degree' => $degree,
            'weighted_degree' => $weightedDegree,
            'is_hub' => $degree > 10, // اگر بیش از 10 اتصال داشته
        ];
    }
    
    /**
     * تشخیص Bot Network
     */
    private function detectBotNetwork(array $graph): float
    {
        $nodes = $graph['nodes'];
        
        if (count($nodes) < 3) {
            return 0.0;
        }
        
        $botLikeCount = 0;
        
        foreach ($nodes as $node) {
            // نشانه‌های bot:
            // 1. حساب جدید (کمتر از 7 روز)
            // 2. fraud_score بالا
            // 3. الگوی فعالیت یکسان
            
            if ($node['age_days'] < 7 && $node['fraud_score'] > 50) {
                $botLikeCount++;
            }
        }
        
        $botRatio = $botLikeCount / count($nodes);
        
        // اگر بیش از 50% شبیه bot باشند
        return $botRatio > 0.5 ? $botRatio : 0.0;
    }
    
    /**
     * محاسبه ریسک کلی شبکه
     */
    private function calculateNetworkRisk(array $analysis): float
    {
        $riskScore = 0.0;
        
        // کلاستر مشکوک
        if ($analysis['cluster_analysis']['is_suspicious'] ?? false) {
            $riskScore += 0.3;
        }
        
        // IP مشترک مشکوک
        if ($analysis['ip_sharing']['is_suspicious']) {
            $riskScore += 0.2;
        }
        
        // تراکنش‌های دایره‌ای
        if ($analysis['circular_transactions']['detected']) {
            $riskScore += 0.25;
        }
        
        // Hub (مرکز شبکه)
        if ($analysis['centrality']['is_hub']) {
            $riskScore += 0.15;
        }
        
        // Bot Network
        $riskScore += $analysis['bot_network_risk'] * 0.1;
        
        return min(1.0, $riskScore);
    }
    
    /**
     * تشخیص شبکه‌های Sybil Attack
     */
    public function detectSybilNetwork(int $userId): array
    {
        // Sybil: یک نفر چندین حساب می‌سازد
        
        // بررسی device fingerprint مشترک
        $sql = "
            SELECT device_fingerprint, COUNT(DISTINCT id) as account_count
            FROM users
            WHERE device_fingerprint IN (
                SELECT device_fingerprint 
                FROM users 
                WHERE id = ?
            )
            GROUP BY device_fingerprint
            HAVING account_count > 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $deviceSharing = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $isSybil = false;
        foreach ($deviceSharing as $device) {
            if ($device['account_count'] >= 3) {
                $isSybil = true;
                break;
            }
        }
        
        return [
            'is_sybil' => $isSybil,
            'shared_devices' => $deviceSharing,
        ];
    }
    
    // ==================== Helper Methods ====================
    
    private function calculateAccountAge(string $createdAt): int
    {
        $created = strtotime($createdAt);
        $now = time();
        return (int)(($now - $created) / 86400);
    }
}
