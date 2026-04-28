<?php

namespace App\Contracts;

/**
 * PaymentProcessorInterface - قرارداد مشترک برای همه پردازشگرهای پرداخت
 *
 * این interface تضمین می‌کند که همه gatewayها متدهای یکسانی داشته باشند
 */
interface PaymentProcessorInterface
{
    /**
     * ایجاد پرداخت جدید
     *
     * @param float $amount مبلغ
     * @param string $description توضیحات
     * @param string $callbackUrl آدرس بازگشت
     * @return array نتیجه شامل success, authority, message
     */
    public function createPayment(float $amount, string $description, string $callbackUrl): array;

    /**
     * بررسی وضعیت پرداخت
     *
     * @param string $authority شناسه پرداخت
     * @return array نتیجه شامل success, status, amount, refId
     */
    public function verifyPayment(string $authority): array;

    /**
     * برگرداندن پرداخت (در صورت امکان)
     *
     * @param string $authority شناسه پرداخت
     * @return array نتیجه برگرداندن
     */
    public function refundPayment(string $authority): array;

    /**
     * نام gateway
     */
    public function getName(): string;

    /**
     * آیا gateway فعال است
     */
    public function isActive(): bool;
}