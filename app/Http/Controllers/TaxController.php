<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTaxRequest;
use App\Models\Tax;
use App\Models\TaxAudit;
use App\Models\TaxDefault;
use App\Models\TaxPriceType;
use App\Models\TransactionTaxSnapshot;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\Tax\TaxResolver;
use App\Services\Tax\Decimal;
use App\Services\Tax\TaxCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaxController extends Controller
{
    public function index(Request $request)
    {
        $this->permit($request, 'taxes.view');
        $query = $this->visibleQuery($request)->with(['priceTypes', 'transactionTypes', 'warehouses']);
        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"));
        }
        if ($request->filled('status')) $query->where('is_active', $request->status === 'active');
        if ($request->filled('behavior')) $query->where('behavior', $request->behavior);
        if ($request->filled('transaction_type')) $query->forTransaction($request->transaction_type);
        if ($request->filled('price_type_id')) $query->whereHas('priceTypes', fn ($q) => $q->where('tax_price_types.id', $request->integer('price_type_id')));
        $limit = max(1, min((int) $request->input('limit', 10), 100));
        $taxes = $query->orderBy('priority')->orderBy('name')->paginate($limit);

        return response()->json(['taxes' => $taxes->getCollection()->map(fn ($tax) => $this->serialize($tax))->values(), 'totalRows' => $taxes->total()]);
    }

    public function metadata(Request $request)
    {
        $this->permit($request, ['taxes.view', 'taxes.create', 'taxes.update', 'taxes.apply']);
        $user = $request->user('api');
        $warehouses = Warehouse::query()->whereNull('deleted_at');
        if (! $user->is_all_warehouses) $warehouses->whereIn('id', UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id'));
        return response()->json([
            'price_types' => TaxPriceType::where('is_active', true)->orderBy('sort_order')->get(),
            'transaction_types' => Tax::TRANSACTION_TYPES,
            'behaviors' => Tax::BEHAVIORS,
            'calculation_types' => Tax::CALCULATION_TYPES,
            'warehouses' => $warehouses->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function applicable(Request $request, TaxResolver $resolver)
    {
        $this->permitTransactionTaxApplication($request);
        $validated = $request->validate([
            'transaction_type' => ['required', Rule::in(Tax::TRANSACTION_TYPES)],
            'price_type' => ['required'], 'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'], 'date' => ['nullable', 'date'],
        ]);
        return response()->json(['taxes' => $resolver->applicable($validated['transaction_type'], $validated['price_type'], $validated['warehouse_id'] ?? null, $request->user('api'), $validated['date'] ?? null)->map(fn ($tax) => $this->serialize($tax))->values()]);
    }

    /**
     * Calculate a transaction tax preview with the same resolver and decimal
     * calculator used when the transaction is persisted.
     */
    public function preview(Request $request, TaxResolver $resolver, TaxCalculationService $calculator)
    {
        $this->permitTransactionTaxApplication($request);
        $validated = $request->validate([
            'transaction_type' => ['required', Rule::in(Tax::TRANSACTION_TYPES)],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date' => ['nullable', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'discount_method' => ['nullable', Rule::in(['1', '2', 1, 2])],
            'points_discount' => ['nullable', 'numeric', 'min:0'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'max:500'],
            'lines.*.line_key' => ['required'],
            'lines.*.price_type' => ['required'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_method' => ['nullable', Rule::in(['1', '2', 1, 2])],
            'lines.*.excluded_tax_codes' => ['nullable', 'array'],
            'lines.*.excluded_tax_codes.*' => ['string', 'max:40'],
        ]);

        $rows = collect();
        $lineTotals = [];
        $availableTaxes = [];
        $subtotal = '0.000000';
        $warehouseId = $validated['warehouse_id'] ?? null;
        $date = $validated['date'] ?? null;

        foreach ($validated['lines'] as $line) {
            $priceType = is_numeric($line['price_type'])
                ? TaxPriceType::whereKey((int) $line['price_type'])->where('is_active', true)->first()
                : TaxPriceType::where('code', $line['price_type'])->where('is_active', true)->first();
            abort_unless($priceType, 422, 'The selected tax price type is invalid.');

            $unitPrice = Decimal::round($line['unit_price'], 6);
            $lineDiscount = Decimal::round($line['discount'] ?? 0, 6);
            $discountAmount = (string) ($line['discount_method'] ?? '2') === '1'
                ? Decimal::mul($unitPrice, Decimal::div($lineDiscount, '100'))
                : $lineDiscount;
            $netUnitPrice = Decimal::sub($unitPrice, $discountAmount);
            if (bccomp($netUnitPrice, '0', 6) === -1) $netUnitPrice = '0.000000';

            $quantity = Decimal::round($line['quantity'], 6);
            $taxableLine = Decimal::mul($netUnitPrice, $quantity);
            $subtotal = Decimal::add($subtotal, $taxableLine);
            $taxes = $resolver->applicable($validated['transaction_type'], $priceType->id, $warehouseId, $request->user('api'), $date);
            $availableTaxes[(string) $line['line_key']] = $taxes->map(fn ($tax) => [
                'tax_id' => $tax->id,
                'tax_name' => $tax->name,
                'tax_code' => $tax->code,
                'calculation_type' => $tax->calculation_type,
                'rate' => Decimal::round($tax->rate, 6),
                'behavior' => $tax->behavior,
                'price_type_id' => $priceType->id,
                'price_type_code' => $priceType->code,
                'price_type_name' => $priceType->name,
            ])->values();

            $excludedCodes = collect($line['excluded_tax_codes'] ?? [])->map(fn ($code) => strtoupper(trim((string) $code)))->filter()->unique();
            if ($excludedCodes->isNotEmpty()) {
                $user = $request->user('api');
                abort_unless($user->isSuperAdmin() || $user->effectivePermissionNames()->contains('taxes.override'), 403, 'You are not allowed to override automatically selected taxes.');
                $taxes = $taxes->reject(fn ($tax) => $excludedCodes->contains(strtoupper($tax->code)))->values();
            }
            $calculated = collect($calculator->calculateLine($netUnitPrice, $quantity, $taxes));

            $additive = $calculated->where('behavior', 'additive')->reduce(fn ($sum, $row) => Decimal::add($sum, $row['tax_amount']), '0');
            $deductive = $calculated->where('behavior', 'deductive')->reduce(fn ($sum, $row) => Decimal::add($sum, $row['tax_amount']), '0');
            $lineTotal = Decimal::sub(Decimal::add($taxableLine, $additive), $deductive);
            $lineTotals[(string) $line['line_key']] = Decimal::round($lineTotal, 2);

            foreach ($calculated as $row) {
                $rows->push($row + [
                    'line_key' => $line['line_key'],
                    'price_type_id' => $priceType->id,
                    'price_type_code' => $priceType->code,
                    'price_type_name' => $priceType->name,
                    'quantity' => $quantity,
                ]);
            }
        }

        $manualDiscount = Decimal::round($validated['discount'] ?? 0, 6);
        if ((string) ($validated['discount_method'] ?? '2') === '1') {
            $manualDiscount = Decimal::mul($subtotal, Decimal::div($manualDiscount, '100'));
        }
        if (bccomp($manualDiscount, $subtotal, 6) === 1) $manualDiscount = $subtotal;
        $remaining = Decimal::sub($subtotal, $manualDiscount);
        $pointsDiscount = Decimal::round($validated['points_discount'] ?? 0, 6);
        if (bccomp($pointsDiscount, $remaining, 6) === 1) $pointsDiscount = $remaining;
        $totalDiscount = Decimal::add($manualDiscount, $pointsDiscount);

        $totals = $calculator->totals(
            $subtotal,
            $totalDiscount,
            Decimal::round($validated['shipping'] ?? 0, 6),
            $rows
        );

        $summary = $rows->groupBy(fn ($row) => implode('|', [
            $row['tax_code'], $row['price_type_code'], $row['behavior'], $row['rate'], $row['calculation_type'],
        ]))->map(function ($group) {
            $first = $group->first();
            return Arr::only($first, [
                'tax_id', 'tax_name', 'tax_code', 'calculation_type', 'rate', 'behavior',
                'price_type_id', 'price_type_code', 'price_type_name', 'priority', 'is_compound',
            ]) + [
                'taxable_base' => $group->reduce(fn ($sum, $row) => Decimal::add($sum, $row['taxable_base']), '0'),
                'tax_amount' => $group->reduce(fn ($sum, $row) => Decimal::add($sum, $row['tax_amount']), '0'),
            ];
        })->sortBy('priority')->values();

        return response()->json([
            'rows' => $rows->values(),
            'summary' => $summary,
            'line_totals' => $lineTotals,
            'available_taxes' => $availableTaxes,
            'managed_available' => collect($availableTaxes)->flatten(1)->isNotEmpty(),
            'totals' => $totals,
        ]);
    }

    public function store(SaveTaxRequest $request)
    {
        $this->permit($request, 'taxes.create');
        $tax = DB::transaction(function () use ($request) {
            $data = Arr::except($request->validated(), ['transaction_types', 'price_type_ids', 'warehouse_ids']);
            $data += ['scope_key' => 'global', 'company_id' => null, 'created_by' => $request->user('api')->id, 'updated_by' => $request->user('api')->id];
            $tax = Tax::create($data);
            $this->syncRelations($tax, $request->validated());
            $this->audit($request, $tax, 'created', null, $this->auditState($tax));
            return $tax;
        });
        return response()->json(['tax' => $this->serialize($tax->load(['priceTypes', 'transactionTypes', 'warehouses']))], 201);
    }

    public function show(Request $request, Tax $tax)
    {
        $this->permit($request, 'taxes.view');
        $this->assertVisible($request, $tax);
        return response()->json(['tax' => $this->serialize($tax->load(['priceTypes', 'transactionTypes', 'warehouses', 'audits' => fn ($q) => $q->latest()->limit(50)]))]);
    }

    public function update(SaveTaxRequest $request, Tax $tax)
    {
        $this->permit($request, 'taxes.update');
        $this->assertVisible($request, $tax);
        DB::transaction(function () use ($request, $tax) {
            $before = $this->auditState($tax->load(['priceTypes', 'transactionTypes', 'warehouses']));
            $data = Arr::except($request->validated(), ['transaction_types', 'price_type_ids', 'warehouse_ids', 'is_active']);
            $data['updated_by'] = $request->user('api')->id;
            $tax->update($data);
            if ((bool) $tax->is_active !== (bool) $request->boolean('is_active')) {
                $this->permit($request, 'taxes.activate');
                $tax->update(['is_active' => $request->boolean('is_active')]);
            }
            $this->syncRelations($tax, $request->validated());
            $this->audit($request, $tax, 'updated', $before, $this->auditState($tax->fresh(['priceTypes', 'transactionTypes', 'warehouses'])));
        });
        return response()->json(['tax' => $this->serialize($tax->fresh(['priceTypes', 'transactionTypes', 'warehouses']))]);
    }

    public function toggle(Request $request, Tax $tax)
    {
        $this->permit($request, 'taxes.activate');
        $this->assertVisible($request, $tax);
        $before = ['is_active' => (bool) $tax->is_active];
        $tax->update(['is_active' => ! $tax->is_active, 'updated_by' => $request->user('api')->id]);
        $this->audit($request, $tax, $tax->is_active ? 'activated' : 'deactivated', $before, ['is_active' => (bool) $tax->is_active]);
        return response()->json(['tax' => $this->serialize($tax->load(['priceTypes', 'transactionTypes', 'warehouses']))]);
    }

    public function destroy(Request $request, Tax $tax)
    {
        $this->permit($request, 'taxes.delete');
        $this->assertVisible($request, $tax);
        if (TransactionTaxSnapshot::where('tax_id', $tax->id)->exists()) {
            return response()->json(['message' => 'A tax used in a transaction cannot be deleted. Deactivate it instead.'], 422);
        }
        $before = $this->auditState($tax->load(['priceTypes', 'transactionTypes', 'warehouses']));
        DB::transaction(function () use ($request, $tax, $before) {
            TaxDefault::where('tax_id', $tax->id)->delete();
            $this->audit($request, $tax, 'deleted', $before, null);
            $tax->delete();
        });
        return response()->json(['success' => true]);
    }

    public function defaults(Request $request)
    {
        $this->permit($request, 'taxes.view');
        return response()->json(['defaults' => TaxDefault::with('tax')->where('scope_key', 'global')->get()]);
    }

    public function saveDefaults(Request $request)
    {
        $this->permit($request, 'taxes.update');
        $data = $request->validate(['defaults' => ['array'], 'defaults.*' => ['nullable', 'integer', 'exists:taxes,id']]);
        DB::transaction(function () use ($request, $data) {
            foreach (['purchase', 'sale_invoice', 'pos'] as $type) {
                TaxDefault::where('scope_key', 'global')->where('transaction_type', $type)->delete();
                $taxId = $data['defaults'][$type] ?? null;
                if (! $taxId) continue;
                $tax = $this->visibleQuery($request)->effective()->forTransaction($type)->find($taxId);
                if (! $tax) abort(422, "The {$type} default tax is not currently applicable.");
                TaxDefault::create(['scope_key' => 'global', 'transaction_type' => $type, 'tax_id' => $tax->id, 'updated_by' => $request->user('api')->id]);
                $this->audit($request, $tax, 'default_changed', null, ['transaction_type' => $type, 'tax_id' => $tax->id]);
            }
        });
        return $this->defaults($request);
    }

    private function visibleQuery(Request $request)
    {
        $user = $request->user('api');
        $allowed = $user->is_all_warehouses ? null : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');
        return Tax::query()->whereNull('company_id')->where(function ($q) use ($allowed) {
            $q->whereDoesntHave('warehouses');
            if ($allowed !== null) $q->orWhereHas('warehouses', fn ($w) => $w->whereIn('warehouses.id', $allowed));
        });
    }

    private function assertVisible(Request $request, Tax $tax): void
    {
        abort_unless($this->visibleQuery($request)->whereKey($tax->id)->exists(), 404);
    }

    private function syncRelations(Tax $tax, array $data): void
    {
        $tax->priceTypes()->sync($data['price_type_ids']);
        $tax->warehouses()->sync($data['warehouse_ids'] ?? []);
        $tax->transactionTypes()->delete();
        $tax->transactionTypes()->createMany(collect($data['transaction_types'])->unique()->map(fn ($type) => ['transaction_type' => $type])->all());
    }

    private function serialize(Tax $tax): array
    {
        return array_merge($tax->toArray(), [
            'transaction_types' => $tax->relationLoaded('transactionTypes') ? $tax->transactionTypes->pluck('transaction_type')->values() : [],
            'price_type_ids' => $tax->relationLoaded('priceTypes') ? $tax->priceTypes->pluck('id')->values() : [],
            'warehouse_ids' => $tax->relationLoaded('warehouses') ? $tax->warehouses->pluck('id')->values() : [],
            'is_used' => TransactionTaxSnapshot::where('tax_id', $tax->id)->exists(),
        ]);
    }

    private function auditState(Tax $tax): array
    {
        return Arr::only($tax->toArray(), ['name', 'code', 'description', 'calculation_type', 'rate', 'behavior', 'effective_start_date', 'effective_end_date', 'priority', 'is_compound', 'is_active']) + [
            'transaction_types' => $tax->transactionTypes->pluck('transaction_type')->values()->all(),
            'price_type_ids' => $tax->priceTypes->pluck('id')->values()->all(),
            'warehouse_ids' => $tax->warehouses->pluck('id')->values()->all(),
        ];
    }

    private function audit(Request $request, Tax $tax, string $event, ?array $before, ?array $after): void
    {
        TaxAudit::create(['tax_id' => $tax->id, 'user_id' => $request->user('api')->id, 'event' => $event, 'auditable_type' => Tax::class, 'auditable_id' => $tax->id, 'before' => $before, 'after' => $after, 'ip_address' => $request->ip()]);
    }

    private function permit(Request $request, string|array $permissions): void
    {
        $user = $request->user('api');
        $permissions = (array) $permissions;
        abort_unless($user && ($user->isSuperAdmin() || $user->effectivePermissionNames()->intersect($permissions)->isNotEmpty()), 403);
    }

    /**
     * Automatic tax calculation is part of creating a transaction. Users who
     * can create that transaction must therefore be able to resolve and preview
     * its approved taxes even when an older/custom role is missing taxes.apply.
     */
    private function permitTransactionTaxApplication(Request $request): void
    {
        $transactionPermission = match ((string) $request->input('transaction_type')) {
            'purchase' => 'Purchases_add',
            'sale_invoice' => 'Sales_add',
            'pos' => 'Pos_view',
            'sale_return' => 'Sale_Returns_add',
            'purchase_return' => 'Purchase_Returns_add',
            default => null,
        };

        $permissions = ['taxes.apply'];
        if ($transactionPermission) {
            $permissions[] = $transactionPermission;
        }

        $this->permit($request, $permissions);
    }
}
