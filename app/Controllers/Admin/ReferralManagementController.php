<?php

namespace App\Controllers\Admin;

use App\Services\ReferralTierService;
use App\Services\ReferralMilestoneService;
use App\Services\ReferralLeaderboardService;
use App\Services\ReferralQualityScoreService;
use App\Services\ReferralAnalyticsService;
use App\Models\ReferralCommission;
use Core\Database;

class ReferralManagementController
{
    private Database $db;
    private ReferralCommission $commissionModel;
    private ReferralTierService $tierService;
    private ReferralMilestoneService $milestoneService;
    private ReferralLeaderboardService $leaderboardService;
    private ReferralQualityScoreService $qualityScoreService;
    private ReferralAnalyticsService $analyticsService;

    public function __construct(
        Database $db,
        ReferralCommission $commissionModel,
        ReferralTierService $tierService,
        ReferralMilestoneService $milestoneService,
        ReferralLeaderboardService $leaderboardService,
        ReferralQualityScoreService $qualityScoreService,
        ReferralAnalyticsService $analyticsService
    ) {
        $this->db = $db;
        $this->commissionModel = $commissionModel;
        $this->tierService = $tierService;
        $this->milestoneService = $milestoneService;
        $this->leaderboardService = $leaderboardService;
        $this->qualityScoreService = $qualityScoreService;
        $this->analyticsService = $analyticsService;
    }

    /**
     * داشبورد کلی سیستم رفرال
     */
    public function dashboard()
    {
        // آمار کلی
        $globalStats = $this->commissionModel->globalStats();
        
        // آمار Tiers
        $tierStats = $this->tierService->getGlobalTierStats();
        
        // آمار Milestones
        $milestoneStats = $this->milestoneService->getGlobalMilestoneStats();
        
        // لیدربورد ماه جاری
        $currentLeaderboard = $this->leaderboardService->getCurrentLeaderboard(10);
        
        // برترین معرفین
        $topReferrers = $this->commissionModel->topReferrers('irt', 10);

        return view('admin.referral.dashboard', [
            'global_stats' => $globalStats,
            'tier_stats' => $tierStats,
            'milestone_stats' => $milestoneStats,
            'current_leaderboard' => $currentLeaderboard,
            'top_referrers' => $topReferrers
        ]);
    }

    /**
     * مدیریت Tiers
     */
    public function tiers()
    {
        $tiers = $this->tierService->getAllTiers();
        $tierStats = $this->tierService->getGlobalTierStats();

        return view('admin.referral.tiers', [
            'tiers' => $tiers,
            'stats' => $tierStats
        ]);
    }

