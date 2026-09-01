<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\PaymentSaleReturns;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReturnRefundService
{
    public function total(array $refunds): float
    {
        return round(
            max(0, (float) ($refunds['refund_cash_amount'] ?? 0))
            + max(0, (float) ($refunds['refund_bank_amount'] ?? 0))
            + max(0, (float) ($refunds['refund_easypaisa_amount'] ?? 0)),
            2
        );
    }

    public function paymentStatus(float $total, float $paid): string
    {
        if ($paid <= 0.005) {
            return 'unpaid';
        }

        return $paid + 0.005 >= $total ? 'paid' : 'partial';
    }

    public function record(SaleReturn $saleReturn, array $refunds, int $userId): void
    {
        $channels = [
            'cash' => [
                'amount' => (float) ($refunds['refund_cash_amount'] ?? 0),
                'account_id' => $refunds['refund_cash_account_id'] ?? null,
                'method_names' => ['cash'],
                'label' => 'Cash',
            ],
            'bank' => [
                'amount' => (float) ($refunds['refund_bank_amount'] ?? 0),
                'account_id' => $refunds['refund_bank_account_id'] ?? null,
                'method_names' => ['bank transfer', 'bank'],
                'label' => 'Bank',
            ],
            'easypaisa' => [
                'amount' => (float) ($refunds['refund_easypaisa_amount'] ?? 0),
                'account_id' => $refunds['refund_easypaisa_account_id'] ?? null,
                'method_names' => ['easypaisa', 'easy paisa'],
                'label' => 'EasyPaisa',
            ],
        ];

        foreach ($channels as $channel => $attributes) {
            if ($attributes['amount'] <= 0) {
                continue;
            }

            $method = PaymentMethod::whereNull('deleted_at')
                ->whereIn(DB::raw('LOWER(name)'), $attributes['method_names'])
                ->first();
            if (! $method) {
                throw ValidationException::withMessages([
                    "refund_{$channel}_amount" => ["The {$attributes['label']} payment method is not configured."],
                ]);
            }

            $accountId = $attributes['account_id'] ? (int) $attributes['account_id'] : null;
            if ($accountId) {
                $account = Account::whereNull('deleted_at')->lockForUpdate()->findOrFail($accountId);
                $account->update(['balance' => (float) $account->balance - $attributes['amount']]);
            }

            PaymentSaleReturns::create([
                'sale_return_id' => $saleReturn->id,
                'account_id' => $accountId,
                'Ref' => 'INV/RT_'.$saleReturn->id.'_'.strtoupper($channel),
                'date' => $saleReturn->date,
                'payment_method_id' => $method->id,
                'montant' => $attributes['amount'],
                'change' => 0,
                'notes' => $attributes['label'].' refund recorded with '.$saleReturn->Ref,
                'user_id' => $userId,
            ]);
        }
    }
}
