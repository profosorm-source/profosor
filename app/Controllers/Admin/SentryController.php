<?php

namespace App\Controllers\Admin;

use App\Services\Sentry\Analytics\DashboardService;
use Core\Database;
use Core\Controller;

/**
 * 🛡️ SentryController - کنترلر پنل ادمین Sentry
 */
class SentryController extends Controller
{
    private DashboardService $dashboardService;

    public function __construct()
    {
        parent::__construct();
        $this->dashboardService = new DashboardService(Database::getInstance());
    }

    /**
     * 📊 Dashboard - صفحه اصلی Sentry
     */
    public function dashboard(): void
    {
        // بررسی دسترسی ادمین
        if (!$this->session->get('is_admin')) {
            $this->redirect('/admin/login');
            return;
        }

        try {
            $overview = $this->dashboardService->getOverview();
            $timeSeries = $this->dashboardService->getTimeSeriesData('24h', '1h');
            $topIssues = $this->dashboardService->getTrendingIssues(10);
            $topSources = $this->dashboardService->getTopErrorSources(10);
            $slowEndpoints = $this->dashboardService->getSlowEndpoints(10);

            $this->render('admin/sentry/dashboard.php', [
                'pageTitle' => 'مانیتورینگ سیستم - Sentry',
                'overview' => $overview,
                'timeSeries' => $timeSeries,
                'topIssues' => $topIssues,
                'topSources' => $topSources,
                'slowEndpoints' => $slowEndpoints,
            ]);
        } catch (\Throwable $e) {
            $this->render('admin/sentry/dashboard.php', [
                'pageTitle' => 'مانیتورینگ سیستم - Sentry',
                'error' => 'خطا در بارگذاری داشبورد: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 📋 Issues List - لیست خطاها
     */
    public function issues(): void
    {
        if (!$this->session->get('is_admin')) {
            $this->redirect('/admin/login');
            return;
        }

        $page = (int)($_GET['page'] ?? 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        try {
            $issues = Database::getInstance()->query(
                "SELECT 
                    si.*,
                    COUNT(se.id) as event_count,
                    MAX(se.created_at) as last_event
                 FROM sentry_issues si
                 LEFT JOIN sentry_events se ON si.id = se.issue_id
                 GROUP BY si.id
                 ORDER BY si.last_seen DESC
                 LIMIT ? OFFSET ?",
                [$perPage, $offset]
            )->fetchAll(\PDO::FETCH_OBJ);

            $total = Database::getInstance()->query(
                "SELECT COUNT(*) as count FROM sentry_issues"
            )->fetch(\PDO::FETCH_OBJ)->count;

            $this->render('admin/sentry/issues.php', [
                'pageTitle' => 'لیست خطاها - Sentry',
                'issues' => $issues,
                'pagination' => [
                    'current' => $page,
                    'total' => ceil($total / $perPage),
                    'per_page' => $perPage,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->render('admin/sentry/issues.php', [
                'pageTitle' => 'لیست خطاها - Sentry',
                'error' => 'خطا در بارگذاری لیست خطاها: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 📄 Issue Detail - جزئیات خطا
     */
    public function issueDetail(int $issueId): void
    {
        if (!$this->session->get('is_admin')) {
            $this->redirect('/admin/login');
            return;
        }

        try {
            $issue = Database::getInstance()->query(
                "SELECT * FROM sentry_issues WHERE id = ?",
                [$issueId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$issue) {
                $this->render('errors/404.php');
                return;
            }

            $events = Database::getInstance()->query(
                "SELECT * FROM sentry_events 
                 WHERE issue_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT 50",
                [$issueId]
            )->fetchAll(\PDO::FETCH_OBJ);

            $this->render('admin/sentry/issue-detail.php', [
                'pageTitle' => 'جزئیات خطا - Sentry',
                'issue' => $issue,
                'events' => $events,
            ]);
        } catch (\Throwable $e) {
            $this->render('admin/sentry/issue-detail.php', [
                'pageTitle' => 'جزئیات خطا - Sentry',
                'error' => 'خطا در بارگذاری جزئیات: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 🚀 Performance - مانیتورینگ عملکرد
     */
    public function performance(): void
    {
        if (!$this->session->get('is_admin')) {
            $this->redirect('/admin/login');
            return;
        }

        try {
            $performanceStats = $this->dashboardService->getPerformanceStatistics();
            $slowEndpoints = $this->dashboardService->getSlowEndpoints(20);
            $timeSeries = $this->dashboardService->getTimeSeriesData('24h', '1h', 'performance');

            $this->render('admin/sentry/performance.php', [
                'pageTitle' => 'مانیتورینگ عملکرد - Sentry',
                'performanceStats' => $performanceStats,
                'slowEndpoints' => $slowEndpoints,
                'timeSeries' => $timeSeries,
            ]);
        } catch (\Throwable $e) {
            $this->render('admin/sentry/performance.php', [
                'pageTitle' => 'مانیتورینگ عملکرد - Sentry',
                'error' => 'خطا در بارگذاری عملکرد: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * ⚙️ Settings - تنظیمات Sentry
     */
    public function settings(): void
    {
        if (!$this->session->get('is_admin')) {
            $this->redirect('/admin/login');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // ذخیره تنظیمات
            $settings = [
                'SENTRY_ENABLED' => $_POST['enabled'] ?? '1',
                'SENTRY_SAMPLE_RATE' => $_POST['sample_rate'] ?? '1.0',
            ];

            // ذخیره در .env یا database
            // TODO: پیاده‌سازی ذخیره تنظیمات

            $this->redirect('/admin/sentry/settings?success=1');
            return;
        }

        $this->render('admin/sentry/settings.php', [
            'pageTitle' => 'تنظیمات Sentry',
        ]);
    }
}