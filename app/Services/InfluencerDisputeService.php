<?php

namespace App\Services;

use App\Models\InfluencerDispute;
use Core\Logger;
use App\Models\StoryOrder;
use Core\Database;
use App\Services\AuditTrail;

class InfluencerDisputeService
{
    private InfluencerDispute      $disputeModel;
    private StoryOrder             $orderModel;
    private Database               $db;
    private NotificationService    $notificationService;
    private StoryPromotionService  $promotionService;
    private InfluencerReputationService $reputationService;
    private AuditTrail             $auditTrail;
    private StateMachineService    $stateMachine;
    private FinancialEscrowService $escrow;

    public function __construct(
        Database                    $db,
        InfluencerDispute           $disputeModel,
        StoryOrder                  $orderModel,
        NotificationService         $notificationService,
        StoryPromotionService       $promotionService,
        InfluencerReputationService $reputationService,
        AuditTrail                  $auditTrail,
        StateMachineService         $stateMachine,
        FinancialEscrowService      $escrow
    ) {
        $this->db                  = $db;
        $this->disputeModel        = $disputeModel;
        $this->orderModel          = $orderModel;
        $this->notificationService = $notificationService;
        $this->promotionService    = $promotionService;
        $this->reputationService   = $reputationService;
        $this->auditTrail          = $auditTrail;
        $this->stateMachine        = $stateMachine;
        $this->escrow              = $escrow;
    }

    // ══════════════════════════════════════════════════════
    //  باز کردن پرونده اختلاف توسط buyer
    // ══════════════════════════════════════════════════════

