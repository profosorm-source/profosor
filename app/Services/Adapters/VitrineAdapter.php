<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use App\Models\VitrineListing;
use App\Services\WalletService;
use Core\Database;

class VitrineAdapter implements AdSystemContract
{
    public function __construct(
        private VitrineListing $listingModel,
        private WalletService $walletService,
        private Database $db
    ) {}

    public function getType(): string { return 'vitrine'; }

    public function create(int $userId, array $data): array
    {
        $errors = $this->validate($data);
        if (!$errors['valid']) {
            return ['success' => false, 'message' => implode(', ', $errors['errors'])];
        }

        $price = (float) ($data['price'] ?? 0);
        $feePercent = (float) setting('vitrine_site_fee_percent', 5);
        $feeAmount = $price * ($feePercent / 100);

        try {
            $this->db->beginTransaction();

            $listing = $this->listingModel->create([
                'seller_id' => $userId,
                'title' => $data['title'],
                'price' => $price,
                'status' => 'pending',
                'description' => $data['description'] ?? null,
            ]);

            $this->db->commit();
            return ['success' => true, 'id' => $listing->id, 'message' => 'فهرست ویترین ایجاد شد'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا: ' . $e->getMessage()];
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        if (empty($data['title'])) $errors[] = 'عنوان الزامی است';
        if (empty($data['price']) || (float)$data['price'] <= 0) $errors[] = 'قیمت باید مثبت باشد';
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isExpired(int $adId): bool
    {
        $listing = $this->listingModel->find($adId);
        return !$listing || $listing->status === 'expired' || $listing->status === 'sold';
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) setting('vitrine_site_fee_percent', 5) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $result = $this->walletService->withdraw($userId, $amount, $currency, ['type' => 'vitrine_payment']);
        return $result ? 
            ['success' => true, 'transaction_id' => $result] : 
            ['success' => false, 'transaction_id' => null];
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        logger('vitrine_track', "Event: {$eventType}, Listing: {$adId}");
        return ['success' => true, 'message' => 'رویداد ثبت شد'];
    }

    public function getStatus(int $adId): ?array
    {
        $listing = $this->listingModel->find($adId);
        return $listing ? ['id' => $listing->id, 'type' => 'vitrine', 'status' => $listing->status] : null;
    }
}
