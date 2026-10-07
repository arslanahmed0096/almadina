<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SupplierPaymentAllocationService
{
    public function plan(iterable $purchases, float $amount): Collection
    {
        $purchases = collect($purchases);
        $amount = round($amount, 2);
        $outstanding = round($purchases->sum(fn ($purchase) => $this->due($purchase)), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Payment amount must be greater than zero.']]);
        }
        if ($amount > $outstanding) {
            throw ValidationException::withMessages([
                'amount' => ['Payment cannot exceed the supplier outstanding balance of '.number_format($outstanding, 2).'.'],
            ]);
        }

        $remaining = $amount;
        return $purchases->map(function ($purchase) use (&$remaining) {
            $allocated = min($remaining, $this->due($purchase));
            $remaining = round($remaining - $allocated, 2);

            return ['purchase' => $purchase, 'amount' => round($allocated, 2)];
        })->filter(fn ($allocation) => $allocation['amount'] > 0)->values();
    }

    private function due($purchase): float
    {
        return round(max(0, (float) $purchase->GrandTotal - (float) $purchase->paid_amount), 2);
    }
}