    public function openDispute(int $orderId, int $customerId, string $reason): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int)$order->customer_id !== $customerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($order->status !== 'peer_resolution') {
            return ['success' => false, 'message' => 'وضعیت سفارش برای اختلاف مناسب نیست.'];
        }

        // بررسی تکراری نبودن اختلاف
        $existing = $this->disputeModel->findByOrderId($orderId);
        if ($existing && !\in_array($existing->status, ['closed'])) {
            return ['success' => false, 'message' => 'اختلافی برای این سفارش قبلاً باز شده است.', 'dispute' => $existing];
        }

        $peerHours    = (int) setting('influencer_peer_resolution_hours', 24);
        $peerDeadline = \date('Y-m-d H:i:s', \strtotime("+{$peerHours} hours"));

        $dispute = $this->disputeModel->create([
            'order_id'           => $orderId,
            'customer_id'        => $customerId,
            'influencer_user_id' => (int)$order->influencer_user_id,
            'opened_by'          => $customerId,
            'reason'             => $reason,
            'peer_deadline'      => $peerDeadline,
        ]);

        if (!$dispute) {
            return ['success' => false, 'message' => 'خطا در ثبت اختلاف.'];
        }

        // پیام اول از طرف buyer
        $this->disputeModel->addMessage(
            (int)$dispute->id,
            $customerId,
            'customer',
            $reason
        );

        // نوتیف به اینفلوئنسر
        $this->notificationService->send(
            (int)$order->influencer_user_id,
            'influencer_dispute_opened',
            'اختلاف رسمی ثبت شد',
            "تبلیغ‌دهنده اختلاف رسمی برای سفارش #{$orderId} باز کرد. تا {$peerHours} ساعت برای پاسخ فرصت دارید.",
            ['order_id' => $orderId, 'dispute_id' => $dispute->id],
            url('/influencer/orders/' . $orderId . '/dispute'),
            'پنل اختلاف'
        );

        $this->auditTrail->record(
    'influencer.dispute.opened',
    $customerId,
    [
        'channel' => 'influencer',
        'dispute_id' => $dispute->id,
        'order_id' => $orderId,
        'reason' => $reason,
    ]
);
        return ['success' => true, 'message' => 'اختلاف ثبت شد. با اینفلوئنسر گفت‌وگو کنید.', 'dispute' => $dispute];
    }

    // ══════════════════════════════════════════════════════
    //  ارسال پیام در اختلاف
    // ══════════════════════════════════════════════════════

    public function sendMessage(int $disputeId, int $userId, string $role, string $message, ?string $attachment = null): array
    {
        $dispute = $this->disputeModel->find($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'اختلاف یافت نشد.'];
        }
        if (!\in_array($dispute->status, ['open_peer', 'escalated'])) {
            return ['success' => false, 'message' => 'این اختلاف بسته است.'];
        }

        // تایید هویت: فقط buyer یا influencer مجاز
        if ($role === 'customer' && (int)$dispute->customer_id !== $userId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($role === 'influencer' && (int)$dispute->influencer_user_id !== $userId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if (\mb_strlen(\trim($message)) < 5) {
            return ['success' => false, 'message' => 'پیام خیلی کوتاه است.'];
        }

        $msg = $this->disputeModel->addMessage($disputeId, $userId, $role, $message, $attachment);
        if (!$msg) {
            return ['success' => false, 'message' => 'خطا در ارسال پیام.'];
        }

        // نوتیف به طرف مقابل
        $recipientId = $role === 'customer'
            ? (int)$dispute->influencer_user_id
            : (int)$dispute->customer_id;

        $this->notificationService->send(
            $recipientId,
            'influencer_dispute_message',
            'پیام جدید در پرونده اختلاف',
            "پیام جدیدی در اختلاف سفارش #{$dispute->order_id} ارسال شد.",
            ['dispute_id' => $disputeId, 'order_id' => $dispute->order_id],
            url('/influencer/orders/' . $dispute->order_id . '/dispute'),
            'مشاهده پیام'
        );

        return ['success' => true, 'message' => 'پیام ارسال شد.', 'msg' => $msg];
    }

    // ══════════════════════════════════════════════════════
    //  توافق دوطرفه و بستن اختلاف
    // ══════════════════════════════════════════════════════

    public function resolveByAgreement(int $disputeId, int $initiatorId, string $resolution, string $verdict): array
    {
        $dispute = $this->disputeModel->find($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'اختلاف یافت نشد.'];
        }
        if ($dispute->status !== 'open_peer') {
            return ['success' => false, 'message' => 'این اختلاف در مرحله گفت‌وگوی طرفین نیست.'];
        }
        if (!\in_array($initiatorId, [(int)$dispute->customer_id, (int)$dispute->influencer_user_id])) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        $this->disputeModel->update($disputeId, [
            'status'          => 'resolved_peer',
            'resolution_note' => $resolution,
            'admin_verdict'   => $verdict,
            'resolved_by'     => $initiatorId,
            'resolved_at'     => \date('Y-m-d H:i:s'),
        ]);

        // اجرای نتیجه توافق
        $result = $this->executeVerdict($dispute, $verdict, $resolution, $initiatorId);

        // امتیازدهی
        $this->reputationService->scoreAfterDisputeResolution($dispute, $verdict, 'peer');

        $this->auditTrail->record(
    'influencer.dispute.resolved.peer',
    $initiatorId,
    [
        'channel' => 'influencer',
        'dispute_id' => $disputeId,
        'verdict' => $verdict,
        'resolution' => $resolution,
    ]
);
        return ['success' => true, 'message' => 'اختلاف دوستانه حل شد.', 'settlement' => $result];
    }

    // ══════════════════════════════════════════════════════
    //  ارجاع به مدیر (escalate)
    // ══════════════════════════════════════════════════════

    public function escalateToAdmin(int $disputeId, int $requesterId): array
    {
        $dispute = $this->disputeModel->find($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'اختلاف یافت نشد.'];
        }
        if ($dispute->status !== 'open_peer') {
            return ['success' => false, 'message' => 'این اختلاف قابل ارجاع نیست.'];
        }
        if (!\in_array($requesterId, [(int)$dispute->customer_id, (int)$dispute->influencer_user_id])) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        $this->disputeModel->update($disputeId, ['status' => 'escalated']);
        $this->orderModel->update((int)$dispute->order_id, ['status' => 'escalated_to_admin']);

        $this->auditTrail->record(
    'influencer.dispute.escalated',
    $requesterId,
    [
        'channel' => 'influencer',
        'dispute_id' => $disputeId,
        'order_id' => $dispute->order_id,
    ]
);

        return ['success' => true, 'message' => 'پرونده به مدیر ارجاع شد.'];
    }

    // ══════════════════════════════════════════════════════
    //  رأی نهایی مدیر
    // ══════════════════════════════════════════════════════

    public function adminResolve(int $disputeId, int $adminId, string $verdict, string $note, float $refundPercent = 0): array
    {
        $dispute = $this->disputeModel->find($disputeId);
        if (!$dispute || $dispute->status !== 'escalated') {
            return ['success' => false, 'message' => 'اختلاف برای داوری آماده نیست.'];
        }

        // ✅ State machine validation
        if (!$this->stateMachine->canTransitionDispute($dispute->status, 'resolved_admin')) {
            return ['success' => false, 'message' => 'این وضعیت برای رأی‌گیری مجاز نیست'];
        }

        // verdict: favor_customer | favor_influencer | partial
        if (!\in_array($verdict, ['favor_customer', 'favor_influencer', 'partial'])) {
            return ['success' => false, 'message' => 'رأی نامعتبر.'];
        }

        $this->disputeModel->update($disputeId, [
            'status'            => 'resolved_admin',
            'admin_verdict'     => $verdict,
            'admin_verdict_note'=> $note,
            'refund_percent'    => $refundPercent,
            'resolved_by'       => $adminId,
            'resolved_at'       => \date('Y-m-d H:i:s'),
        ]);

        $result = $this->executeVerdict($dispute, $verdict, $note, $adminId, $refundPercent);

        // امتیازدهی بر اساس رأی
        $this->reputationService->scoreAfterDisputeResolution($dispute, $verdict, 'admin');

        $this->auditTrail->record(
            'influencer.dispute.resolved.admin',
            $adminId,
            [
                'channel' => 'influencer',
                'dispute_id' => $disputeId,
                'verdict' => $verdict,
                'refund_percent' => $refundPercent,
                'note' => $note,
            ],
            $adminId
        );

        // نوتیف هر دو طرف
        foreach ([$dispute->customer_id, $dispute->influencer_user_id] as $uid) {
            $this->notificationService->send(
                (int)$uid,
                'influencer_dispute_resolved',
                'رأی مدیر صادر شد',
                "مدیر برای اختلاف سفارش #{$dispute->order_id} رأی صادر کرد.",
                ['dispute_id' => $disputeId, 'order_id' => $dispute->order_id],
                url('/influencer/advertise/my-orders'),
                'مشاهده نتیجه'
            );
        }

        return ['success' => true, 'message' => 'رأی ثبت و اجرا شد.', 'settlement' => $result];
    }

    // ══════════════════════════════════════════════════════
    //  CronJob — escalate اختلاف‌هایی که peer timeout شده
    // ══════════════════════════════════════════════════════

    public function processExpiredPeerResolutions(): int
    {
        $expired = $this->orderModel->getExpiredPeerResolutions();
        $count = 0;
        foreach ($expired as $o) {
            $dispute = $this->disputeModel->findByOrderId((int)$o->id);
            if (!$dispute || $dispute->status !== 'open_peer') continue;

            $this->disputeModel->update((int)$dispute->id, ['status' => 'escalated']);
            $this->orderModel->update((int)$o->id, ['status' => 'escalated_to_admin']);

            $this->logger->info('story.peer_resolution_auto_escalated', [
                'dispute_id' => $dispute->id, 'order_id' => $o->id,
            ]);
            $count++;
        }
        return $count;
    }

    // ══════════════════════════════════════════════════════
    //  اجرای رأی (خصوصی)
    // ══════════════════════════════════════════════════════

    private function executeVerdict(object $dispute, string $verdict, string $reason, int $actorId, float $refundPercent = 0): array
    {
        switch ($verdict) {
            case 'favor_customer':
                return $this->promotionService->refundOrder((int)$dispute->order_id, $actorId, 100, $reason);

            case 'favor_influencer':
                return $this->promotionService->completeOrder((int)$dispute->order_id, $actorId, 'dispute_' . $reason);

            case 'partial':
                $rp = $refundPercent > 0 ? $refundPercent : 50.0;
                return $this->promotionService->refundOrder((int)$dispute->order_id, $actorId, $rp, $reason);

            default:
                return ['success' => false, 'message' => 'رأی نامعتبر.'];
        }
    }
}
