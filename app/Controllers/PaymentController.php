<?php

namespace App\Controllers;

use App\Services\PaymentService;
use App\Services\WalletService;
use App\Controllers\BaseController;
use Core\Validator;

class PaymentController extends BaseController
{
    private WalletService $walletService;
private PaymentService $paymentService;
private \Core\Logger $logger;

public function __construct(
    WalletService $walletService,
    PaymentService $paymentService,
    \Core\Logger $logger
) {
    parent::__construct();
    $this->walletService = $walletService;
    $this->paymentService = $paymentService;
    $this->logger = $logger;
}

    /**
     * درخواست پرداخت آنلاین
     */
    public function request(): void
    {
        if (!auth()) {
            $this->session->setFlash('error', 'ابتدا وارد شوید');
            redirect('/auth/login');
            return;
        }

        $userId = $this->userId();

        // دریافت داده‌ها
        $data = [
            'gateway' => $this->request->input('gateway'),
            'amount' => $this->request->input('amount'),
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'gateway' => 'required|in:zarinpal,nextpay,idpay,dgpay',
            'amount' => 'required|numeric|min:10000',
        ], [
            'gateway.required' => 'انتخاب درگاه الزامی است',
            'amount.required' => 'مبلغ الزامی است',
            'amount.min' => 'حداقل مبلغ پرداخت 10,000 تومان است',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0]);
            redirect('/wallet/deposit');
            return;
        }

       try {
    $amount = (float)$data['amount'];
    $bankCardId = (int)($this->request->input('bank_card_id') ?? 0);

    $result = $this->paymentService->create(
        $userId,
        (string)$data['gateway'],
        $amount,
        $bankCardId
    );

    if (empty($result['success'])) {
        throw new \RuntimeException($result['message'] ?? 'خطا در ایجاد پرداخت');
    }

    redirect($result['payment_url']);
} catch (\Throwable $e) {
    $this->logger->error('payment.request.failed', [
        'channel' => 'payment',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    $this->session->setFlash('error', 'خطا در اتصال به درگاه پرداخت');
    redirect('/wallet/deposit');
}
    }

    /**
     * بازگشت از درگاه پرداخت
     */
   public function callback(): void
{
    $gateway = (string)(
        $this->request->get('gateway')
        ?? $this->request->param('gateway')
        ?? ''
    );

    if ($gateway === '') {
        $this->session->setFlash('error', 'درگاه نامعتبر است');
        redirect('/wallet');
        return;
    }

    try {
        $result = $this->paymentService->callback($gateway, $this->request->all());

        if (!empty($result['success'])) {
            $this->session->setFlash('success', $result['message'] ?? 'پرداخت با موفقیت انجام شد');
        } else {
            $this->session->setFlash('error', $result['message'] ?? 'پرداخت ناموفق بود');
        }

        redirect('/wallet');
    } catch (\Throwable $e) {
        $this->logger->error('payment.callback.failed', [
            'channel' => 'payment',
            'gateway' => $gateway,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->session->setFlash('error', 'پرداخت ناموفق بود');
        redirect('/wallet');
    }
}
}