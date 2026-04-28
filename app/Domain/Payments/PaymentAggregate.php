<?php

namespace App\Domain\Payments;

use App\Models\PaymentLog;
use App\Models\Wallet;
use App\Services\WalletService;
use Core\Database;

/**
 * PaymentAggregate - Aggregate Root برای domain پرداخت
 *
 * این کلاس business rules پرداخت را مدیریت می‌کند
 */
class PaymentAggregate
{
    private Database $db;
    private WalletService $walletService;
    private PaymentLog $paymentLog;

    public function __construct(Database $db, WalletService $walletService, PaymentLog $paymentLog)
    {
        $this->db = $db;
        $this->walletService = $walletService;
        $this->paymentLog = $paymentLog;
    }

    /**
     * پردازش پرداخت موفق
     */
    public function processSuccessfulPayment(int $userId, string $gateway, float $amount, string $authority, string $refId): bool
    {
        $this->db->beginTransaction();

        try {
            // آپدیت wallet
            $walletResult = $this->walletService->deposit($userId, $amount, 'payment', "پرداخت از {$gateway} - {$refId}");

            if (!$walletResult['success']) {
                throw new \Exception('Wallet update failed: ' . $walletResult['message']);
            }

            // آپدیت payment log
            $this->paymentLog
                ->where('authority', $authority)
                ->update([
                    'status' => 'completed',
                    'ref_id' => $refId,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            $this->db->commit();
            return true;

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * پردازش پرداخت ناموفق
     */
    public function processFailedPayment(string $authority): void
    {
        $this->paymentLog
            ->where('authority', $authority)
            ->update([
                'status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }

    /**
     * اعتبارسنجی پرداخت
     */
    public function validatePayment(int $userId, float $amount, string $gateway): array
    {
        $errors = [];

        if ($amount < 1000) {
            $errors[] = 'حداقل مبلغ ۱۰۰۰ تومان';
        }

        if (!in_array($gateway, ['zarinpal', 'nextpay', 'idpay', 'dgpay'])) {
            $errors[] = 'درگاه نامعتبر';
        }

        // سایر business rules...

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}