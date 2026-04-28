<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use Core\Database;

class AdTubeAdapter implements AdSystemContract
{
    public function __construct(private Database $db) {}

    public function getType(): string { return 'adtube'; }

    public function create(int $userId, array $data): array
    {
        $errors = $this->validate($data);
        if (!$errors['valid']) {
            return ['success' => false, 'message' => implode(', ', $errors['errors'])];
        }

        try {
            $result = $this->db->query(
                "INSERT INTO adtube_campaigns (creator_id, title, budget, status, created_at) 
                 VALUES (?, ?, ?, 'pending', NOW())",
                [$userId, $data['title'], $data['budget'] ?? 0]
            );

            return $result ? 
                ['success' => true, 'id' => $this->db->lastInsertId(), 'message' => 'تبلیغ AdTube ایجاد شد'] :
                ['success' => false, 'message' => 'خطا در ایجاد'];
        } catch (\Exception $e) {
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
        $result = $this->db->query(
            "SELECT status FROM adtube_campaigns WHERE id = ? LIMIT 1",
            [$adId]
        )->fetch();
        return !$result || in_array($result->status, ['expired', 'completed']);
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) setting('adtube_site_fee_percent', 20) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        return ['success' => true, 'transaction_id' => 0, 'message' => 'پرداخت پردازش شد'];
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        logger('adtube_track', "Event: {$eventType}, Campaign: {$adId}");
        return ['success' => true, 'message' => 'رویداد ثبت شد'];
    }

    public function getStatus(int $adId): ?array
    {
        $result = $this->db->query(
            "SELECT id, status FROM adtube_campaigns WHERE id = ? LIMIT 1",
            [$adId]
        )->fetch();
        return $result ? ['id' => $result->id, 'type' => 'adtube', 'status' => $result->status] : null;
    }
}
