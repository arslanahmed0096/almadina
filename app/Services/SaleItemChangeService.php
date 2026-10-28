<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleItemChange;
use App\Models\User;
use Illuminate\Support\Collection;

class SaleItemChangeService
{
    /**
     * Persist product additions, removals, and replacements made while editing a sale.
     * Quantity/price-only edits are intentionally excluded from this product history.
     */
    public function recordDifferences(Sale $sale, Collection $before, Collection $after, ?User $user): void
    {
        foreach ($this->differences($before, $after) as $change) {
            SaleItemChange::create([
                'sale_id' => $sale->id,
                'sale_detail_id' => $change['sale_detail_id'],
                'user_id' => $user?->id,
                'action' => $change['action'],
                'old_values' => $change['old_values'],
                'new_values' => $change['new_values'],
            ]);
        }
    }

    /**
     * @return array<int, array{sale_detail_id: int|null, action: string, old_values: array|null, new_values: array|null}>
     */
    public function differences(Collection $before, Collection $after): array
    {
        $oldById = $before->keyBy(fn (SaleDetail $detail) => (int) $detail->id);
        $newById = $after->keyBy(fn (SaleDetail $detail) => (int) $detail->id);
        $changes = [];

        foreach ($oldById as $detailId => $oldDetail) {
            /** @var SaleDetail|null $newDetail */
            $newDetail = $newById->get($detailId);
            if (! $newDetail) {
                $changes[] = $this->change('product_removed', $oldDetail, null);

                continue;
            }

            if ($this->identity($oldDetail) !== $this->identity($newDetail)) {
                $changes[] = $this->change('product_replaced', $oldDetail, $newDetail);
            }
        }

        foreach ($newById as $detailId => $newDetail) {
            if (! $oldById->has($detailId)) {
                $changes[] = $this->change('product_added', null, $newDetail);
            }
        }

        return $changes;
    }

    public function present(Collection $changes): array
    {
        return $changes->map(function (SaleItemChange $change) {
            $user = $change->user;
            $userName = $user
                ? trim((string) ($user->firstname.' '.$user->lastname))
                : '';

            return [
                'id' => $change->id,
                'action' => $change->action,
                'sale_detail_id' => $change->sale_detail_id,
                'old_item' => $change->old_values,
                'new_item' => $change->new_values,
                'changed_by' => $userName !== '' ? $userName : ($user?->username ?? 'System'),
                'changed_at' => optional($change->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    private function identity(SaleDetail $detail): string
    {
        return (int) $detail->product_id.':'.(int) ($detail->product_variant_id ?? 0);
    }

    private function change(string $action, ?SaleDetail $oldDetail, ?SaleDetail $newDetail): array
    {
        return [
            'sale_detail_id' => (int) ($newDetail?->id ?? $oldDetail?->id) ?: null,
            'action' => $action,
            'old_values' => $oldDetail ? $this->snapshot($oldDetail) : null,
            'new_values' => $newDetail ? $this->snapshot($newDetail) : null,
        ];
    }

    private function snapshot(SaleDetail $detail): array
    {
        $product = $detail->relationLoaded('product') ? $detail->product : $detail->product()->first();
        $variant = $detail->product_variant_id
            ? ($detail->relationLoaded('productVariant') ? $detail->productVariant : $detail->productVariant()->first())
            : null;

        return [
            'product_id' => (int) $detail->product_id,
            'product_variant_id' => $detail->product_variant_id ? (int) $detail->product_variant_id : null,
            'name' => (string) ($product->name ?? 'Unknown product'),
            'code' => (string) ($variant->code ?? $product->code ?? ''),
            'variant' => $variant?->name,
            'quantity' => (float) $detail->quantity,
        ];
    }
}
