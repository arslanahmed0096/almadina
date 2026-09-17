<?php

namespace App\Services;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

class WarehouseStockGuard
{
    /**
     * Assert that a warehouse can fulfil every non-service sale line.
     * Matching stock rows are locked until the surrounding transaction commits.
     */
    public function assertSaleAvailable(int $warehouseId, array $details): void
    {
        $this->assertAvailable($warehouseId, $details, 'sale');
    }

    /** Assert that a source warehouse can fulfil every transfer line. */
    public function assertTransferAvailable(int $warehouseId, array $details): void
    {
        $this->assertAvailable($warehouseId, $details, 'transfer');
    }

    private function assertAvailable(int $warehouseId, array $details, string $operation): void
    {
        $productIds = collect($details)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $products = Product::whereNull('deleted_at')
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'code', 'type', 'unit_sale_id', 'unit_purchase_id'])
            ->keyBy('id');

        $unitIds = collect($details)
            ->flatMap(function ($detail) use ($products, $operation) {
                $product = $products->get((int) ($detail['product_id'] ?? 0));
                $payloadUnitId = $operation === 'sale'
                    ? ($detail['sale_unit_id'] ?? $detail['unit_sale_id'] ?? null)
                    : ($detail['purchase_unit_id'] ?? null);
                $fallbackUnitId = $operation === 'sale'
                    ? optional($product)->unit_sale_id
                    : optional($product)->unit_purchase_id;

                return array_filter([$payloadUnitId, $fallbackUnitId]);
            })
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $units = Unit::whereIn('id', $unitIds)->get()->keyBy('id');
        $requiredByStockRow = [];

        foreach ($details as $index => $detail) {
            $productId = (int) ($detail['product_id'] ?? 0);
            $quantity = (float) ($detail['quantity'] ?? $detail['qty'] ?? 0);
            $product = $products->get($productId);

            if (! $product || $quantity <= 0 || $product->type === 'is_service') {
                continue;
            }

            $variantValue = $detail['product_variant_id'] ?? null;
            $variantId = $variantValue === null || $variantValue === '' ? null : (int) $variantValue;
            $unitId = $operation === 'sale'
                ? ($detail['sale_unit_id'] ?? $detail['unit_sale_id'] ?? $product->unit_sale_id)
                : ($detail['purchase_unit_id'] ?? $product->unit_purchase_id);
            $unit = $unitId ? $units->get((int) $unitId) : null;
            $requiredBase = $this->toBaseQuantity($quantity, $unit);
            $key = $productId.':'.($variantId ?? 'base');

            if (! isset($requiredByStockRow[$key])) {
                $requiredByStockRow[$key] = [
                    'product' => $product,
                    'variant_id' => $variantId,
                    'required_base' => 0.0,
                    'first_index' => $index,
                ];
            }

            $requiredByStockRow[$key]['required_base'] += $requiredBase;
        }

        ksort($requiredByStockRow);

        foreach ($requiredByStockRow as $item) {
            $query = product_warehouse::whereNull('deleted_at')
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $item['product']->id);
            $item['variant_id'] === null
                ? $query->whereNull('product_variant_id')
                : $query->where('product_variant_id', $item['variant_id']);

            $stock = $query->lockForUpdate()->first();
            $available = $stock ? (float) $stock->qte : 0.0;
            $required = (float) $item['required_base'];

            if ($available + 0.000001 >= $required) {
                continue;
            }

            $productLabel = trim((string) $item['product']->name);
            if ($item['product']->code) {
                $productLabel .= ' ('.$item['product']->code.')';
            }

            $action = $operation === 'sale' ? 'Sale' : 'Transfer';
            $message = sprintf(
                '%s not allowed: insufficient stock for %s in the selected warehouse. Requested %s, available %s.',
                $action,
                $productLabel,
                $this->formatQuantity($required),
                $this->formatQuantity($available)
            );

            $exception = ValidationException::withMessages([
                'details.'.$item['first_index'].'.quantity' => [$message],
                'stock' => [$message],
            ]);
            $exception->response = response()->json([
                'message' => $message,
                'errors' => $exception->errors(),
            ], 422);

            throw $exception;
        }
    }

    private function toBaseQuantity(float $quantity, ?Unit $unit): float
    {
        if (! $unit) {
            return $quantity;
        }

        $operatorValue = (float) $unit->operator_value;
        if ($operatorValue <= 0) {
            $operatorValue = 1.0;
        }

        return $unit->operator === '/' ? $quantity / $operatorValue : $quantity * $operatorValue;
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
    }
}
