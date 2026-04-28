<?php

namespace App\Services;

use Core\Database;
use Core\Logger;
use App\Models\ReferralCommission;
use App\Models\User;

/**
 * ReferralService — Consolidated Referral System
 * 
 * یہ unified service تمام referral کام کو handle کرتا ہے:
 * - Analytics (trends, conversion rates)
 * - Commission Processing (payments, batching)
 * - Leaderboards (rankings, rewards)
 * - Multi-tier Commission (indirect earnings)
 * - Milestones (achievements, rewards)
 * - Quality Scoring (reputation)
 * - Tier Management (VIP levels)
 */
class ReferralService
{
    public function __construct(
        private Database $db,
        private Logger $logger,
        private WalletService $walletService,
        private NotificationService $notificationService,
        private AuditTrail $auditTrail,
        private ReferralCommission $commissionModel,
        private User $userModel
    ) {}

    // ═══════════════════════════════════════════════════════════════════════
    // Analytics Methods
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * کسی صارف کی referral trend دیکھیں (آخری X دن)
     */
    public function getReferralTrend(int $userId, int $days = 30): array
    {
        $trend = $this->db->query(
            "SELECT 
                DATE(referred_at) as date,
                COUNT(*) as count,
                SUM(commission_amount) as total_commission
             FROM referral_commissions
             WHERE referrer_id = ? AND referred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(referred_at)
             ORDER BY date ASC",
            [$userId, $days]
        )->fetchAll() ?? [];

        return ['data' => $trend, 'period_days' => $days];
    }

    /**
     * Commission trend دیکھیں
     */
    public function getCommissionTrend(int $userId, int $days = 30, ?string $currency = null): array
    {
        $query = "SELECT 
                    YEARWEEK(commission_date) as week,
                    SUM(commission_amount) as total
                  FROM referral_commissions
                  WHERE referrer_id = ? AND commission_date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        $params = [$userId, $days];

        if ($currency) {
            $query .= " AND currency = ?";
            $params[] = $currency;
        }

        $query .= " GROUP BY week ORDER BY week ASC";

        $trend = $this->db->query($query, $params)->fetchAll() ?? [];
        return ['data' => $trend, 'currency' => $currency];
    }

    /**
     * صارف کی conversion rate
     */
    public function getConversionRate(int $userId, int $days = 30): array
    {
        $result = $this->db->query(
            "SELECT 
                COUNT(DISTINCT referred_user_id) as converted,
                COUNT(DISTINCT click_user_id) as clicked,
                ROUND(100.0 * COUNT(DISTINCT referred_user_id) / 
                      NULLIF(COUNT(DISTINCT click_user_id), 0), 2) as conversion_rate
             FROM referral_clicks rc
             LEFT JOIN referral_commissions r ON rc.referred_user_id = r.referred_user_id
             WHERE rc.referrer_id = ? AND rc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$userId, $days]
        )->fetch();

