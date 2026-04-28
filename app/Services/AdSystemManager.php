<?php

namespace App\Services;

use App\Contracts\AdSystemContract;
use Core\Container;
use RuntimeException;

/**
 * AdSystemManager — مدیریت یکپارچه تمام سیستم‌های تبلیغاتی
 * 
 * این کلاس با استفاده از Strategy Pattern تمام سیستم‌های تبلیغاتی را یکسان‌سازی می‌کند
 * و به Controller‌ها کمک می‌کند بدون نگرانی درباره نوع سیستم، عمل انجام دهند.
 */
class AdSystemManager
{
    private Container $container;
    private array $adapters = [];

    /**
     * ثبت Adapter برای نوع سیستم
     */
    private const ADAPTER_MAP = [
        'custom_task' => 'App\Services\Adapters\CustomTaskAdapter',
        'seo' => 'App\Services\Adapters\SeoAdAdapter',
        'banner' => 'App\Services\Adapters\BannerAdapter',
        'vitrine' => 'App\Services\Adapters\VitrineAdapter',
        'story_promotion' => 'App\Services\Adapters\StoryPromotionAdapter',
        'adtube' => 'App\Services\Adapters\AdTubeAdapter',
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * دریافت Adapter برای نوع سیستم
     * 
     * @param string $type نوع سیستم (custom_task, seo, banner, ...)
     * @return AdSystemContract
     * @throws RuntimeException
     */
    public function getAdapter(string $type): AdSystemContract
    {
        // بررسی cache
        if (isset($this->adapters[$type])) {
            return $this->adapters[$type];
        }

        // بررسی اینکه نوع پشتیبانی شده است
        if (!isset(self::ADAPTER_MAP[$type])) {
            throw new RuntimeException("نوع سیستم تبلیغاتی '{$type}' پشتیبانی نشده است. انواع پشتیبانی‌شده: " . implode(', ', array_keys(self::ADAPTER_MAP)));
        }

        // load adapter از container
        $adapterClass = self::ADAPTER_MAP[$type];
        $adapter = $this->container->make($adapterClass);

        // بررسی اینکه Adapter درست است
        if (!($adapter instanceof AdSystemContract)) {
            throw new RuntimeException("Adapter '{$adapterClass}' باید AdSystemContract را پیاده‌سازی کند");
        }

        // cache کردن
        $this->adapters[$type] = $adapter;

        return $adapter;
    }

    /**
     * ایجاد آگهی/تسک جدید
     */
    public function create(string $type, int $userId, array $data): array
    {
        return $this->getAdapter($type)->create($userId, $data);
    }

    /**
     * بررسی اعتبار داده‌های آگهی
     */
    public function validate(string $type, array $data, bool $isUpdate = false): array
    {
        return $this->getAdapter($type)->validate($data, $isUpdate);
    }

    /**
     * بررسی انقضای آگهی
     */
    public function isExpired(string $type, int $adId): bool
    {
        return $this->getAdapter($type)->isExpired($adId);
    }

    /**
     * محاسبه هزینه/کمیسیون سایت
     */
    public function calculateCost(string $type, float $amount, array $context = []): float
    {
        return $this->getAdapter($type)->calculateCost($amount, $context);
    }

    /**
     * پردازش پرداخت/کسب بودجه
     */
    public function processPayment(string $type, int $adId, int $userId, float $amount, string $currency): array
    {
        return $this->getAdapter($type)->processPayment($adId, $userId, $amount, $currency);
    }

    /**
     * ردیابی تعاملات
     */
    public function track(string $type, int $adId, string $eventType, ?int $userId = null): array
    {
        return $this->getAdapter($type)->track($adId, $eventType, $userId);
    }

    /**
     * دریافت وضعیت آگهی
     */
    public function getStatus(string $type, int $adId): ?array
    {
        return $this->getAdapter($type)->getStatus($adId);
    }

    /**
     * دریافت دسته‌بندی انواع سیستم‌ها
     */
    public function getSupportedTypes(): array
    {
        return array_keys(self::ADAPTER_MAP);
    }

    /**
     * بررسی اینکه نوع پشتیبانی شده است
     */
    public function isSupported(string $type): bool
    {
        return isset(self::ADAPTER_MAP[$type]);
    }
}
