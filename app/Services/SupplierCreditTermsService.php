<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseCategoryCreditTerm;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SupplierCreditTermsService
{
    public function syncPurchase(Purchase $purchase): void
    {
        $purchase->loadMissing('provider', 'details');
        $provider = $purchase->provider;
        if (! $provider || $provider->credit_terms_mode !== 'category') {
            PurchaseCategoryCreditTerm::where('purchase_id', $purchase->id)->delete();

            return;
        }

        $details = $purchase->details;
        if ($details->isEmpty()) {
            PurchaseCategoryCreditTerm::where('purchase_id', $purchase->id)->delete();

            return;
        }

        $products = Product::whereIn('id', $details->pluck('product_id')->unique())
            ->with('category:id,name')
            ->get(['id', 'category_id'])
            ->keyBy('id');
        $categoryTerms = $provider->categories()
            ->get(['categories.id', 'categories.name'])
            ->keyBy('id');

        $categoryAmounts = $details->groupBy(function ($detail) use ($products) {
            return $products->get($detail->product_id)?->category_id ?: 0;
        })->map(function (Collection $rows) {
            return (float) $rows->sum('total');
        });

        $subtotal = (float) $categoryAmounts->sum();
        $grandTotal = round((float) $purchase->GrandTotal, 2);
        $remaining = $grandTotal;
        $purchaseDate = Carbon::parse($purchase->date);
        $terms = [];
        $lastKey = $categoryAmounts->keys()->last();

        foreach ($categoryAmounts as $categoryId => $categorySubtotal) {
            $category = $categoryId ? $categoryTerms->get((int) $categoryId) : null;
            $amount = (int) $categoryId === (int) $lastKey
                ? $remaining
                : ($subtotal > 0 ? round($grandTotal * $categorySubtotal / $subtotal, 2) : 0);
            $remaining = round($remaining - $amount, 2);
            $days = (int) ($category?->pivot?->credit_days ?: $provider->credit_days ?: 15);
            $terms[] = [
                'purchase_id' => $purchase->id,
                'category_id' => $category?->id,
                'category_name' => $category?->name
                    ?? ($categoryId ? $products->firstWhere('category_id', (int) $categoryId)?->category?->name : null)
                    ?? 'Uncategorized',
                'credit_days' => $days,
                'amount' => max(0, $amount),
                'due_date' => $purchaseDate->copy()->addDays($days)->toDateString(),
            ];
        }

        PurchaseCategoryCreditTerm::where('purchase_id', $purchase->id)->delete();
        foreach ($terms as $term) {
            PurchaseCategoryCreditTerm::create($term);
        }
    }

    public function outstanding(Purchase $purchase, Carbon $asOf): Collection
    {
        $terms = $purchase->relationLoaded('categoryCreditTerms')
            ? $purchase->categoryCreditTerms->sortBy([
                ['due_date', 'asc'],
                ['id', 'asc'],
            ])->values()
            : PurchaseCategoryCreditTerm::where('purchase_id', $purchase->id)
                ->orderBy('due_date')
                ->orderBy('id')
                ->get();

        if ($terms->isEmpty()) {
            $amount = round(max(0, (float) $purchase->GrandTotal - (float) $purchase->paid_amount), 2);
            if ($amount <= 0) {
                return collect();
            }

            return collect([[
                'category_name' => null,
                'due_date' => $purchase->payment_due_date
                    ? Carbon::parse($purchase->payment_due_date)->toDateString()
                    : Carbon::parse($purchase->date)->addDays((int) ($purchase->payment_due_days ?: 15))->toDateString(),
                'credit_days' => (int) ($purchase->payment_due_days ?: 15),
                'outstanding' => $amount,
            ]]);
        }

        $paidRemaining = max(0, (float) $purchase->paid_amount);

        return $terms->map(function (PurchaseCategoryCreditTerm $term) use (&$paidRemaining) {
            $paid = min($paidRemaining, (float) $term->amount);
            $paidRemaining = max(0, $paidRemaining - $paid);
            $outstanding = round(max(0, (float) $term->amount - $paid), 2);

            return [
                'category_name' => $term->category_name,
                'due_date' => $term->due_date->toDateString(),
                'credit_days' => $term->credit_days,
                'outstanding' => $outstanding,
            ];
        })->filter(fn ($term) => $term['outstanding'] > 0.009)->values();
    }
}
