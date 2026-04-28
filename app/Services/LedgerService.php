<?php

namespace App\Services;

use App\Models\LedgerEntry;
use Core\Container;
use Core\Database;

class LedgerService
{
    private LedgerEntry $ledgerEntry;
    private Database $db;

    public function __construct(LedgerEntry $ledgerEntry, Database $db)
    {
        $this->ledgerEntry = $ledgerEntry;
        $this->db = $db;
    }

    public function recordEntry(array $data): ?object
    {
        return $this->ledgerEntry->create($data);
    }

    public function recordDoubleEntry(
        string $transactionId,
        string $debitAccount,
        string $creditAccount,
        float $amount,
        string $description = null,
        array $metadata = []
    ): bool {
        if ($amount <= 0) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $common = [
                'transaction_id' => $transactionId,
                'description' => $description,
                'metadata' => $metadata,
            ];

            $debit = $this->recordEntry(array_merge($common, [
                'account' => $debitAccount,
                'debit' => $amount,
                'credit' => 0,
            ]));

            $credit = $this->recordEntry(array_merge($common, [
                'account' => $creditAccount,
                'debit' => 0,
                'credit' => $amount,
            ]));

            if (!$debit || !$credit) {
                $this->db->rollBack();
                return false;
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger()->error('ledger.record_double_entry.failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
