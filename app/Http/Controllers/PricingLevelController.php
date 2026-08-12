<?php

namespace App\Http\Controllers;

use App\Models\PricingLevel;
use App\Models\PricingLevelDetail;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PricingLevelController extends Controller
{
    private const PRICE_FIELDS = [
        'company_rb_price',
        'mrp_price',
        'cost',
        'fix_price',
        'price',
        'wholesale_price',
        'min_price',
    ];

    public function index(Request $request)
    {
        $this->authorizePricingLevel($request);

        $user = $request->user('api');
        $requestedLimit = (int) $request->input('limit', 10);
        $showAll = $requestedLimit === -1;
        $limit = $showAll ? null : max(1, min($requestedLimit, 100));
        $sortField = (string) $request->input('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType', 'desc'));
        $allowedSorts = ['id', 'date', 'brand_id', 'category_id', 'total_products', 'created_at'];
        if (! in_array($sortField, $allowedSorts, true)) {
            $sortField = 'id';
        }
        if (! in_array($sortType, ['asc', 'desc'], true)) {
            $sortType = 'desc';
        }

        $query = PricingLevel::query()
            ->with(['brand:id,name', 'category:id,name', 'user:id,username'])
            ->whereNull('deleted_at');

        if (! $user->hasRecordView()) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->input('date'));
        }
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->integer('brand_id'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($subQuery) use ($search) {
                $subQuery->whereHas('brand', fn ($brand) => $brand->where('name', 'LIKE', "%{$search}%"))
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'LIKE', "%{$search}%"))
                    ->orWhere('total_products', $search);
            });
        }

        $totalRows = (clone $query)->count();
        if ($showAll) {
            $limit = max($totalRows, 1);
        }

        $entries = $query->orderBy($sortField, $sortType)
            ->paginate($limit);

        $rows = $entries->getCollection()->map(fn (PricingLevel $entry) => [
            'id' => $entry->id,
            'date' => $entry->date ? $entry->date->toDateString() : null,
            'created_at' => $entry->created_at?->toIso8601String(),
            'brand' => $entry->brand?->name ?: 'N/D',
            'category' => $entry->category?->name ?: 'N/D',
            'brand_id' => $entry->brand_id,
            'category_id' => $entry->category_id,
            'total_products' => $entry->total_products,
            'created_by' => $entry->user?->username ?: 'N/D',
        ])->values();

        return response()->json([
            'pricing_levels' => $rows,
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePricingLevel($request);
        $validated = $this->validateEntry($request);
        $prepared = $this->prepareDetails($validated, $request->user('api'));

        $entry = DB::transaction(function () use ($request, $validated, $prepared) {
            $entry = PricingLevel::create([
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'brand_id' => $validated['brand_id'],
                'category_id' => $validated['category_id'],
                'user_id' => $request->user('api')->id,
                'total_products' => collect($prepared)->pluck('product_id')->unique()->count(),
            ]);

            $this->applyAndStoreDetails($entry, $prepared);

            return $entry;
        });

        return response()->json([
            'success' => true,
            'id' => $entry->id,
            'message' => 'Pricing level entry created successfully.',
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $this->authorizePricingLevel($request);
        $entry = $this->findVisibleEntry($request, $id);
        $entry->load(['brand:id,name', 'category:id,name']);

        $details = PricingLevelDetail::query()
            ->with(['product.brand', 'product.category', 'product.categories', 'variant'])
            ->whereHas('product', fn ($productQuery) => $productQuery->visibleTo($request->user('api')))
            ->where('pricing_level_id', $entry->id)
            ->orderBy('id')
            ->get();

        $products = $details->groupBy('product_id')->map(function ($productDetails) {
            $first = $productDetails->first();
            $product = $first->product;
            if (! $product) {
                return null;
            }

            $payload = [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'product_type' => $product->type,
                'created_at' => $product->created_at?->toIso8601String(),
                'brand_id' => $product->brand_id,
                'brand' => $product->brand?->name ?: 'N/D',
                'category_id' => $product->category_id,
                'category' => $product->category?->name,
                'categories_display' => $product->categories->isNotEmpty()
                    ? $product->categories->pluck('name')->filter()->unique()->values()->implode("\n")
                    : $product->category?->name,
                'pricing_variants' => [],
            ];

            if ($product->type === 'is_variant') {
                $payload['pricing_variants'] = $productDetails->map(function ($detail) {
                    $variant = $detail->variant;
                    $row = [
                        'id' => $detail->product_variant_id,
                        'name' => $variant?->name ?: 'Deleted variant',
                        'code' => $variant?->code ?: '',
                    ];
                    foreach (self::PRICE_FIELDS as $field) {
                        $row[$field] = (float) $detail->{$field};
                    }

                    return $row;
                })->values()->all();
            } else {
                foreach (self::PRICE_FIELDS as $field) {
                    $payload[$field] = (float) $first->{$field};
                }
            }

            return $payload;
        })->filter()->values();

        return response()->json([
            'entry' => [
                'id' => $entry->id,
                'date' => $entry->date?->toDateString(),
                'brand_id' => $entry->brand_id,
                'brand' => $entry->brand?->name ?: 'N/D',
                'category_id' => $entry->category_id,
                'category' => $entry->category?->name ?: 'N/D',
                'total_products' => $entry->total_products,
            ],
            'products' => $products,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizePricingLevel($request);
        $entry = $this->findVisibleEntry($request, $id);
        $validated = $this->validateEntry($request);

        if ((int) $entry->brand_id !== (int) $validated['brand_id'] ||
            (int) $entry->category_id !== (int) $validated['category_id']) {
            throw ValidationException::withMessages([
                'entry' => ['The brand and category of an existing pricing level entry cannot be changed.'],
            ]);
        }

        $prepared = $this->prepareDetails($validated, $request->user('api'));
        $existingKeys = $entry->details()->get()->map(fn ($detail) => $this->detailKey(
            $detail->product_id,
            $detail->product_variant_id
        ))->sort()->values()->all();
        $requestedKeys = collect($prepared)->map(fn ($detail) => $this->detailKey(
            $detail['product_id'],
            $detail['product_variant_id']
        ))->sort()->values()->all();

        if ($existingKeys !== $requestedKeys) {
            throw ValidationException::withMessages([
                'details' => ['Editing must retain the same products and variants as the original pricing level entry.'],
            ]);
        }

        DB::transaction(function () use ($entry, $prepared) {
            $entry->details()->delete();
            $entry->update([
                'total_products' => collect($prepared)->pluck('product_id')->unique()->count(),
            ]);
            $this->applyAndStoreDetails($entry, $prepared);
        });

        return response()->json([
            'success' => true,
            'message' => 'Pricing level entry updated successfully.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizePricingLevel($request);
        $entry = $this->findVisibleEntry($request, $id);
        $entry->delete();

        return response()->json(['success' => true]);
    }

    private function validateEntry(Request $request): array
    {
        $rules = [
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'details.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ];
        foreach (self::PRICE_FIELDS as $field) {
            $rules["details.*.{$field}"] = ['required', 'numeric', 'min:0'];
        }

        return $request->validate($rules);
    }

    private function prepareDetails(array $validated, $user): array
    {
        $productIds = collect($validated['details'])->pluck('product_id')->unique()->values();
        $products = Product::query()
            ->visibleTo($user)
            ->whereIn('id', $productIds)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages(['details' => ['One or more products are unavailable.']]);
        }

        $seen = [];
        $prepared = [];
        foreach ($validated['details'] as $detail) {
            $product = $products->get((int) $detail['product_id']);
            if ((int) $product->brand_id !== (int) $validated['brand_id'] ||
                (int) $product->category_id !== (int) $validated['category_id']) {
                throw ValidationException::withMessages([
                    'details' => ['Every product must belong to the selected brand and category.'],
                ]);
            }

            $variantId = $detail['product_variant_id'] ?? null;
            if ($product->type === 'is_variant') {
                if (! $variantId || ! ProductVariant::where('id', $variantId)
                    ->where('product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'details' => ["A valid variant is required for {$product->name}."],
                    ]);
                }
            } elseif ($variantId) {
                throw ValidationException::withMessages([
                    'details' => ["{$product->name} does not accept variant pricing."],
                ]);
            }

            $key = $this->detailKey($product->id, $variantId);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['details' => ['Duplicate product pricing rows are not allowed.']]);
            }
            $seen[$key] = true;

            $row = [
                'product_id' => $product->id,
                'product_variant_id' => $variantId ? (int) $variantId : null,
            ];
            foreach (self::PRICE_FIELDS as $field) {
                $row[$field] = (float) $detail[$field];
            }
            $prepared[] = $row;
        }

        return $prepared;
    }

    private function applyAndStoreDetails(PricingLevel $entry, array $details): void
    {
        foreach ($details as $detail) {
            $prices = collect($detail)->only(self::PRICE_FIELDS)->all();
            if ($detail['product_variant_id']) {
                ProductVariant::where('id', $detail['product_variant_id'])->update([
                    'company_rb_price' => $prices['company_rb_price'],
                    'mrp_price' => $prices['mrp_price'],
                    'cost' => $prices['cost'],
                    'fix_price' => $prices['fix_price'],
                    'price' => $prices['price'],
                    'wholesale' => $prices['wholesale_price'],
                    'min_price' => $prices['min_price'],
                ]);
            } else {
                Product::where('id', $detail['product_id'])->update($prices);
            }

            PricingLevelDetail::create(array_merge($detail, [
                'pricing_level_id' => $entry->id,
            ]));
        }
    }

    private function findVisibleEntry(Request $request, $id): PricingLevel
    {
        $user = $request->user('api');
        $query = PricingLevel::query()->whereNull('deleted_at');
        if (! $user->hasRecordView()) {
            $query->where('user_id', $user->id);
        }

        return $query->findOrFail($id);
    }

    private function authorizePricingLevel(Request $request): void
    {
        $user = $request->user('api');
        abort_unless(
            $user && $user->effectivePermissionNames()->contains('pricing_level'),
            403,
            'Permission denied: pricing_level'
        );
    }

    private function detailKey($productId, $variantId): string
    {
        return (int) $productId.':'.($variantId ? (int) $variantId : 0);
    }
}
