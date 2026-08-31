<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductSupplierResolver
{
    public function resolve(Product $product, ?int $variantId, ?int $warehouseId): ?Provider
    {
        $product->loadMissing('brand');
        $provider = null;

        if ($warehouseId && Schema::hasTable('product_batches')) {
            $batchProviderId = DB::table('product_batches')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'active')
                ->where('qty', '>', 0)
                ->whereNotNull('provider_id')
                ->whereNull('deleted_at')
                ->when(
                    $variantId,
                    fn ($query) => $query->where('product_variant_id', $variantId),
                    fn ($query) => $query->whereNull('product_variant_id')
                )
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->value('provider_id');

            if ($batchProviderId) {
                $provider = Provider::whereNull('deleted_at')->find($batchProviderId);
            }
        }

        $categoryIds = collect([$product->category_id])->filter();
        if (Schema::hasTable('category_product')) {
            $categoryIds = $categoryIds
                ->merge(DB::table('category_product')->where('product_id', $product->id)->pluck('category_id'))
                ->filter()
                ->unique()
                ->values();
        }

        if (! $provider && $categoryIds->isNotEmpty() && Schema::hasTable('category_provider')) {
            $categoryProviderIds = DB::table('category_provider')
                ->whereIn('category_id', $categoryIds)
                ->pluck('provider_id')
                ->unique();

            $categoryProviders = Provider::whereNull('deleted_at')
                ->whereIn('id', $categoryProviderIds)
                ->get(['id', 'name', 'tax_status']);

            if ($categoryProviders->count() === 1) {
                $provider = $categoryProviders->first();
            } elseif ($categoryProviders->count() > 1) {
                $provider = $this->providerMatchingBrand($categoryProviders, optional($product->brand)->name);
            }
        }

        if (! $provider && $warehouseId) {
            $purchaseProviderId = DB::table('purchase_details as purchase_detail')
                ->join('purchases as purchase', 'purchase.id', '=', 'purchase_detail.purchase_id')
                ->where('purchase_detail.product_id', $product->id)
                ->where('purchase.warehouse_id', $warehouseId)
                ->whereNotNull('purchase.provider_id')
                ->whereNull('purchase.deleted_at')
                ->when(
                    $variantId,
                    fn ($query) => $query->where('purchase_detail.product_variant_id', $variantId),
                    fn ($query) => $query->whereNull('purchase_detail.product_variant_id')
                )
                ->orderByDesc('purchase_detail.id')
                ->value('purchase.provider_id');

            if ($purchaseProviderId) {
                $provider = Provider::whereNull('deleted_at')->find($purchaseProviderId);
            }
        }

        if (! $provider && $product->brand && $product->brand->name) {
            $provider = $this->providerMatchingBrand(
                Provider::whereNull('deleted_at')->get(['id', 'name', 'tax_status']),
                $product->brand->name
            );
        }

        return $provider;
    }

    public function isNonGst(int $productId, ?int $variantId, ?int $warehouseId): bool
    {
        $product = Product::with('brand')->whereNull('deleted_at')->find($productId);
        if (! $product) {
            return false;
        }

        $provider = $this->resolve($product, $variantId, $warehouseId);

        return $provider && $provider->tax_status !== 'gst';
    }

    private function providerMatchingBrand($providers, ?string $brandName): ?Provider
    {
        $normalize = static fn ($value) => strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
        $brand = $normalize($brandName);

        if ($brand === '') {
            return null;
        }

        $exact = $providers->filter(fn ($provider) => $normalize($provider->name) === $brand)->values();
        if ($exact->count() === 1) {
            return $exact->first();
        }

        $prefix = $providers->filter(function ($provider) use ($normalize, $brand) {
            $name = $normalize($provider->name);
            return $name !== '' && (str_starts_with($name, $brand) || str_starts_with($brand, $name));
        })->values();

        return $prefix->count() === 1 ? $prefix->first() : null;
    }
}
