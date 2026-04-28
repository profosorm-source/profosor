<?php

namespace App\Services;

use App\Services\AnalyticsService;
use App\Services\CustomTaskAnalyticsService;
use App\Services\ReferralAnalyticsService;
use App\Services\NotificationAnalyticsService;
use App\Services\AdvancedAnalyticsService;

/**
 * AnalyticsManager - یکپارچه‌سازی همه سرویس‌های آنالیتیکس
 *
 * این کلاس facade است برای دسترسی یکدست به همه آنالیتیکس‌ها
 */
class AnalyticsManager
{
    private AnalyticsService $analytics;
    private CustomTaskAnalyticsService $customTaskAnalytics;
    private ReferralAnalyticsService $referralAnalytics;
    private NotificationAnalyticsService $notificationAnalytics;
    private AdvancedAnalyticsService $advancedAnalytics;

    public function __construct(
        AnalyticsService $analytics,
        CustomTaskAnalyticsService $customTaskAnalytics,
        ReferralAnalyticsService $referralAnalytics,
        NotificationAnalyticsService $notificationAnalytics,
        AdvancedAnalyticsService $advancedAnalytics
    ) {
        $this->analytics = $analytics;
        $this->customTaskAnalytics = $customTaskAnalytics;
        $this->referralAnalytics = $referralAnalytics;
        $this->notificationAnalytics = $notificationAnalytics;
        $this->advancedAnalytics = $advancedAnalytics;
    }

    /**
     * دریافت آمار کلی
     */
    public function getDashboardStats(): array
    {
        return $this->analytics->getDashboardStats();
    }

    /**
     * آمار تسک‌های سفارشی
     */
    public function getCustomTaskStats(): array
    {
        return $this->customTaskAnalytics->getStats();
    }

    /**
     * آمار رفرال
     */
    public function getReferralStats(): array
    {
        return $this->referralAnalytics->getStats();
    }

    /**
     * آمار نوتیفیکیشن
     */
    public function getNotificationStats(): array
    {
        return $this->notificationAnalytics->getStats();
    }

    /**
     * آمار پیشرفته
     */
    public function getAdvancedStats(): array
    {
        return $this->advancedAnalytics->getStats();
    }

    /**
     * aggregate همه داده‌ها
     */
    public function aggregateAll(): void
    {
        $this->analytics->aggregate();
        $this->customTaskAnalytics->aggregate();
        $this->referralAnalytics->aggregate();
        $this->notificationAnalytics->aggregate();
        $this->advancedAnalytics->aggregate();
    }
}