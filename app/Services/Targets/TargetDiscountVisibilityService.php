<?php

namespace App\Services\Targets;

use App\Models\Product;
use App\Models\SupplierTargetLine;
use App\Services\ProductMarginPricingService;
use App\Services\ProductSupplierResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TargetDiscountVisibilityService
{
    public function __construct(
        private TargetAchievementService $achievement,
        private ProductMarginPricingService $pricing,
        private ProductSupplierResolver $supplierResolver
    ) {}

    public function forProduct(Product $product, int $warehouseId, ?string $date = null): Collection
    {
        $saleDate = $date ? Carbon::parse($date)->format('Y-m-d') : now()->format('Y-m-d');
        $categoryIds = collect([$product->category_id])->filter()
            ->merge(DB::table('category_product')->where('product_id', $product->id)->pluck('category_id'))
            ->map(fn ($id) => (int) $id)->unique()->values();

        $lines = SupplierTargetLine::with([
            'target.supplier:id,name',
            'target.lines.product:id,name',
            'target.lines.category:id,name',
            'target.lines.allocations.warehouse:id,name',
            'target.lines.discountPostings',
            'target.allocations.warehouse:id,name',
            'allocations',
        ])->where(function ($query) {
            $query->whereNotNull('further_discounts')->orWhere('further_discount_per_unit', '>', 0);
        })
            ->where(function ($query) use ($product, $categoryIds) {
                $query->where('product_id', $product->id);
                if ($categoryIds->isNotEmpty()) {
                    $query->orWhereIn('category_id', $categoryIds);
                }
            })
            ->whereHas('target', function ($query) use ($saleDate, $warehouseId) {
                $query->where('status', 'active')
                    ->whereDate('start_date', '<=', $saleDate)
                    ->whereDate('end_date', '>=', $saleDate)
                    ->whereHas('allocations', fn ($allocation) => $allocation->where('warehouse_id', $warehouseId));
            })
            ->whereHas('allocations', fn ($allocation) => $allocation->where('warehouse_id', $warehouseId))
            ->get();

        $metrics = [];

        return $lines->filter(fn ($line) => ! empty($line->discountRules()))
            ->map(function ($line) use (&$metrics, $warehouseId, $product) {
            $target = $line->target;
            if (! isset($metrics[$target->id])) {
                $metrics[$target->id] = collect($this->achievement->calculate($target)['lines'])->keyBy('id');
            }
            $lineMetric = $metrics[$target->id]->get($line->id, []);
            $achieved = (float) ($lineMetric['achieved'] ?? 0);
            $targetQuantity = (float) $line->target_quantity;
            $accruedQuantity = min($achieved, $targetQuantity);

            $discounts = $line->discountRules();
            $calculation = $this->discountCalculation($product, $discounts);

            return [
                'target_id' => (int) $target->id,
                'target_line_id' => (int) $line->id,
                'target_name' => $target->target_name,
                'supplier' => optional($target->supplier)->name,
                'period_start' => optional($target->start_date)->format('Y-m-d'),
                'period_end' => optional($target->end_date)->format('Y-m-d'),
                'rate_per_unit' => (float) $calculation['total_discount'],
                'further_discounts' => $discounts,
                'target_quantity' => $targetQuantity,
                'branch_target_quantity' => round((float) $line->allocations->where('warehouse_id', $warehouseId)->sum('allocated_quantity'), 3),
                'achieved_quantity' => round($achieved, 3),
                'accrued_quantity' => round($accruedQuantity, 3),
                'remaining_eligible_quantity' => round(max($targetQuantity - $accruedQuantity, 0), 3),
                'accrued_amount' => (float) ($lineMetric['discount_earned'] ?? 0),
            ];
        })->values();
    }

    public function forPricing(Product $product, ?string $date = null): Collection
    {
        $pricingDate = $date ? Carbon::parse($date)->format('Y-m-d') : now()->format('Y-m-d');
        $categoryIds = collect([$product->category_id])->filter()
            ->merge(DB::table('category_product')->where('product_id', $product->id)->pluck('category_id'))
            ->map(fn ($id) => (int) $id)->unique()->values();
        $supplier = $this->supplierResolver->resolve($product, null, null);

        $lines = SupplierTargetLine::with('target.supplier:id,name')
            ->where(function ($query) {
                $query->whereNotNull('further_discounts')->orWhere('further_discount_per_unit', '>', 0);
            })
            ->where(function ($query) use ($product, $categoryIds) {
                $query->where('product_id', $product->id);
                if ($categoryIds->isNotEmpty()) {
                    $query->orWhereIn('category_id', $categoryIds);
                }
            })
            ->whereHas('target', function ($query) use ($pricingDate, $supplier) {
                $query->where('status', 'active')
                    ->whereDate('start_date', '<=', $pricingDate)
                    ->whereDate('end_date', '>=', $pricingDate)
                    ->when($supplier, fn ($targetQuery) => $targetQuery->where('supplier_id', $supplier->id));
            })
            ->orderBy('supplier_target_id')
            ->orderBy('id')
            ->get();

        return $lines->flatMap(function ($line) {
            return collect($line->discountRules())->values()->map(function ($discount, $index) use ($line) {
                return [
                    'label' => (string) ($discount['label'] ?? 'Further Discount'),
                    'type' => ($discount['type'] ?? null) === 'fixed' ? 'fixed' : 'percentage',
                    'value' => (float) ($discount['value'] ?? 0),
                    'target_id' => (int) $line->supplier_target_id,
                    'target_line_id' => (int) $line->id,
                    'target_name' => $line->target?->target_name,
                    'supplier' => $line->target?->supplier?->name,
                    'position' => $index + 1,
                ];
            });
        })->values();
    }

    private function discountCalculation(Product $product, array $discounts): array
    {
        $fixed = round((float) collect($discounts)->where('type', 'fixed')->sum('value'), 2);
        if (! collect($discounts)->contains(fn ($discount) => ($discount['type'] ?? null) === 'percentage')) {
            return ['total_discount' => $fixed, 'price' => 0, 'discounts' => $discounts];
        }
        $purchasePrice = (float) $this->pricing->effectivePurchasePrice($product)['price'];
        if ($purchasePrice <= 0) {
            return ['total_discount' => $fixed, 'price' => 0, 'discounts' => $discounts];
        }

        return $this->pricing->calculateFurtherDiscounts($purchasePrice, $discounts);
    }
}
