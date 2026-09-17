<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductMarginPricingService
{
    private const FIELDS = ['min_price', 'wholesale_price', 'price'];

    public function apply(Model $product, array $rows): void
    {
        $base = (float) ($product->purchase_price ?? 0);
        if ($rows && $base <= 0) {
            throw ValidationException::withMessages(['pricing_margins' => 'A purchase price is required before margins can be applied.']);
        }

        $previous = null;
        $clean = [];
        foreach (array_values($rows) as $index => $row) {
            $type = $row['type'] ?? null;
            $value = $row['value'] ?? null;
            if (! in_array($type, ['percentage', 'fixed'], true) || ! is_numeric($value) || (float) $value < 0) {
                throw ValidationException::withMessages(['pricing_margins' => 'Each margin must be a non-negative percentage or fixed amount.']);
            }
            $value = (float) $value;
            $profit = round($type === 'percentage' ? $base * $value / 100 : $value);
            $price = round($base + $profit);
            if ($previous !== null && $price <= $previous) {
                throw ValidationException::withMessages(['pricing_margins' => 'Each next margin price must be higher than the preceding price.']);
            }
            $previous = $price;
            $label = $index < count(self::FIELDS)
                ? ['Minimum Price', 'Wholesale Price', 'Al-Madina Price'][$index]
                : trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = 'Custom Price '.($index + 1);
            }
            if ($index < count(self::FIELDS)) {
                $field = $product instanceof ProductVariant && $index === 1 ? 'wholesale' : self::FIELDS[$index];
                $product->{$field} = $price;
            }
            $clean[] = [
                'type' => $type,
                'value' => $value,
                'label' => $label,
                'profit' => $profit,
                'calculated_price' => $price,
            ];
        }
        $product->pricing_margins = $clean;
    }

    public function effectivePurchasePrice(Model $product): array
    {
        if ((float) ($product->purchase_price ?? 0) > 0) {
            return ['price' => (float) $product->purchase_price, 'source' => 'stored'];
        }

        $isVariant = $product instanceof ProductVariant;
        $purchase = Purchase::whereNull('deleted_at')
            ->whereHas('details', function ($query) use ($product, $isVariant) {
                if ($isVariant) {
                    $query->where('product_variant_id', $product->id);
                } else {
                    $query->where('product_id', $product->id)->whereNull('product_variant_id');
                }
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if ($purchase && (float) $purchase->GrandTotal > 0) {
            $lines = $purchase->details()->get();
            $lineSum = (float) $lines->sum('total');
            if ($lineSum > 0) {
                $units = Unit::whereIn('id', $lines->pluck('purchase_unit_id')->filter()->unique())
                    ->get()->keyBy('id');
                $amount = 0;
                $quantity = 0;
                foreach ($lines as $line) {
                    $matches = $isVariant
                        ? (int) $line->product_variant_id === (int) $product->id
                        : (int) $line->product_id === (int) $product->id && ! $line->product_variant_id;
                    if (! $matches || (float) $line->total <= 0) {
                        continue;
                    }
                    $lineQuantity = (float) $line->quantity;
                    $unit = $units->get($line->purchase_unit_id);
                    if ($unit && (float) $unit->operator_value > 0) {
                        $lineQuantity = $unit->operator === '/'
                            ? $lineQuantity / (float) $unit->operator_value
                            : $lineQuantity * (float) $unit->operator_value;
                    }
                    if ($lineQuantity <= 0) {
                        continue;
                    }
                    $amount += (float) $line->total;
                    $quantity += $lineQuantity;
                }
                if ($quantity > 0) {
                    return ['price' => round($amount / $quantity, 2), 'source' => 'invoice'];
                }
            }
        }

        $cost = (float) ($product->cost ?? 0);
        return ['price' => $cost > 0 ? $cost : 0, 'source' => $cost > 0 ? 'cost' : 'none'];
    }

    public function syncPurchase(Purchase $purchase): void
    {
        $lines = PurchaseDetail::where('purchase_id', $purchase->id)->get();
        $lineSum = (float) $lines->sum('total');
        if ($lineSum <= 0 || (float) $purchase->GrandTotal <= 0) {
            return;
        }

        $units = Unit::whereIn('id', $lines->pluck('purchase_unit_id')->filter()->unique())
            ->get()->keyBy('id');

        $allocations = [];
        foreach ($lines as $line) {
            $quantity = (float) $line->quantity;
            $unit = $units->get($line->purchase_unit_id);
            if ($unit && (float) $unit->operator_value > 0) {
                $quantity = $unit->operator === '/'
                    ? $quantity / (float) $unit->operator_value
                    : $quantity * (float) $unit->operator_value;
            }
            if ($quantity <= 0 || (float) $line->total <= 0) {
                continue;
            }

            $isVariant = (bool) $line->product_variant_id;
            $id = $isVariant ? $line->product_variant_id : $line->product_id;
            if (! $id) {
                continue;
            }
            $key = ($isVariant ? 'variant:' : 'product:').$id;
            if (! isset($allocations[$key])) {
                $allocations[$key] = ['id' => $id, 'variant' => $isVariant, 'amount' => 0, 'quantity' => 0];
            }
            // Purchase Price is this product line's Grand Total per base unit,
            // never the Grand Total of every product on the invoice.
            $allocations[$key]['amount'] += (float) $line->total;
            $allocations[$key]['quantity'] += $quantity;
        }

        DB::transaction(function () use ($allocations) {
            foreach ($allocations as $allocation) {
                $model = $allocation['variant']
                    ? ProductVariant::find($allocation['id'])
                    : Product::find($allocation['id']);
                if (! $model || $allocation['quantity'] <= 0) {
                    continue;
                }
                $model->purchase_price = round($allocation['amount'] / $allocation['quantity'], 2);
                $rows = $model->pricing_margins ?: [];
                if ($rows) {
                    $this->apply($model, $rows);
                }
                $model->save();
            }
        });
    }
}
