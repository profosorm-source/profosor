<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use App\Models\SeoAd;
use App\Services\WalletService;
use Core\Database;

class SeoAdAdapter implements AdSystemContract
{
    public function __construct(
        private SeoAd $adModel,
        private WalletService $walletService,
        private Database $db
    ) {}

    public function getType(): string { return 'seo'; }

    public function create(int $userId, array $data): array
    {
        $errors = $this->validate($data);
        if (!$errors['valid']) {
            return ['success' => false, 'message' => implode(', ', $errors['errors'])];
        }

        $budget = (float) ($data['budget'] ?? 0);
        $feePercent = (float) setting('seo_ad_site_fee_percent', 15);
        $totalWithFee = $budget + ($budget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $txId = $this->walletService->withdraw(
                $userId,
                $totalWithFee,
                'irt',
                ['type' => 'seo_ad_budget', 'idempotency_key' => "seo_" . time()]
            );

            if (!$txId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست'];
            }

            $ad = $this->adModel->create([
                'user_id' => $userId,
                'title' => $data['title'],
                'budget' => $budget,
                'status' => 'pending',
                'remaining_budget' => $budget,
            ]);

            $this->db->commit();
            return ['success' => true, 'id' => $ad->id, 'message' => 'تبلیغ SEO ایجاد شد'];
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
        $ad = $this->adModel->find($adId);
        return !$ad || $ad->status === 'expired' || $ad->remaining_budget <= 0;
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) setting('seo_ad_site_fee_percent', 15) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $result = $this->walletService->withdraw($userId, $amount, $currency, ['type' => 'seo_payment']);
        return $result ? 
            ['success' => true, 'transaction_id' => $result] : 
            ['success' => false, 'transaction_id' => null];
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        logger('seo_track', "Event: {$eventType}, Ad: {$adId}");
        return ['success' => true, 'message' => 'رویداد ثبت شد'];
    }

    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        return $ad ? ['id' => $ad->id, 'type' => 'seo', 'status' => $ad->status] : null;
    }
}
