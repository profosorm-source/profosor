<?php

namespace App\Services;

use Core\Database;
use Core\Cache;

/**
 * ReferralLeaderboardService
 * 
 * مدیریت لیدربورد ماهانه رفرال
 * جوایز برای برترین‌ها
 */
class ReferralLeaderboardService
{
    private Database $db;
    private Cache $cache;
    private WalletService $walletService;
    private NotificationService $notificationService;

    public function __construct(
        Database $db,
        Cache $cache,
        WalletService $walletService,
        NotificationService $notificationService
    ) {
        $this->db = $db;
        $this->cache = $cache;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
    }

    /**
     * دریافت لیدربورد ماه جاری
     */
    public function getCurrentLeaderboard(int $limit = 50): array
    {
        $periodKey = date('Y-m');
        return $this->getLeaderboard($periodKey, $limit);
    }

    /**
     * دریافت لیدربورد یک ماه خاص
     */
    public function getLeaderboard(string $periodKey, int $limit = 50): array
    {
        $cacheKey = "leaderboard:referral:{$periodKey}:{$limit}";
        
        return $this->cache->remember($cacheKey, 300, function() use ($periodKey, $limit) {
            $stmt = $this->db->prepare("
                SELECT 
                    rl.*,
                    u.full_name,
                    u.email,
                    u.referral_code,
                    rt.slug as tier_slug,
                    rt.name_fa as tier_name
                FROM referral_leaderboard rl
                JOIN users u ON u.id = rl.user_id
                LEFT JOIN user_referral_tiers urt ON urt.user_id = u.id AND urt.is_current = TRUE
                LEFT JOIN referral_tiers rt ON rt.id = urt.tier_id
                WHERE rl.period_key = ?
                ORDER BY rl.rank_position ASC
                LIMIT ?
            ");
            $stmt->execute([$periodKey, $limit]);

            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        });
    }

    /**
     * بروزرسانی لیدربورد ماه جاری
     * 
     * این متد معمولاً توسط cron job در پایان هر روز اجرا می‌شود
     */
    public function updateCurrentLeaderboard(): int
    {
        $periodKey = date('Y-m');
        $startDate = date('Y-m-01 00:00:00');
        $endDate = date('Y-m-t 23:59:59');

        // محاسبه رتبه‌بندی بر اساس آمار ماه جاری
        $stmt = $this->db->prepare("
            SELECT 
                u.id as user_id,
                COUNT(DISTINCT ref.id) as total_referrals,
                COUNT(DISTINCT CASE WHEN ref.status = 'active' THEN ref.id END) as active_referrals,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned,
                COALESCE(SUM(CASE WHEN rc.currency='usdt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned_usdt
            FROM users u
            INNER JOIN users ref ON ref.referred_by = u.id 
                AND ref.created_at BETWEEN ? AND ?
                AND ref.deleted_at IS NULL
            LEFT JOIN referral_commissions rc ON rc.referrer_id = u.id 
                AND rc.created_at BETWEEN ? AND ?
            WHERE u.deleted_at IS NULL
            GROUP BY u.id
            HAVING total_earned > 0 OR total_referrals > 0
            ORDER BY total_earned DESC, active_referrals DESC, total_referrals DESC
            LIMIT 100
        ");

        $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $leaders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($leaders)) {
            return 0;
        }

        // حذف رکوردهای قدیمی این ماه
        $this->db->prepare("DELETE FROM referral_leaderboard WHERE period_key = ?")
                 ->execute([$periodKey]);

        // درج رکوردهای جدید
        $insertStmt = $this->db->prepare("
            INSERT INTO referral_leaderboard 
            (period_key, user_id, rank_position, total_referrals, total_earned, 
             total_earned_usdt, active_referrals, metadata, snapshot_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $rank = 1;
        foreach ($leaders as $leader) {
            $metadata = json_encode([
                'calculated_at' => date('Y-m-d H:i:s'),
                'period' => $periodKey
            ], JSON_UNESCAPED_UNICODE);

            $insertStmt->execute([
                $periodKey,
                $leader['user_id'],
                $rank,
                $leader['total_referrals'],
                $leader['total_earned'],
                $leader['total_earned_usdt'],
                $leader['active_referrals'],
                $metadata
            ]);

            $rank++;
        }

        // پاک کردن کش
        $this->cache->forget("leaderboard:referral:{$periodKey}*");

        $this->logger->info('Leaderboard updated', [
            'period' => $periodKey,
            'leaders_count' => count($leaders)
        ]);

        return count($leaders);
    }

    /**
     * رتبه کاربر در لیدربورد ماه جاری
     */
    public function getUserRank(int $userId, ?string $periodKey = null): ?array
    {
        $periodKey = $periodKey ?? date('Y-m');

        $stmt = $this->db->prepare("
            SELECT *
            FROM referral_leaderboard
            WHERE period_key = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$periodKey, $userId]);
        $rank = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$rank) {
            // اگر در لیدربورد نیست، رتبه تقریبی محاسبه کن
            return $this->calculateEstimatedRank($userId, $periodKey);
        }

        // تعداد کل شرکت‌کنندگان
        $totalStmt = $this->db->prepare("
            SELECT COUNT(*) FROM referral_leaderboard WHERE period_key = ?
        ");
        $totalStmt->execute([$periodKey]);
        $totalParticipants = (int) $totalStmt->fetchColumn();

        return [
            'rank' => $rank['rank_position'],
            'total_referrals' => $rank['total_referrals'],
            'active_referrals' => $rank['active_referrals'],
            'total_earned' => $rank['total_earned'],
            'total_earned_usdt' => $rank['total_earned_usdt'],
            'total_participants' => $totalParticipants,
            'percentile' => $totalParticipants > 0 
                ? round((1 - ($rank['rank_position'] / $totalParticipants)) * 100, 2)
                : 0
        ];
    }

    /**
     * محاسبه رتبه تقریبی برای کاربرانی که در لیدربورد نیستند
     */
    private function calculateEstimatedRank(int $userId, string $periodKey): ?array
    {
        $startDate = substr($periodKey, 0, 7) . '-01 00:00:00';
        $endDate = date('Y-m-t 23:59:59', strtotime($startDate));

        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) as total_earned,
                COUNT(DISTINCT ref.id) as total_referrals,
                COUNT(DISTINCT CASE WHEN ref.status='active' THEN ref.id END) as active_referrals
            FROM users u
            LEFT JOIN users ref ON ref.referred_by = u.id 
                AND ref.created_at BETWEEN ? AND ?
            LEFT JOIN referral_commissions rc ON rc.referrer_id = u.id 
                AND rc.created_at BETWEEN ? AND ?
            WHERE u.id = ?
        ");
        $stmt->execute([$startDate, $endDate, $startDate, $endDate, $userId]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$stats || $stats['total_earned'] == 0) {
            return [
                'rank' => null,
                'total_referrals' => 0,
                'active_referrals' => 0,
                'total_earned' => 0,
                'total_earned_usdt' => 0,
                'total_participants' => 0,
                'percentile' => 0,
                'message' => 'شما هنوز در لیدربورد این ماه قرار ندارید'
            ];
        }

        // تعداد کسانی که بالاتر از این کاربر هستند
        $higherStmt = $this->db->prepare("
            SELECT COUNT(*) + 1 as estimated_rank
            FROM referral_leaderboard
            WHERE period_key = ? AND total_earned > ?
        ");
        $higherStmt->execute([$periodKey, $stats['total_earned']]);
        $estimatedRank = (int) $higherStmt->fetchColumn();

        return [
            'rank' => $estimatedRank,
            'total_referrals' => $stats['total_referrals'],
            'active_referrals' => $stats['active_referrals'],
            'total_earned' => $stats['total_earned'],
            'total_earned_usdt' => 0,
            'is_estimated' => true,
            'message' => 'رتبه تقریبی - لیدربورد هنوز بروز نشده'
        ];
    }

    /**
     * جوایز ماهانه به برترین‌ها
     */
    public function distributeMonthlyRewards(string $periodKey): array
    {
        $rewards = $this->getMonthlyRewardConfig();
        $results = ['success' => 0, 'failed' => 0, 'total_paid' => 0];

        $leaderboard = $this->getLeaderboard($periodKey, 10);

        foreach ($leaderboard as $entry) {
            $rank = $entry->rank_position;

            if (!isset($rewards[$rank])) {
                continue;
            }

            $rewardAmount = $rewards[$rank]['amount'];
            $rewardCurrency = $rewards[$rank]['currency'] ?? 'irt';

            try {
                $result = $this->walletService->deposit(
                    $entry->user_id,
                    $rewardAmount,
                    $rewardCurrency,
                    [
                        'type' => 'leaderboard_reward',
                        'description' => "جایزه رتبه {$rank} لیدربورد {$periodKey}",
                        'period' => $periodKey,
                        'rank' => $rank
                    ]
                );

                if ($result['success']) {
                    $results['success']++;
                    $results['total_paid'] += $rewardAmount;

                    // ارسال نوتیفیکیشن
                    $this->notificationService->create(
                        $entry->user_id,
                        'leaderboard_reward',
                        'جایزه لیدربورد',
                        sprintf(
                            '🏆 تبریک! شما در لیدربورد %s رتبه %d را کسب کردید و %s %s جایزه دریافت کردید',
                            $periodKey,
                            $rank,
                            number_format($rewardAmount),
                            $rewardCurrency === 'usdt' ? 'USDT' : 'تومان'
                        ),
                        ['period' => $periodKey, 'rank' => $rank, 'amount' => $rewardAmount]
                    );

                    $this->logger->info('Leaderboard reward paid', [
                        'period' => $periodKey,
                        'user_id' => $entry->user_id,
                        'rank' => $rank,
                        'amount' => $rewardAmount
                    ]);
                } else {
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $this->logger->error('Failed to pay leaderboard reward', [
                    'period' => $periodKey,
                    'user_id' => $entry->user_id,
                    'rank' => $rank,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * تنظیمات جوایز ماهانه (قابل تغییر از settings)
     */
    private function getMonthlyRewardConfig(): array
    {
        return [
            1 => ['amount' => (float) setting('leaderboard_reward_rank_1', 500000), 'currency' => 'irt'],
            2 => ['amount' => (float) setting('leaderboard_reward_rank_2', 300000), 'currency' => 'irt'],
            3 => ['amount' => (float) setting('leaderboard_reward_rank_3', 200000), 'currency' => 'irt'],
            4 => ['amount' => (float) setting('leaderboard_reward_rank_4', 100000), 'currency' => 'irt'],
            5 => ['amount' => (float) setting('leaderboard_reward_rank_5', 100000), 'currency' => 'irt'],
            6 => ['amount' => (float) setting('leaderboard_reward_rank_6', 50000), 'currency' => 'irt'],
            7 => ['amount' => (float) setting('leaderboard_reward_rank_7', 50000), 'currency' => 'irt'],
            8 => ['amount' => (float) setting('leaderboard_reward_rank_8', 50000), 'currency' => 'irt'],
            9 => ['amount' => (float) setting('leaderboard_reward_rank_9', 25000), 'currency' => 'irt'],
            10 => ['amount' => (float) setting('leaderboard_reward_rank_10', 25000), 'currency' => 'irt'],
        ];
    }

    /**
     * مقایسه عملکرد ماه جاری با ماه قبل
     */
    public function compareWithPreviousMonth(int $userId): array
    {
        $currentMonth = date('Y-m');
        $previousMonth = date('Y-m', strtotime('-1 month'));

        $currentRank = $this->getUserRank($userId, $currentMonth);
        $previousRank = $this->getUserRank($userId, $previousMonth);

        $rankChange = null;
        if ($currentRank['rank'] && $previousRank['rank']) {
            $rankChange = $previousRank['rank'] - $currentRank['rank'];
        }

        return [
            'current_month' => $currentRank,
            'previous_month' => $previousRank,
            'rank_change' => $rankChange,
            'rank_trend' => $rankChange === null ? 'new' : ($rankChange > 0 ? 'up' : ($rankChange < 0 ? 'down' : 'same')),
            'earnings_change' => $currentRank['total_earned'] - ($previousRank['total_earned'] ?? 0),
            'earnings_change_percent' => $previousRank['total_earned'] > 0 
                ? round((($currentRank['total_earned'] - $previousRank['total_earned']) / $previousRank['total_earned']) * 100, 2)
                : 0
        ];
    }
}