        return [
            'converted' => $result->converted ?? 0,
            'clicked' => $result->clicked ?? 0,
            'rate' => $result->conversion_rate ?? 0,
        ];
    }

    /**
     * Source breakdown (کہاں سے referrals آ رہے ہیں)
     */
    public function getSourceBreakdown(int $userId): array
    {
        $sources = $this->db->query(
            "SELECT 
                referral_source,
                COUNT(*) as count,
                SUM(commission_amount) as total_commission
             FROM referral_commissions
             WHERE referrer_id = ?
             GROUP BY referral_source
             ORDER BY count DESC",
            [$userId]
        )->fetchAll() ?? [];

        return ['sources' => $sources];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Commission Processing
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Commission کو process کریں
     */
    public function processCommission(
        int $referrerId,
        float $amount,
        string $currency,
        array $context = []
    ): array {
        $percentage = (float) setting('referral_commission_percent', 5);
        $commission = $amount * ($percentage / 100);

        try {
            $this->db->beginTransaction();

            // Commission record بنائیں
            $this->commissionModel->create([
                'referrer_id' => $referrerId,
                'amount' => $amount,
                'commission_amount' => $commission,
                'currency' => $currency,
                'status' => 'pending',
                'context' => json_encode($context),
            ]);

            // Wallet میں شامل کریں
            $this->walletService->deposit(
                $referrerId,
                $commission,
                $currency,
                [
                    'type' => 'referral_commission',
                    'amount' => $amount,
                    'idempotency_key' => "referral_" . $referrerId . "_" . time(),
                ]
            );

            $this->db->commit();
            return ['success' => true, 'commission' => $commission];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('commission_error', $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Batch میں commissions ادا کریں
     */
    public function batchPayCommissions(int $limit = 100): array
    {
        $commissions = $this->db->query(
            "SELECT * FROM referral_commissions WHERE status = 'pending' LIMIT ?",
            [$limit]
        )->fetchAll() ?? [];

        $paid = 0;
        $failed = 0;

        foreach ($commissions as $commission) {
            $result = $this->processCommission(
                $commission->referrer_id,
                $commission->amount,
                $commission->currency
            );

            if ($result['success']) {
                $this->commissionModel->update($commission->id, ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);
                $paid++;
            } else {
                $failed++;
            }
        }

        return ['paid' => $paid, 'failed' => $failed];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Leaderboard
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Top referrers کی leaderboard
     */
    public function getLeaderboard(int $limit = 50, string $period = 'month'): array
    {
        $dateFilter = match($period) {
            'week' => "DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year' => "DATE_SUB(NOW(), INTERVAL 365 DAY)",
            default => "DATE_SUB(NOW(), INTERVAL 30 DAY)",
        };

        $leaderboard = $this->db->query(
            "SELECT 
                u.id,
                u.username,
                COUNT(DISTINCT rc.referred_user_id) as referrals,
                SUM(rc.commission_amount) as total_commission,
                SUM(rc.commission_amount) as earned
             FROM users u
             LEFT JOIN referral_commissions rc ON u.id = rc.referrer_id
             WHERE rc.commission_date >= {$dateFilter}
             GROUP BY u.id
             ORDER BY total_commission DESC
             LIMIT ?",
            [$limit]
        )->fetchAll() ?? [];

        return array_map(function($user, $rank) {
            return (array) $user + ['rank' => $rank + 1];
        }, $leaderboard, array_keys($leaderboard));
    }

    /**
     * ماہانہ انعام تقسیم کریں
     */
    public function distributeMonthlyRewards(): array
    {
        $rewards = [
            'bonus_percent' => 0.05, // Top 5% کو اضافی 5% بونس
        ];

        $top = $this->db->query(
            "SELECT u.id, SUM(rc.commission_amount) as total
             FROM users u
             LEFT JOIN referral_commissions rc ON u.id = rc.referrer_id
             WHERE MONTH(rc.commission_date) = MONTH(NOW())
             GROUP BY u.id
             ORDER BY total DESC
             LIMIT 1"
        )->fetch();

        if ($top) {
            $bonus = $top->total * $rewards['bonus_percent'];
            $this->walletService->deposit($top->id, $bonus, 'irt', ['type' => 'referral_bonus']);
        }

        return $rewards;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Multi-tier Commission
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Multi-tier commission process کریں
     */
    public function processMultiTierCommissions(int $userId, float $amount, string $currency): array
    {
        $processed = [];

        // First tier (براہ راست referrer)
        $referrer = $this->db->query(
            "SELECT referred_by FROM users WHERE id = ? LIMIT 1",
            [$userId]
        )->fetch();

        if ($referrer && $referrer->referred_by) {
            $commission = $this->processCommission($referrer->referred_by, $amount, $currency);
            $processed[1] = $commission;

            // Second tier (referrer کا referrer)
            $referrer2 = $this->db->query(
                "SELECT referred_by FROM users WHERE id = ? LIMIT 1",
                [$referrer->referred_by]
            )->fetch();

            if ($referrer2 && $referrer2->referred_by) {
                $commission2Rate = 0.5; // 50% کا کمیشن
                $commission2 = $this->processCommission(
                    $referrer2->referred_by,
                    $amount * $commission2Rate,
                    $currency
                );
                $processed[2] = $commission2;
            }
        }

        return ['tiers_processed' => $processed];
    }

    /**
     * Indirect earnings حاصل کریں
     */
    public function getIndirectEarnings(int $userId, string $currency = 'irt'): float
    {
        $result = $this->db->query(
            "SELECT SUM(commission_amount) as total
             FROM referral_commissions rc
             WHERE rc.referrer_id IN (
                SELECT referred_user_id FROM referral_commissions 
                WHERE referrer_id = ?
             ) AND rc.currency = ?",
            [$userId, $currency]
        )->fetch();

        return (float) ($result->total ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Milestones
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Milestones کو check اور award کریں
     */
    public function checkAndAwardMilestones(int $userId): array
    {
        $milestones = [
            ['name' => 'first_referral', 'condition' => 1, 'reward' => 50000],
            ['name' => 'ten_referrals', 'condition' => 10, 'reward' => 500000],
            ['name' => 'fifty_referrals', 'condition' => 50, 'reward' => 2000000],
            ['name' => 'hundred_referrals', 'condition' => 100, 'reward' => 5000000],
        ];

        $refCount = $this->db->query(
            "SELECT COUNT(*) as count FROM referral_commissions WHERE referrer_id = ?",
            [$userId]
        )->fetch()->count ?? 0;

        $awarded = [];
        foreach ($milestones as $milestone) {
            if ($refCount >= $milestone['condition']) {
                // چیک کریں کہ پہلے سے awarded ہے یا نہیں
                $existing = $this->db->query(
                    "SELECT id FROM user_milestones WHERE user_id = ? AND milestone = ? LIMIT 1",
                    [$userId, $milestone['name']]
                )->fetch();

                if (!$existing) {
                    // Award بنائیں
                    $this->db->query(
                        "INSERT INTO user_milestones (user_id, milestone, awarded_at) VALUES (?, ?, NOW())",
                        [$userId, $milestone['name']]
                    );

                    // Bonus ادا کریں
                    $this->walletService->deposit($userId, $milestone['reward'], 'irt', ['type' => 'milestone_bonus']);
                    $awarded[] = $milestone['name'];
                }
            }
        }

        return ['awarded' => $awarded];
    }

    /**
     * صارف کے achieved milestones
     */
    public function getUserAchievedMilestones(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM user_milestones WHERE user_id = ? ORDER BY awarded_at DESC",
            [$userId]
        )->fetchAll() ?? [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Quality Score
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Quality score حاصل کریں
     */
    public function getScore(int $userId): float
    {
        $result = $this->db->query(
            "SELECT quality_score FROM user_quality_scores WHERE user_id = ? LIMIT 1",
            [$userId]
        )->fetch();

        return $result ? (float) $result->quality_score : 50.0; // Default 50
    }

    /**
     * Quality score calculate کریں
     */
    public function calculateScore(int $userId): float
    {
        $factors = [
            'referral_count' => $this->db->query(
                "SELECT COUNT(*) as c FROM referral_commissions WHERE referrer_id = ?",
                [$userId]
            )->fetch()->c ?? 0,
            'conversion_rate' => $this->getConversionRate($userId)['rate'] ?? 0,
            'account_age_days' => $this->db->query(
                "SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ? LIMIT 1",
                [$userId]
            )->fetch()->days ?? 0,
        ];

        $score = 50; // Base score
        $score += min($factors['referral_count'] * 2, 25); // Max 25 points
        $score += min($factors['conversion_rate'], 15); // Max 15 points
        $score += min($factors['account_age_days'] / 10, 10); // Max 10 points

        // Save to DB
        $this->db->query(
            "INSERT INTO user_quality_scores (user_id, quality_score, last_updated) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE quality_score = ?, last_updated = NOW()",
            [$userId, $score, $score]
        );

        return $score;
    }

    /**
     * Quality score کو penalize کریں (fraud)
     */
    public function penalizeScore(int $userId, int $points = 10, string $reason = ''): void
    {
        $this->db->query(
            "UPDATE user_quality_scores SET quality_score = GREATEST(0, quality_score - ?) 
             WHERE user_id = ?",
            [$points, $userId]
        );

        $this->auditTrail->log('score_penalized', "User $userId penalized: $reason", ['points' => $points]);
    }

    /**
     * Quality score کو reward کریں
     */
    public function rewardScore(int $userId, int $points = 5, string $reason = ''): void
    {
        $this->db->query(
            "UPDATE user_quality_scores SET quality_score = LEAST(100, quality_score + ?)
             WHERE user_id = ?",
            [$points, $userId]
        );

        $this->auditTrail->log('score_rewarded', "User $userId rewarded: $reason", ['points' => $points]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Tier Management
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * موجودہ tier حاصل کریں
     */
    public function getCurrentTier(int $userId): ?object
    {
        return $this->db->query(
            "SELECT * FROM referral_tiers WHERE user_id = ? AND is_active = 1 LIMIT 1",
            [$userId]
        )->fetch();
    }

    /**
     * Tier کو upgrade کریں اگر conditions پوری ہوں
     */
    public function checkAndUpgrade(int $userId): ?object
    {
        $refCount = $this->db->query(
            "SELECT COUNT(*) as c FROM referral_commissions WHERE referrer_id = ? AND status = 'paid'",
            [$userId]
        )->fetch()->c ?? 0;

        $tiers = [
            ['name' => 'bronze', 'min_referrals' => 5, 'bonus_percent' => 1],
            ['name' => 'silver', 'min_referrals' => 25, 'bonus_percent' => 2],
            ['name' => 'gold', 'min_referrals' => 100, 'bonus_percent' => 3],
            ['name' => 'platinum', 'min_referrals' => 500, 'bonus_percent' => 5],
        ];

        foreach (array_reverse($tiers) as $tier) {
            if ($refCount >= $tier['min_referrals']) {
                $current = $this->getCurrentTier($userId);

                if (!$current || $current->tier_name !== $tier['name']) {
                    // Upgrade کریں
                    $this->db->query(
                        "UPDATE referral_tiers SET is_active = 0 WHERE user_id = ?",
                        [$userId]
                    );

                    return $this->db->query(
                        "INSERT INTO referral_tiers (user_id, tier_name, bonus_percent, upgraded_at) 
                         VALUES (?, ?, ?, NOW()) RETURNING *",
                        [$userId, $tier['name'], $tier['bonus_percent']]
                    )->fetch();
                }
            }
        }

        return $this->getCurrentTier($userId);
    }

    /**
     * Commission boost حاصل کریں (tier کی بنیاد پر)
     */
    public function getCommissionBoost(int $userId): float
    {
        $tier = $this->getCurrentTier($userId);
        return $tier ? (float) $tier->bonus_percent : 0;
    }

    /**
     * Final commission percentage حاصل کریں
     */
    public function calculateFinalCommissionPercent(int $userId): float
    {
        $base = (float) setting('referral_commission_percent', 5);
        $boost = $this->getCommissionBoost($userId);
        return $base + $boost;
    }
}
