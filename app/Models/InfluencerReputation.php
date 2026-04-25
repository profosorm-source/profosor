<?php

namespace App\Models;

use Core\Model;

class InfluencerReputation extends Model
{
    /**
     * ثبت رویداد امتیاز
     */
    public function addEvent(array $d): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO influencer_reputation_events
            (profile_id, user_id, order_id, event_type, points, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $d['profile_id'],
            $d['user_id'],
            $d['order_id'] ?? null,
            $d['event_type'],
            $d['points'],
            $d['note'] ?? null,
        ]);
    }

    /**
     * آمار کامل یک پیج برای نمایش عمومی
     */
    public function getProfileStats(int $profileId): object
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(points), 0)                                              AS total_points,
                COUNT(CASE WHEN points > 0 THEN 1 END)                                AS positive_events,
                COUNT(CASE WHEN points < 0 THEN 1 END)                                AS negative_events
            FROM influencer_reputation_events
            WHERE profile_id = ?
        ");
        $stmt->execute([$profileId]);
        $events = $stmt->fetch(\PDO::FETCH_OBJ) ?: (object)['total_points'=>0,'positive_events'=>0,'negative_events'=>0];

        // آمار سفارش‌ها
        $stmt2 = $this->db->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COUNT(CASE WHEN status = 'completed' THEN 1 END)            AS completed_orders,
                COUNT(CASE WHEN status IN ('peer_resolution','escalated_to_admin','disputed') THEN 1 END) AS disputed_orders
            FROM story_orders
            WHERE influencer_id = ?
        ");
        $stmt2->execute([$profileId]);
        $orders = $stmt2->fetch(\PDO::FETCH_OBJ) ?: (object)['total_orders'=>0,'completed_orders'=>0,'disputed_orders'=>0];

        $totalOrders    = (int) $orders->total_orders;
        $completedOrders= (int) $orders->completed_orders;
        $disputedOrders = (int) $orders->disputed_orders;
        $totalPoints    = (int) $events->total_points;

        $completionRate = $totalOrders > 0 ? \round(($completedOrders / $totalOrders) * 100) : 0;
        $disputeRate    = $totalOrders > 0 ? \round(($disputedOrders  / $totalOrders) * 100) : 0;

        $grade = $this->calculateGrade($totalPoints, $completionRate, $disputeRate);

        return (object)[
            'total_points'    => $totalPoints,
            'total_orders'    => $totalOrders,
            'completed_orders'=> $completedOrders,
            'disputed_orders' => $disputedOrders,
            'completion_rate' => $completionRate,
            'dispute_rate'    => $disputeRate,
            'grade'           => $grade['letter'],
            'grade_label'     => $grade['label'],
            'grade_color'     => $grade['color'],
            'stars'           => $grade['stars'],
        ];
    }

    /**
     * تاریخچه رویدادها برای نمایش داخلی
     */
    public function getHistory(int $profileId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT e.*, so.order_type
            FROM influencer_reputation_events e
            LEFT JOIN story_orders so ON so.id = e.order_id
            WHERE e.profile_id = ?
            ORDER BY e.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$profileId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * محاسبه رتبه بر اساس امتیاز + نرخ تکمیل + نرخ اختلاف
     */
    private function calculateGrade(int $points, int $completionRate, int $disputeRate): array
    {
        // فرمول: امتیاز پایه + تنظیم بر اساس نرخ‌ها
        $score = $points
            + ($completionRate >= 90 ?  20 : ($completionRate >= 70 ?  10 : 0))
            - ($disputeRate    >= 30 ? -20 : ($disputeRate    >= 15 ? -10 : 0));

        if ($score >= 100 && $completionRate >= 85 && $disputeRate <= 10) {
            return ['letter'=>'A', 'label'=>'عالی', 'color'=>'success', 'stars'=>5];
        }
        if ($score >= 60 && $completionRate >= 70) {
            return ['letter'=>'B', 'label'=>'خوب', 'color'=>'primary', 'stars'=>4];
        }
        if ($score >= 20 && $completionRate >= 50) {
            return ['letter'=>'C', 'label'=>'متوسط', 'color'=>'warning', 'stars'=>3];
        }
        if ($score >= 0) {
            return ['letter'=>'D', 'label'=>'ضعیف', 'color'=>'orange', 'stars'=>2];
        }
        return ['letter'=>'F', 'label'=>'نامناسب', 'color'=>'danger', 'stars'=>1];
    }
}