    /**
     * مدیریت Milestones
     */
    public function milestones()
    {
        $milestones = $this->db->query("
            SELECT * FROM referral_milestones 
            WHERE is_active = TRUE 
            ORDER BY milestone_type ASC, threshold_value ASC
        ")->fetchAll(\PDO::FETCH_OBJ);

        $stats = $this->milestoneService->getGlobalMilestoneStats();

        return view('admin.referral.milestones', [
            'milestones' => $milestones,
            'stats' => $stats
        ]);
    }

    /**
     * مدیریت Leaderboard
     */
    public function leaderboard()
    {
        $periodKey = $_GET['period'] ?? date('Y-m');
        
        $leaderboard = $this->leaderboardService->getLeaderboard($periodKey, 100);

        return view('admin.referral.leaderboard', [
            'leaderboard' => $leaderboard,
            'period_key' => $periodKey
        ]);
    }

    /**
     * بروزرسانی Leaderboard (Manual)
     */
    public function updateLeaderboard()
    {
        $count = $this->leaderboardService->updateCurrentLeaderboard();

        return redirect('/admin/referral/leaderboard')
            ->with('success', "لیدربورد بروز شد. {$count} کاربر در لیست قرار گرفتند");
    }

    /**
     * پرداخت جوایز Leaderboard
     */
    public function distributeLeaderboardRewards()
    {
        $periodKey = $_POST['period'] ?? date('Y-m', strtotime('-1 month'));

        $results = $this->leaderboardService->distributeMonthlyRewards($periodKey);

        return redirect('/admin/referral/leaderboard')
            ->with('success', sprintf(
                'جوایز پرداخت شد: %d موفق، %d ناموفق، جمع: %s تومان',
                $results['success'],
                $results['failed'],
                number_format($results['total_paid'])
            ));
    }

    /**
     * نمایش جزئیات یک معرف
     */
    public function showReferrer($id)
    {
        $userId = (int) $id;

        // اطلاعات کاربر
        $user = $this->db->query(
            "SELECT * FROM users WHERE id = ? LIMIT 1",
            [$userId]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$user) {
            return redirect('/admin/referral')->with('error', 'کاربر یافت نشد');
        }

        // آمار
        $stats = $this->commissionModel->getReferrerStats($userId);
        
        // Tier
        $currentTier = $this->tierService->getCurrentTier($userId);
        $tierHistory = $this->tierService->getUserTierHistory($userId);
        
        // Quality Score
        $qualityScore = $this->qualityScoreService->getScore($userId);
        $qualityInterpretation = $this->qualityScoreService->getScoreInterpretation($qualityScore);
        
        // Milestones
        $achievedMilestones = $this->milestoneService->getUserAchievedMilestones($userId);
        
        // Analytics
        $analytics = $this->analyticsService->getReferrerDashboard($userId);

        return view('admin.referral.referrer-details', [
            'user' => $user,
            'stats' => $stats,
            'current_tier' => $currentTier,
            'tier_history' => $tierHistory,
            'quality_score' => $qualityScore,
            'quality_interpretation' => $qualityInterpretation,
            'achieved_milestones' => $achievedMilestones,
            'analytics' => $analytics
        ]);
    }

    /**
     * محاسبه مجدد Quality Score (Manual)
     */
    public function recalculateQualityScore($id)
    {
        $userId = (int) $id;
        
        $newScore = $this->qualityScoreService->calculate($userId);

        return redirect("/admin/referral/referrer/{$userId}")
            ->with('success', "Quality Score محاسبه شد: " . round($newScore, 2));
    }

    /**
     * جریمه/پاداش Quality Score
     */
    public function adjustQualityScore($id)
    {
        $userId = (int) $id;
        $action = $_POST['action'] ?? 'penalize'; // penalize or reward
        $amount = (float) ($_POST['amount'] ?? 0);
        $reason = $_POST['reason'] ?? '';

        if ($amount <= 0 || empty($reason)) {
            return redirect("/admin/referral/referrer/{$userId}")
                ->with('error', 'مقدار و دلیل الزامی است');
        }

        if ($action === 'penalize') {
            $this->qualityScoreService->penalize($userId, $amount, $reason);
            $message = "Quality Score کاهش یافت (-{$amount})";
        } else {
            $this->qualityScoreService->reward($userId, $amount, $reason);
            $message = "Quality Score افزایش یافت (+{$amount})";
        }

        return redirect("/admin/referral/referrer/{$userId}")
            ->with('success', $message);
    }

    /**
     * بررسی و ارتقای سطح کاربر (Manual)
     */
    public function checkTierUpgrade($id)
    {
        $userId = (int) $id;
        
        $newTier = $this->tierService->checkAndUpgrade($userId);

        return redirect("/admin/referral/referrer/{$userId}")
            ->with('success', "سطح بررسی شد: " . ($newTier->name_fa ?? 'بدون تغییر'));
    }

    /**
     * بررسی Milestones کاربر (Manual)
     */
    public function checkMilestones($id)
    {
        $userId = (int) $id;
        
        $awarded = $this->milestoneService->checkAndAwardMilestones($userId);

        $message = count($awarded) > 0
            ? sprintf('%d milestone جدید دریافت شد', count($awarded))
            : 'Milestone جدیدی یافت نشد';

        return redirect("/admin/referral/referrer/{$userId}")
            ->with('success', $message);
    }

    /**
     * گزارش Quality Score همه کاربران
     */
    public function qualityScoreReport()
    {
        $page = (int) ($_GET['page'] ?? 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $stmt = $this->db->query("
            SELECT 
                u.id,
                u.full_name,
                u.email,
                u.referral_quality_score,
                COUNT(DISTINCT ref.id) as total_referrals,
                COUNT(DISTINCT CASE WHEN ref.status = 'active' THEN ref.id END) as active_referrals
            FROM users u
            LEFT JOIN users ref ON ref.referred_by = u.id AND ref.deleted_at IS NULL
            WHERE u.deleted_at IS NULL
            HAVING total_referrals > 0
            ORDER BY u.referral_quality_score DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        $users = $stmt->fetchAll(\PDO::FETCH_OBJ);

        return view('admin.referral.quality-report', [
            'users' => $users,
            'page' => $page
        ]);
    }

    /**
     * بروزرسانی دسته‌ای Quality Score (Batch)
     */
    public function batchRecalculateQuality()
    {
        $limit = (int) ($_POST['limit'] ?? 100);
        
        $count = $this->qualityScoreService->batchRecalculate($limit);

        return redirect('/admin/referral/quality-report')
            ->with('success', "{$count} کاربر پردازش شد");
    }
}
