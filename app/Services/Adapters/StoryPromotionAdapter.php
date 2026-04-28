<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use Core\Database;

class StoryPromotionAdapter implements AdSystemContract
{
    public function __construct(private Database $db) {}

    public function getType(): string { return 'story_promotion'; }

    public function create(int $userId, array $data): array
    {
        $errors = $this->validate($data);
        if (!$errors['valid']) {
            return ['success' => false, 'message' => implode(', ', $errors['errors'])];
        }

        try {
            $result = $this->db->query(
                "INSERT INTO story_promotions (user_id, title, budget, status, created_at) 
                 VALUES (?, ?, ?, 'pending', NOW())",
                [$userId, $data['title'], $data['budget'] ?? 0]
            );

            return $result ? 
                ['success' => true, 'id' => $this->db->lastInsertId(), 'message' => 'تبلیغ داستان ایجاد شد'] :
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
            "SELECT status FROM story_promotions WHERE id = ? LIMIT 1",
            [$adId]
        )->fetch();
        return !$result || in_array($result->status, ['expired', 'completed']);
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) setting('story_promotion_site_fee_percent', 8) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        return ['success' => true, 'transaction_id' => 0, 'message' => 'پرداخت پردازش شد'];
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        logger('story_promotion_track', "Event: {$eventType}, Promotion: {$adId}");
        return ['success' => true, 'message' => 'رویداد ثبت شد'];
    }

    public function getStatus(int $adId): ?array
    {
        $result = $this->db->query(
            "SELECT id, status FROM story_promotions WHERE id = ? LIMIT 1",
            [$adId]
        )->fetch();
        return $result ? ['id' => $result->id, 'type' => 'story_promotion', 'status' => $result->status] : null;
    }
}
