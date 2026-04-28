<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use App\Models\Banner;
use App\Services\WalletService;
use Core\Database;

class BannerAdapter implements AdSystemContract
{
    public function __construct(
        private Banner $bannerModel,
        private WalletService $walletService,
        private Database $db
    ) {}

    public function getType(): string { return 'banner'; }

    public function create(int $userId, array $data): array
    {
        $errors = $this->validate($data);
        if (!$errors['valid']) {
            return ['success' => false, 'message' => implode(', ', $errors['errors'])];
        }

        $budget = (float) ($data['budget'] ?? 0);
        $feePercent = (float) setting('banner_site_fee_percent', 12);
        $totalWithFee = $budget + ($budget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $txId = $this->walletService->withdraw(
                $userId,
                $totalWithFee,
                'irt',
                ['type' => 'banner_budget', 'idempotency_key' => "banner_" . time()]
            );

            if (!$txId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست'];
            }

            $banner = $this->bannerModel->create([
                'created_by' => $userId,
                'title' => $data['title'],
                'budget' => $budget,
                'status' => 'pending',
                'is_active' => 0,
            ]);

            $this->db->commit();
            return ['success' => true, 'id' => $banner->id, 'message' => 'بنر ایجاد شد'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا: ' . $e->getMessage()];
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        if (empty($data['title'])) $errors[] = 'عنوان الزامی است';
        if (empty($data['budget']) || (float)$data['budget'] <= 0) $errors[] = 'بودجه باید مثبت باشد';
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isExpired(int $adId): bool
    {
        $banner = $this->bannerModel->find($adId);
        return !$banner || !$banner->is_active;
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) setting('banner_site_fee_percent', 12) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $result = $this->walletService->withdraw($userId, $amount, $currency, ['type' => 'banner_payment']);
        return $result ? 
            ['success' => true, 'transaction_id' => $result] : 
            ['success' => false, 'transaction_id' => null];
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        logger('banner_track', "Event: {$eventType}, Banner: {$adId}");
        return ['success' => true, 'message' => 'رویداد ثبت شد'];
    }

    public function getStatus(int $adId): ?array
    {
        $banner = $this->bannerModel->find($adId);
        return $banner ? ['id' => $banner->id, 'type' => 'banner', 'status' => $banner->status] : null;
    }
}
