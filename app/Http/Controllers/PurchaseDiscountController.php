<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePurchaseDiscountRequest;
use App\Models\PurchaseDiscount;
use Illuminate\Http\Request;

class PurchaseDiscountController extends Controller
{
    public function index(Request $request)
    {
        $this->permit($request, 'purchase_discounts.view');

        $query = PurchaseDiscount::query()->withCount('transactionUsages');
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$term}%");
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $sortField = (string) $request->input('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType', 'desc'));
        if (! in_array($sortField, ['id', 'name', 'category', 'description', 'status', 'created_at'], true)) {
            $sortField = 'id';
        }
        if (! in_array($sortType, ['asc', 'desc'], true)) {
            $sortType = 'desc';
        }
        $query->orderBy($sortField, $sortType)->orderBy('id', 'desc');

        $limit = (int) $request->input('limit', 10);
        if ($limit === -1) {
            $discounts = $query->get();
            $totalRows = $discounts->count();
        } else {
            $limit = max(1, min($limit, 100));
            $page = $query->paginate($limit);
            $discounts = $page->getCollection();
            $totalRows = $page->total();
        }

        return response()->json([
            'discounts' => $discounts->map(fn (PurchaseDiscount $discount) => $this->serialize($discount))->values(),
            'totalRows' => $totalRows,
            'categories' => $this->categories(),
        ]);
    }

    public function store(SavePurchaseDiscountRequest $request)
    {
        $this->permit($request, 'purchase_discounts.create');
        $discount = PurchaseDiscount::create($request->validated());

        return response()->json([
            'message' => 'Purchase discount created successfully.',
            'discount' => $this->serialize($discount->loadCount('transactionUsages')),
        ], 201);
    }

    public function show(Request $request, PurchaseDiscount $purchaseDiscount)
    {
        $this->permit($request, 'purchase_discounts.view');

        return response()->json([
            'discount' => $this->serialize($purchaseDiscount->loadCount('transactionUsages')),
        ]);
    }

    public function update(SavePurchaseDiscountRequest $request, PurchaseDiscount $purchaseDiscount)
    {
        $this->permit($request, 'purchase_discounts.update');
        $purchaseDiscount->update($request->validated());

        return response()->json([
            'message' => 'Purchase discount updated successfully.',
            'discount' => $this->serialize($purchaseDiscount->fresh()->loadCount('transactionUsages')),
        ]);
    }

    public function destroy(Request $request, PurchaseDiscount $purchaseDiscount)
    {
        $this->permit($request, 'purchase_discounts.delete');
        if ($purchaseDiscount->transactionUsages()->exists()) {
            return response()->json([
                'message' => 'This discount is already used by a purchase transaction and cannot be deleted. Set it to inactive instead.',
            ], 422);
        }

        $purchaseDiscount->delete();

        return response()->json([
            'success' => true,
            'message' => 'Purchase discount deleted successfully.',
        ]);
    }

    private function serialize(PurchaseDiscount $discount): array
    {
        return [
            'id' => $discount->id,
            'name' => $discount->name,
            'category' => $discount->category,
            'category_label' => $discount->category_label,
            'description' => $discount->description,
            'status' => $discount->status,
            'status_label' => ucfirst($discount->status),
            'created_at' => optional($discount->created_at)->toDateTimeString(),
            'updated_at' => optional($discount->updated_at)->toDateTimeString(),
            'is_used' => (int) ($discount->transaction_usages_count ?? 0) > 0,
        ];
    }

    private function categories(): array
    {
        return [
            ['value' => 'on_invoice', 'text' => 'On Invoice Discount'],
            ['value' => 'further_discount', 'text' => 'Further Discount'],
        ];
    }

    private function permit(Request $request, string $permission): void
    {
        $user = $request->user('api');
        abort_unless(
            $user && ($user->isSuperAdmin() || $user->effectivePermissionNames()->contains($permission)),
            403,
            'You do not have permission to manage purchase discounts.'
        );
    }
}
