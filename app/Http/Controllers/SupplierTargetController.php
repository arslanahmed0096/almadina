<?php

namespace App\Http\Controllers;

use App\Exports\SupplierTargetsExport;
use App\Http\Requests\SaveSupplierTargetAllocationsRequest;
use App\Http\Requests\SaveSupplierTargetDetailsRequest;
use App\Http\Requests\SaveSupplierTargetLinesRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Provider;
use App\Models\SupplierTarget;
use App\Models\SupplierTargetDiscountPosting;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Targets\TargetAccessService;
use App\Services\Targets\TargetAchievementService;
use App\Services\Targets\TargetActivationService;
use App\Services\Targets\TargetAllocationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class SupplierTargetController extends Controller
{
    public function __construct(
        private TargetAchievementService $achievement,
        private TargetAccessService $access,
        private TargetActivationService $activation,
        private TargetAllocationService $allocation
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SupplierTarget::class);
        $warehouseIds = $this->access->warehouseIds($request->user());
        $userId = $request->user()->id;
        $canSeeAllDrafts = $request->user()->isSuperAdmin() || (bool) $request->user()->is_all_warehouses
            || $request->user()->effectivePermissionNames()->contains('targets.view_all_warehouses');
        $query = SupplierTarget::with(['supplier:id,name', 'lines.product:id,name', 'lines.category:id,name',
            'allocations.warehouse:id,name', 'creator:id,firstname,lastname'])
            ->when(! $canSeeAllDrafts, function ($query) use ($warehouseIds, $userId) {
                $query->where(function ($scope) use ($warehouseIds, $userId) {
                    $scope->whereHas('allocations', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
                        ->orWhere(fn ($q) => $q->where('status', 'draft')->where('created_by', $userId));
                });
            })
            ->when($request->supplier_id, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->period_type, fn ($q, $v) => $q->where('period_type', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->warehouse_id, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('warehouse_id', $v)))
            ->when($request->year, fn ($q, $v) => $q->whereYear('start_date', $v))
            ->when($request->month, fn ($q, $v) => $q->whereMonth('start_date', $v))
            ->when($request->search, fn ($q, $v) => $q->where('target_name', 'like', '%'.$v.'%'))
            ->latest('id');
        $targets = $query->paginate(min(100, max(5, (int) $request->input('limit', 15))))->withQueryString();
        $metricScope = $request->warehouse_id ? [(int) $request->warehouse_id] : $warehouseIds->all();
        $targets->setCollection($targets->getCollection()->map(fn ($target) => $this->payload(
            $target, $this->achievement->calculate($target, $metricScope)
        )));

        return response()->json($targets);
    }

    public function options(Request $request)
    {
        abort_unless(
            $request->user()->can('viewAny', SupplierTarget::class)
            || $request->user()->can('create', SupplierTarget::class),
            403
        );
        $warehouseIds = $this->access->warehouseIds($request->user());
        $supplierId = (int) $request->input('supplier_id');
        $categories = Category::whereNull('deleted_at')
            ->when($supplierId, fn ($q) => $q->whereHas('providers', fn ($p) => $p->where('providers.id', $supplierId)))
            ->orderBy('name')->get(['id', 'name']);
        $products = Product::visibleTo($request->user())->whereNull('deleted_at')
            ->when($supplierId, function ($query) use ($supplierId) {
                $query->where(function ($q) use ($supplierId) {
                    $q->whereHas('category.providers', fn ($p) => $p->where('providers.id', $supplierId))
                        ->orWhereHas('categories.providers', fn ($p) => $p->where('providers.id', $supplierId))
                        ->orWhereIn('products.id', DB::table('purchase_details')->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
                            ->where('purchases.provider_id', $supplierId)->whereNull('purchases.deleted_at')->select('purchase_details.product_id'))
                        ->orWhereIn('products.id', DB::table('product_batches')->where('provider_id', $supplierId)->whereNull('deleted_at')->select('product_id'));
                });
            })->orderBy('name')->get(['id', 'name', 'category_id', 'unit_sale_id']);

        return response()->json([
            'suppliers' => Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'products' => $products,
            'categories' => $categories,
            'warehouses' => Warehouse::whereIn('id', $warehouseIds)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'ShortName']),
            'permissions' => $request->user()->effectivePermissionNames()->filter(fn ($name) => str_starts_with($name, 'targets.'))->values(),
        ]);
    }

    public function store(SaveSupplierTargetDetailsRequest $request)
    {
        $this->authorize('create', SupplierTarget::class);
        $target = DB::transaction(function () use ($request) {
            $target = SupplierTarget::create($request->validated() + [
                'measurement_type' => 'quantity', 'status' => 'draft',
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $this->history($target, 'created', null, $target->only(['supplier_id', 'target_name', 'period_type', 'start_date', 'end_date']));

            return $target;
        });

        return response()->json(['message' => 'Target draft saved.', 'target' => $this->payload($target->load(['supplier', 'lines', 'allocations']))], 201);
    }

    public function show(Request $request, SupplierTarget $target)
    {
        $this->authorize('view', $target);
        $this->ensureVisible($request, $target);
        $target->load(['supplier:id,name', 'lines.product:id,name,unit_sale_id', 'lines.category:id,name',
            'lines.unit:id,name,ShortName', 'lines.allocations.warehouse:id,name', 'lines.discountPostings.creator:id,firstname,lastname',
            'allocations.warehouse:id,name', 'histories.user:id,firstname,lastname', 'creator:id,firstname,lastname']);

        return response()->json(['target' => $this->payload($target, $this->achievement->calculate($target, $this->access->warehouseIds($request->user())->all()))]);
    }

    public function update(SaveSupplierTargetDetailsRequest $request, SupplierTarget $target)
    {
        $this->authorize('update', $target);
        if ($target->lines()->exists()) {
            $original = $target->only(['supplier_id', 'period_type', 'start_date', 'end_date']);
            $target->forceFill($request->safe()->only(['supplier_id', 'period_type', 'start_date', 'end_date']));
            $existingLines = $target->lines()->get()->map(fn ($line) => [
                'type' => $line->product_id ? 'product' : 'category',
                'targetable_id' => $line->product_id ?: $line->category_id,
            ]);
            $this->validateSupplierCoverage($target, $existingLines);
            if ($target->status === 'active') {
                $this->validateActiveConflict($target, $existingLines);
            }
            $target->forceFill($original);
        }
        $old = $target->only(['supplier_id', 'target_name', 'period_type', 'start_date', 'end_date', 'description']);
        DB::transaction(function () use ($request, $target, $old) {
            $target->update($request->validated() + ['measurement_type' => 'quantity', 'updated_by' => $request->user()->id]);
            $this->history($target, 'details_changed', $old, $target->only(array_keys($old)));
        });

        return response()->json(['message' => 'Target details saved.', 'target' => $this->payload($target->fresh()->load(['supplier', 'lines', 'allocations']))]);
    }

    public function saveLines(SaveSupplierTargetLinesRequest $request, SupplierTarget $target)
    {
        $this->authorize('update', $target);
        if ($target->discountPostings()->exists()) {
            throw ValidationException::withMessages(['lines' => ['Target lines cannot be replaced after a further-discount posting exists.']]);
        }
        $lines = collect($request->validated('lines'));
        $this->validateSupplierCoverage($target, $lines);
        $this->validateActiveConflict($target, $lines);
        DB::transaction(function () use ($request, $target, $lines) {
            $old = $target->lines()->get()->toArray();
            $oldTotal = (float) $target->lines()->sum('target_quantity');
            $target->lines()->delete();
            foreach ($lines as $line) {
                $discounts = collect($line['further_discounts'] ?? [])->map(fn ($discount) => [
                    'label' => trim((string) $discount['label']),
                    'type' => $discount['type'],
                    'value' => (float) $discount['value'],
                ])->values()->all();
                $legacyFixedRate = collect($discounts)->where('type', 'fixed')->sum('value');
                $target->lines()->create([
                    'product_id' => $line['type'] === 'product' ? $line['targetable_id'] : null,
                    'category_id' => $line['type'] === 'category' ? $line['targetable_id'] : null,
                    'unit_id' => $line['unit_id'] ?? null,
                    'target_quantity' => $line['target_quantity'],
                    'further_discount_per_unit' => $legacyFixedRate,
                    'further_discounts' => $discounts,
                ]);
            }
            $newTotal = (float) $target->lines()->sum('target_quantity');
            $requiresReview = $target->allocations()->exists();
            $target->update([
                'allocation_requires_review' => $requiresReview,
                'status' => $requiresReview && $target->status === 'active' ? 'draft' : $target->status,
                'updated_by' => $request->user()->id,
            ]);
            $this->history($target, 'lines_changed', $old, $target->lines()->get()->toArray());
        });

        return response()->json(['message' => 'Product targets saved.', 'target' => $this->payload($target->fresh()->load(['supplier', 'lines.product', 'lines.category', 'allocations.warehouse', 'lineAllocations.warehouse']))]);
    }

    public function saveAllocations(SaveSupplierTargetAllocationsRequest $request, SupplierTarget $target)
    {
        $this->authorize('update', $target);
        $allowed = $this->access->warehouseIds($request->user());
        $allocations = collect($request->validated('allocations', []))->filter(fn ($row) => (float) $row['allocated_quantity'] > 0);
        $lineAllocations = collect($request->validated('line_allocations', []))->filter(fn ($row) => (float) $row['allocated_quantity'] > 0);
        $warehouseIds = $lineAllocations->isNotEmpty() ? $lineAllocations->pluck('warehouse_id') : $allocations->pluck('warehouse_id');
        if ($warehouseIds->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['allocations' => ['You cannot allocate a target to a warehouse outside your scope.']]);
        }
        if ($lineAllocations->isEmpty() && $allocations->isNotEmpty()) {
            $lineAllocations = $this->expandLegacyAllocations($target, $allocations);
        }
        if ($lineAllocations->isNotEmpty()) {
            $targetLines = $target->lines()->pluck('target_quantity', 'id');
            if ($lineAllocations->pluck('supplier_target_line_id')->diff($targetLines->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['line_allocations' => ['An allocation contains a line that does not belong to this target.']]);
            }
            foreach ($targetLines as $lineId => $quantity) {
                $allocated = (float) $lineAllocations->where('supplier_target_line_id', $lineId)->sum('allocated_quantity');
                if (abs((float) $quantity - $allocated) > 0.0001) {
                    throw ValidationException::withMessages(['line_allocations' => ['Every product/category quantity must be fully distributed to branches.']]);
                }
            }
            $allocations = $lineAllocations->groupBy('warehouse_id')->map(fn ($rows, $warehouseId) => [
                'warehouse_id' => (int) $warehouseId,
                'allocated_quantity' => round((float) $rows->sum('allocated_quantity'), 3),
            ])->values();
        }
        DB::transaction(function () use ($request, $target, $allocations, $lineAllocations) {
            $old = $target->allocations()->get()->toArray();
            $target->lineAllocations()->delete();
            $target->allocations()->delete();
            foreach ($allocations as $row) {
                $target->allocations()->create($row);
            }
            foreach ($lineAllocations as $row) {
                $target->lineAllocations()->create($row);
            }
            $total = (float) $target->lines()->sum('target_quantity');
            $allocated = (float) $target->allocations()->sum('allocated_quantity');
            $requiresReview = abs($total - $allocated) > 0.0001;
            $target->update([
                'allocation_requires_review' => $requiresReview,
                'status' => $requiresReview && $target->status === 'active' ? 'draft' : $target->status,
                'updated_by' => $request->user()->id,
            ]);
            $this->history($target, 'allocation_changed', $old, [
                'warehouses' => $target->allocations()->get()->toArray(),
                'lines' => $target->lineAllocations()->get()->toArray(),
            ]);
        });

        return response()->json(['message' => 'Branch/category allocation saved.', 'target' => $this->payload($target->fresh()->load(['supplier', 'lines', 'allocations.warehouse', 'lineAllocations.warehouse']))]);
    }

    public function allocationSuggestion(Request $request, SupplierTarget $target)
    {
        $this->authorize('update', $target);
        $this->ensureVisible($request, $target);
        $suggestion = $this->allocation->byHistoricalSales($target, $this->access->warehouseIds($request->user()));

        return response()->json([
            'message' => 'Sales-weighted branch allocation calculated.',
            'suggestion' => $suggestion,
        ]);
    }

    public function activate(Request $request, SupplierTarget $target)
    {
        $this->authorize('activate', $target);
        $this->activation->activate($target, $request->user()->id, function ($locked) {
            $this->validateActiveConflict($locked, $locked->lines()->get()->map(fn ($line) => [
                'type' => $line->product_id ? 'product' : 'category',
                'targetable_id' => $line->product_id ?: $line->category_id,
            ]));
        });

        return response()->json(['message' => 'Target activated successfully.']);
    }

    public function cancel(Request $request, SupplierTarget $target)
    {
        $this->authorize('cancel', $target);
        $old = $target->status;
        $target->update(['status' => 'cancelled', 'updated_by' => $request->user()->id]);
        $this->history($target, 'cancelled', ['status' => $old], ['status' => 'cancelled']);

        return response()->json(['message' => 'Target cancelled.']);
    }

    public function complete(Request $request, SupplierTarget $target)
    {
        $this->authorize('complete', $target);
        $target->update(['status' => 'completed', 'updated_by' => $request->user()->id]);
        $this->history($target, 'completed', ['status' => 'active'], ['status' => 'completed']);

        return response()->json(['message' => 'Target completed.']);
    }

    public function destroy(SupplierTarget $target)
    {
        $this->authorize('delete', $target);
        $target->delete();

        return response()->json(['message' => 'Draft target deleted.']);
    }

    public function postDiscount(Request $request, SupplierTarget $target)
    {
        $this->authorize('postDiscount', $target);
        $validated = $request->validate([
            'supplier_target_line_id' => ['required', 'integer', 'exists:supplier_target_lines,id'],
            'posting_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999999999'],
            'reference' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! $target->lines()->whereKey($validated['supplier_target_line_id'])->exists()) {
            throw ValidationException::withMessages(['supplier_target_line_id' => ['The selected category does not belong to this target.']]);
        }
        $posting = $target->discountPostings()->create($validated + ['created_by' => $request->user()->id]);
        $this->history($target, 'discount_posted', null, $posting->toArray());

        return response()->json(['message' => 'Further-discount posting recorded.', 'posting' => $posting], 201);
    }

    public function deleteDiscount(Request $request, SupplierTarget $target, SupplierTargetDiscountPosting $posting)
    {
        $this->authorize('postDiscount', $target);
        abort_unless((int) $posting->supplier_target_id === (int) $target->id, 404);
        $old = $posting->toArray();
        $posting->delete();
        $this->history($target, 'discount_posting_deleted', $old, null);

        return response()->json(['message' => 'Further-discount posting deleted.']);
    }

    public function discountLedger(Request $request)
    {
        $this->authorize('discountLedger', SupplierTarget::class);
        $targets = SupplierTarget::with([
            'supplier:id,name', 'lines.product:id,name', 'lines.category:id,name',
            'lines.allocations.warehouse:id,name', 'lines.discountPostings.creator:id,firstname,lastname',
            'allocations.warehouse:id,name',
        ])->whereIn('status', ['active', 'completed'])
            ->when($request->supplier_id, fn ($q, $value) => $q->where('supplier_id', $value))
            ->when($request->year, fn ($q, $value) => $q->whereYear('start_date', $value))
            ->when($request->category_id, fn ($q, $value) => $q->whereHas('lines', fn ($line) => $line->where('category_id', $value)))
            ->orderByDesc('start_date')->get();

        $rows = $targets->flatMap(function ($target) {
            $metrics = $this->achievement->calculate($target);
            $metricLines = collect($metrics['lines'])->keyBy('id');

            return $target->lines->map(function ($line) use ($target, $metricLines) {
                $metric = $metricLines->get($line->id, []);

                return [
                    'target_id' => $target->id, 'target_name' => $target->target_name,
                    'supplier_id' => $target->supplier_id, 'supplier' => optional($target->supplier)->name,
                    'line_id' => $line->id, 'type' => $line->product_id ? 'product' : 'category',
                    'category_id' => $line->category_id,
                    'category' => $line->product_id ? optional($line->product)->name : optional($line->category)->name,
                    'start_date' => optional($target->start_date)->format('Y-m-d'),
                    'end_date' => optional($target->end_date)->format('Y-m-d'),
                    'target_quantity' => (float) $line->target_quantity,
                    'achieved_quantity' => (float) ($metric['achieved'] ?? 0),
                    'rate' => (float) ($metric['discount_rate_per_unit'] ?? $line->further_discount_per_unit),
                    'further_discounts' => $line->discountRules(),
                    'earned' => (float) ($metric['discount_earned'] ?? 0),
                    'posted' => (float) ($metric['discount_posted'] ?? 0),
                    'balance' => (float) ($metric['discount_balance'] ?? 0),
                    'eligible' => (bool) ($metric['discount_eligible'] ?? false),
                ];
            });
        })->values();

        return response()->json([
            'summary' => [
                'earned' => round((float) $rows->sum('earned'), 2),
                'posted' => round((float) $rows->sum('posted'), 2),
                'balance' => round((float) $rows->sum('balance'), 2),
            ],
            'rows' => $rows,
        ]);
    }

    public function dashboard(Request $request)
    {
        $this->authorize('viewAny', SupplierTarget::class);
        $warehouseIds = $this->access->warehouseIds($request->user());
        $query = SupplierTarget::with(['supplier:id,name', 'lines.product:id,name', 'lines.category:id,name', 'allocations.warehouse:id,name'])
            ->whereIn('status', ['active', 'completed'])
            ->whereHas('allocations', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->when($request->target_id, fn ($q, $v) => $q->whereKey($v))
            ->when($request->supplier_id, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->period_type, fn ($q, $v) => $q->where('period_type', $v))
            ->when($request->year, fn ($q, $v) => $q->whereYear('start_date', $v))
            ->when($request->month, fn ($q, $v) => $q->whereMonth('start_date', $v))
            ->when($request->warehouse_id, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('warehouse_id', $v)))
            ->when($request->product_id, fn ($q, $v) => $q->whereHas('lines', fn ($line) => $line->where('product_id', $v)))
            ->when($request->category_id, fn ($q, $v) => $q->whereHas('lines', fn ($line) => $line->where('category_id', $v)))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v));
        $targets = $query->get();
        $metricScope = $request->warehouse_id ? [(int) $request->warehouse_id] : $warehouseIds->all();
        $items = $targets->map(fn ($target) => ['target' => $target, 'metrics' => $this->achievement->calculate($target, $metricScope)]);

        return response()->json($this->dashboardPayload($items));
    }

    public function report(Request $request)
    {
        $this->authorize('reports', SupplierTarget::class);

        return $this->dashboard($request);
    }

    public function reportPrint(Request $request)
    {
        $this->authorize('reports', SupplierTarget::class);
        $data = $this->dashboard($request)->getData(true);
        $filters = $request->only(['supplier_id', 'year', 'month', 'period_type', 'warehouse_id', 'product_id', 'category_id', 'status']);

        return view('reports.supplier_targets', compact('data', 'filters'));
    }

    public function reportPdf(Request $request)
    {
        $this->authorize('export', SupplierTarget::class);
        $data = $this->dashboard($request)->getData(true);
        $filters = $request->only(['supplier_id', 'year', 'month', 'period_type', 'warehouse_id', 'product_id', 'category_id', 'status']);

        return Pdf::loadView('reports.supplier_targets', compact('data', 'filters'))->setPaper('a4', 'landscape')
            ->download('supplier-target-report-'.now()->format('Y-m-d-His').'.pdf');
    }

    public function reportExcel(Request $request)
    {
        $this->authorize('export', SupplierTarget::class);
        $data = $this->dashboard($request)->getData(true);

        return Excel::download(new SupplierTargetsExport($data), 'supplier-target-report-'.now()->format('Y-m-d-His').'.xlsx');
    }

    private function validateSupplierCoverage(SupplierTarget $target, $lines): void
    {
        $supplierId = (int) $target->supplier_id;
        $categoryIds = $lines->where('type', 'category')->pluck('targetable_id')->map(fn ($id) => (int) $id);
        $invalidCategories = $categoryIds->reject(fn ($id) => DB::table('category_provider')
            ->where('provider_id', $supplierId)->where('category_id', $id)->exists());
        if ($invalidCategories->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => ['A selected category is not assigned to this supplier.']]);
        }
        $invalidProducts = $lines->where('type', 'product')->pluck('targetable_id')->map(fn ($id) => (int) $id)
            ->reject(function ($productId) use ($supplierId) {
                $categoryMatch = DB::table('products as p')->join('category_provider as cp', 'cp.category_id', '=', 'p.category_id')
                    ->where('p.id', $productId)->where('cp.provider_id', $supplierId)->exists();
                $pivotMatch = DB::table('category_product as pc')->join('category_provider as cp', 'cp.category_id', '=', 'pc.category_id')
                    ->where('pc.product_id', $productId)->where('cp.provider_id', $supplierId)->exists();
                $purchaseMatch = DB::table('purchase_details as pd')->join('purchases as p', 'p.id', '=', 'pd.purchase_id')
                    ->where('pd.product_id', $productId)->where('p.provider_id', $supplierId)->whereNull('p.deleted_at')->exists();
                $batchMatch = DB::table('product_batches')->where('product_id', $productId)->where('provider_id', $supplierId)->whereNull('deleted_at')->exists();

                return $categoryMatch || $pivotMatch || $purchaseMatch || $batchMatch;
            });
        if ($invalidProducts->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => ['A selected product cannot be attributed to this supplier.']]);
        }
    }

    private function expandLegacyAllocations(SupplierTarget $target, $allocations)
    {
        $rows = collect();
        $allocationTotal = (float) $allocations->sum('allocated_quantity');
        if ($allocationTotal <= 0) {
            return $rows;
        }
        foreach ($target->lines()->get() as $line) {
            $remaining = (int) round((float) $line->target_quantity * 1000);
            foreach ($allocations->values() as $index => $allocation) {
                $minor = $index === $allocations->count() - 1
                    ? $remaining
                    : (int) round((float) $line->target_quantity * 1000 * ((float) $allocation['allocated_quantity'] / $allocationTotal));
                $minor = max(0, min($minor, $remaining));
                if ($minor > 0) {
                    $rows->push([
                        'supplier_target_line_id' => $line->id,
                        'warehouse_id' => $allocation['warehouse_id'],
                        'allocated_quantity' => $minor / 1000,
                    ]);
                }
                $remaining -= $minor;
            }
        }

        return $rows;
    }

    private function validateActiveConflict(SupplierTarget $target, $lines): void
    {
        $selected = $this->productIdsForLines($lines);
        if ($selected->isEmpty()) {
            return;
        }
        $conflicts = SupplierTarget::with('lines')->where('supplier_id', $target->supplier_id)
            ->where('status', 'active')->whereKeyNot($target->id)
            ->whereDate('start_date', '<=', $target->end_date)->whereDate('end_date', '>=', $target->start_date)->get();
        foreach ($conflicts as $conflict) {
            $other = $conflict->lines->map(fn ($line) => [
                'type' => $line->product_id ? 'product' : 'category',
                'targetable_id' => $line->product_id ?: $line->category_id,
            ]);
            if ($selected->intersect($this->productIdsForLines($other))->isNotEmpty()) {
                throw ValidationException::withMessages(['lines' => ['An active target already covers one or more selected products during this date range.']]);
            }
        }
    }

    private function productIdsForLines($lines)
    {
        $lines = collect($lines);
        $products = $lines->where('type', 'product')->pluck('targetable_id')->map(fn ($id) => (int) $id);
        $categories = $lines->where('type', 'category')->pluck('targetable_id')->map(fn ($id) => (int) $id);
        if ($categories->isNotEmpty()) {
            $products = $products->merge(Product::whereNull('deleted_at')->where(function ($query) use ($categories) {
                $query->whereIn('category_id', $categories)
                    ->orWhereHas('categories', fn ($q) => $q->whereIn('categories.id', $categories));
            })->pluck('id'));
        }

        return $products->map(fn ($id) => (int) $id)->unique()->values();
    }

    private function ensureVisible(Request $request, SupplierTarget $target): void
    {
        if ($request->user()->isSuperAdmin() || $request->user()->is_all_warehouses || $target->created_by === $request->user()->id) {
            return;
        }
        if (! $target->allocations()->whereIn('warehouse_id', $this->access->warehouseIds($request->user()))->exists()) {
            abort(403);
        }
    }

    private function history(SupplierTarget $target, string $event, $old, $new): void
    {
        $target->histories()->create(['user_id' => auth()->id(), 'event' => $event, 'old_values' => $old, 'new_values' => $new]);
    }

    private function payload(SupplierTarget $target, ?array $metrics = null): array
    {
        $target->loadMissing(['supplier:id,name', 'lines.product:id,name', 'lines.category:id,name',
            'lines.unit:id,name,ShortName', 'lines.allocations.warehouse:id,name',
            'lines.discountPostings.creator:id,firstname,lastname', 'allocations.warehouse:id,name']);

        return [
            'id' => $target->id, 'supplier_id' => $target->supplier_id,
            'supplier' => optional($target->supplier)->name, 'target_name' => $target->target_name,
            'period_type' => $target->period_type, 'start_date' => optional($target->start_date)->format('Y-m-d'),
            'end_date' => optional($target->end_date)->format('Y-m-d'), 'measurement_type' => $target->measurement_type,
            'status' => $target->status, 'description' => $target->description,
            'allocation_requires_review' => $target->allocation_requires_review,
            'total_target' => $target->total_target, 'allocated_total' => $target->allocated_total,
            'created_by' => $target->creator ? trim($target->creator->firstname.' '.$target->creator->lastname) : null,
            'lines' => $target->lines->map(fn ($line) => [
                'id' => $line->id, 'type' => $line->product_id ? 'product' : 'category',
                'targetable_id' => $line->product_id ?: $line->category_id,
                'name' => $line->product_id ? optional($line->product)->name : optional($line->category)->name,
                'unit_id' => $line->unit_id, 'unit' => optional($line->unit)->ShortName ?: optional($line->unit)->name,
                'target_quantity' => (float) $line->target_quantity,
                'further_discount_per_unit' => (float) $line->further_discount_per_unit,
                'further_discounts' => $line->discountRules(),
            ])->values(),
            'allocations' => $target->allocations->map(fn ($allocation) => [
                'id' => $allocation->id, 'warehouse_id' => $allocation->warehouse_id,
                'warehouse' => optional($allocation->warehouse)->name,
                'allocated_quantity' => (float) $allocation->allocated_quantity,
            ])->values(),
            'line_allocations' => $target->lines->flatMap(fn ($line) => $line->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'supplier_target_line_id' => $line->id,
                'line_name' => $line->product_id ? optional($line->product)->name : optional($line->category)->name,
                'warehouse_id' => $allocation->warehouse_id,
                'warehouse' => optional($allocation->warehouse)->name,
                'allocated_quantity' => (float) $allocation->allocated_quantity,
            ]))->values(),
            'discount_postings' => $target->lines->flatMap(fn ($line) => $line->discountPostings->map(fn ($posting) => [
                'id' => $posting->id,
                'supplier_target_line_id' => $line->id,
                'line_name' => $line->product_id ? optional($line->product)->name : optional($line->category)->name,
                'posting_date' => optional($posting->posting_date)->format('Y-m-d'),
                'amount' => (float) $posting->amount,
                'reference' => $posting->reference,
                'notes' => $posting->notes,
                'created_by' => $posting->creator ? trim($posting->creator->firstname.' '.$posting->creator->lastname) : null,
            ]))->sortByDesc('posting_date')->values(),
            'histories' => $target->relationLoaded('histories') ? $target->histories->map(fn ($history) => [
                'id' => $history->id, 'event' => $history->event, 'old_values' => $history->old_values,
                'new_values' => $history->new_values, 'user' => $history->user ? trim($history->user->firstname.' '.$history->user->lastname) : null,
                'created_at' => optional($history->created_at)->toIso8601String(),
            ])->values() : [],
            'metrics' => $metrics,
        ];
    }

    private function dashboardPayload($items): array
    {
        $summary = [
            'target' => round((float) $items->sum(fn ($item) => $item['metrics']['target']), 3),
            'achieved' => round((float) $items->sum(fn ($item) => $item['metrics']['achieved']), 3),
        ];
        $summary['remaining'] = round(max($summary['target'] - $summary['achieved'], 0), 3);
        $summary['percentage'] = $summary['target'] > 0 ? round($summary['achieved'] / $summary['target'] * 100, 1) : 0;
        $monthly = $items->flatMap(fn ($item) => $item['metrics']['monthly'])
            ->groupBy('label')->map(fn ($rows, $label) => [
                'label' => $label, 'target' => round((float) $rows->sum('target'), 3),
                'achieved' => round((float) $rows->sum('achieved'), 3),
            ])->values();
        $warehouses = $items->flatMap(fn ($item) => $item['metrics']['warehouses'])
            ->groupBy('id')->map(fn ($rows) => $this->aggregateRows($rows))->values();
        $lines = $items->flatMap(fn ($item) => $item['metrics']['lines'])
            ->groupBy('name')->map(fn ($rows) => $this->aggregateRows($rows))->values();

        return ['summary' => $summary, 'monthly' => $monthly, 'warehouses' => $warehouses,
            'lines' => $lines, 'targets' => $items->map(fn ($item) => $this->payload($item['target'], $item['metrics']))->values()];
    }

    private function aggregateRows($rows): array
    {
        $target = (float) $rows->sum('target');
        $achieved = (float) $rows->sum('achieved');
        $percentage = $target > 0 ? round($achieved / $target * 100, 1) : 0;

        return ['id' => $rows->first()['id'], 'name' => $rows->first()['name'], 'target' => round($target, 3),
            'achieved' => round($achieved, 3), 'remaining' => round(max($target - $achieved, 0), 3),
            'percentage' => $percentage, 'status' => $percentage >= 100 ? ($percentage > 100 ? 'Exceeded' : 'Achieved') : $rows->first()['status']];
    }
}
