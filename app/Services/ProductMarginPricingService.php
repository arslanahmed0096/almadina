<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductMarginPricingService
{
    private const FIELDS = ['min_price', 'wholesale_price', 'price', 'fix_price'];

    private const LABELS = ['Minimum Price', 'Wholesale Price', 'Al-Madina Price', 'Regular Price'];

    private const ROUNDING_INCREMENTS = [1, 10, 50, 100, 500, 1000];

    public function apply(Model $product, array $rows): void
    {
        $discountResult = $this->applyFurtherDiscounts(
            $product,
            $product->further_discounts ?: []
        );
        $base = $discountResult['price'];
        if ($rows && $base <= 0) {
            throw ValidationException::withMessages(['pricing_margins' => 'A positive further discounted price is required before margins can be applied.']);
        }

        $previous = null;
        $clean = [];
        foreach (array_values($rows) as $index => $row) {
            $type = $row['type'] ?? null;
            $value = $row['value'] ?? null;
            if (! in_array($type, ['percentage', 'fixed'], true) || ! is_numeric($value) || (float) $value < 0) {
                throw ValidationException::withMessages(['pricing_margins' => 'Each margin must be a non-negative percentage or fixed amount.']);
            }
            $roundTo = isset($row['round_to']) && is_numeric($row['round_to'])
                ? (int) $row['round_to']
                : 1;
            if (! in_array($roundTo, self::ROUNDING_INCREMENTS, true)) {
                throw ValidationException::withMessages(['pricing_margins' => 'Each rounding increment must be 1, 10, 50, 100, 500, or 1000.']);
            }
            $value = (float) $value;
            $marginProfit = round($type === 'percentage' ? $base * $value / 100 : $value);
            $rawPrice = round($base + $marginProfit);
            $price = (float) (ceil($rawPrice / $roundTo) * $roundTo);
            $profit = round($price - $base);
            if ($previous !== null && $price <= $previous) {
                throw ValidationException::withMessages(['pricing_margins' => 'Each next margin price must be higher than the preceding price.']);
            }
            $previous = $price;
            $label = $index < count(self::FIELDS)
                ? self::LABELS[$index]
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
                'round_to' => $roundTo,
                'profit' => $profit,
                'calculated_price' => $price,
            ];
        }
        $product->pricing_margins = $clean;
    }

    public function applyFurtherDiscounts(Model $product, array $rows): array
    {
        $purchasePrice = round((float) ($product->purchase_price ?? 0), 2);
        $result = $this->calculateFurtherDiscounts($purchasePrice, $rows);
        $product->further_discounts = $result['discounts'];
        $product->further_discounted_price = $result['price'];

        return $result;
    }

    public function calculateFurtherDiscounts(float $purchasePrice, array $rows): array
    {
        $purchasePrice = round($purchasePrice, 2);
        if ($rows && $purchasePrice <= 0) {
            throw ValidationException::withMessages([
                'further_discounts' => 'An invoice price per item is required before further discounts can be applied.',
            ]);
        }

        $clean = [];
        $totalDiscount = 0.0;
        foreach (array_values($rows) as $index => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $type = $row['type'] ?? null;
            $value = $row['value'] ?? null;

            if ($label === '' || mb_strlen($label) > 100) {
                throw ValidationException::withMessages([
                    'further_discounts' => 'Each further discount must have a name of 100 characters or fewer.',
                ]);
            }
            if (! in_array($type, ['percentage', 'fixed'], true) || ! is_numeric($value) || (float) $value < 0) {
                throw ValidationException::withMessages([
                    'further_discounts' => 'Each further discount must be a non-negative percentage or fixed amount.',
                ]);
            }

            $value = (float) $value;
            if ($type === 'percentage' && $value > 100) {
                throw ValidationException::withMessages([
                    'further_discounts' => 'A percentage further discount cannot exceed 100%.',
                ]);
            }

            // Supplier discount percentages are independent benefits and are each
            // calculated from the original invoice price per item, not compounded.
            $amount = round($type === 'percentage' ? $purchasePrice * $value / 100 : $value, 2);
            $totalDiscount = round($totalDiscount + $amount, 2);
            if ($totalDiscount > $purchasePrice) {
                throw ValidationException::withMessages([
                    'further_discounts' => 'The total further discount cannot exceed the invoice price per item.',
                ]);
            }

            $clean[] = [
                'label' => $label,
                'type' => $type,
                'value' => $value,
                'amount' => $amount,
                'price_after' => round($purchasePrice - $totalDiscount, 2),
                'position' => $index + 1,
            ];
        }

        $discountedPrice = round($purchasePrice - $totalDiscount, 2);
        return [
            'purchase_price' => $purchasePrice,
            'total_discount' => $totalDiscount,
            'price' => $discountedPrice,
            'discounts' => $clean,
        ];
    }

    public function effectivePurchasePrice(Model $product): array
    {
        $isVariant = $product instanceof ProductVariant;
        $purchase = Purchase::whereNull('deleted_at')
            ->whereHas('details', function ($query) use ($product, $isVariant) {
                if ($isVariant) {
                    $query->where('product_variant_id', $product->id);
                } else {
                    $query->where('product_id', $product->id)->whereNull('product_variant_id');
                }
                $query->where('quantity', '>', 0)->where('total', '>', 0);
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if ($purchase) {
            $lines = $purchase->details()->get();
            $amount = 0;
            $quantity = 0;
            foreach ($lines as $line) {
                $matches = $isVariant
                    ? (int) $line->product_variant_id === (int) $product->id
                    : (int) $line->product_id === (int) $product->id && ! $line->product_variant_id;
                $lineTotal = (float) $line->total;
                $lineQuantity = (float) $line->quantity;
                if (! $matches || $lineTotal <= 0 || $lineQuantity <= 0) {
                    continue;
                }
                $amount += $lineTotal;
                $quantity += $lineQuantity;
            }
            if ($quantity > 0) {
                return ['price' => round($amount / $quantity, 2), 'source' => 'invoice'];
            }
        }

        if ((float) ($product->purchase_price ?? 0) > 0) {
            return ['price' => (float) $product->purchase_price, 'source' => 'stored'];
        }

        $cost = (float) ($product->cost ?? 0);
        return ['price' => $cost > 0 ? $cost : 0, 'source' => $cost > 0 ? 'cost' : 'none'];
    }

    public function syncPurchase(Purchase $purchase): void
    {
        $lines = PurchaseDetail::where('purchase_id', $purchase->id)->get();
        if ($lines->isEmpty()) {
            return;
        }

        $allocations = [];
        foreach ($lines as $line) {
            $quantity = (float) $line->quantity;
            $lineTotal = (float) $line->total;
            if ($quantity <= 0 || $lineTotal <= 0) {
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
            // Invoice price per item is the invoiced line total divided by the
            // quantity shown on that purchase invoice.
            $allocations[$key]['amount'] += $lineTotal;
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
                $discounts = $model->further_discounts ?: [];
                if ($rows || $discounts) {
                    $this->apply($model, $rows);
                } else {
                    $model->further_discounted_price = $model->purchase_price;
                }
                $model->save();
            }
        });
    }
}
