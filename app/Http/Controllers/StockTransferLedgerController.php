<?php

namespace App\Http\Controllers;

use App\Exports\StockTransferLedgerExport;
use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\StockTransferCompany;
use App\Models\StockTransferLedgerEntry;
use App\Models\StockTransferPayment;
use App\Models\Transfer;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\StockTransferLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class StockTransferLedgerController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeLedger($request, false);
        $query = $this->transferQuery($request);
        $perPage = min(max((int) $request->input('limit', 25), 1), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn ($transfer) => $this->transferRow($transfer))->values(),
            'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ]);
    }

    public function detail(Request $request, int $id)
    {
        $this->authorizeLedger($request, false);
        $transfer = $this->transferQuery($request)->with([
            'details.product:id,name,code', 'paymentAllocations.payment:id,reference,payment_date,amount',
            'ledgerEntries' => fn ($query) => $query->orderBy('entry_date')->orderBy('id'),
        ])->findOrFail($id);
        return response()->json(['transfer' => $this->transferRow($transfer) + [
            'previous_outstanding' => (float) $transfer->previous_outstanding,
            'advance_applied' => (float) $transfer->advance_applied,
            'items' => $transfer->details,
            'allocations' => $transfer->paymentAllocations,
            'financial_entries' => $transfer->ledgerEntries,
            'remarks' => $transfer->notes,
        ]]);
    }

    public function summary(Request $request)
    {
        $this->authorizeLedger($request, false);
        $transfers = $this->transferQuery($request)
            ->where('ownership_type', 'external')
            ->whereNotNull('financial_posted_at')
            ->get();
        $companiesQuery = StockTransferCompany::where('is_active', true)->orderBy('name');
        if ($request->filled('company_id')) $companiesQuery->whereKey($request->company_id);
        $companies = $companiesQuery->get(['id', 'name']);
        $companyIds = $companies->pluck('id');
        $payments = StockTransferPayment::where('status', 'posted')->whereIn('stock_transfer_company_id', $companyIds);
        if ($request->filled('date_from')) $payments->whereDate('payment_date', '>=', $request->date_from);
        if ($request->filled('date_to')) $payments->whereDate('payment_date', '<=', $request->date_to);
        $companyBalances = StockTransferLedgerEntry::whereIn('stock_transfer_company_id', $companyIds)
            ->selectRaw('stock_transfer_company_id, SUM(debit-credit) balance')->groupBy('stock_transfer_company_id')->pluck('balance', 'stock_transfer_company_id');

        $groupedTransfers = $transfers->groupBy('stock_transfer_company_id');
        $byCompany = $companies->map(function ($company) use ($companyBalances, $groupedTransfers) {
            $rows = $groupedTransfers->get($company->id, collect());
            $companyId = $company->id;
            $balance = (float) ($companyBalances[$companyId] ?? 0);
            return [
                'company_id' => (int) $companyId,
                'company' => $company->name,
                'transfer_value' => round($rows->sum('transfer_value'), 2),
                'outstanding' => max(0, round($balance, 2)),
                'available_advance' => max(0, round(-$balance, 2)),
            ];
        })->values();
        $byWarehouse = $transfers->groupBy('to_warehouse_id')->map(fn ($rows, $warehouseId) => [
            'warehouse_id' => (int) $warehouseId,
            'warehouse' => optional($rows->first()->to_warehouse)->name,
            'company' => optional($rows->first()->stockTransferCompany)->name,
            'transfer_value' => round($rows->sum('transfer_value'), 2),
            'outstanding' => round($rows->sum('outstanding_balance'), 2),
        ])->values();

        return response()->json([
            'totals' => [
                'transfer_value' => round($transfers->sum('transfer_value'), 2),
                'payments_received' => round((float) $payments->sum('amount'), 2),
                'outstanding_receivables' => round($byCompany->sum('outstanding'), 2),
                'available_advances' => round($byCompany->sum('available_advance'), 2),
            ],
            'companies' => $byCompany, 'warehouses' => $byWarehouse,
        ]);
    }

    public function ledger(Request $request)
    {
        $this->authorizeLedger($request, false);
        $validated = $request->validate([
            'company_id' => 'nullable|integer|exists:stock_transfer_companies,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);
        $query = StockTransferLedgerEntry::with(['company:id,name', 'warehouse:id,name'])
            ->orderBy('entry_date')->orderBy('id');
        if (! empty($validated['company_id'])) $query->where('stock_transfer_company_id', $validated['company_id']);
        if (! empty($validated['warehouse_id'])) $query->where('warehouse_id', $validated['warehouse_id']);
        $opening = 0.0;
        if (! empty($validated['date_from'])) {
            $openingQuery = clone $query;
            $opening = (float) $openingQuery->whereDate('entry_date', '<', $validated['date_from'])->sum(DB::raw('debit-credit'));
            $query->whereDate('entry_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) $query->whereDate('entry_date', '<=', $validated['date_to']);
        $running = $opening;
        $rows = $query->get()->map(function ($entry) use (&$running) {
            $running = round($running + (float) $entry->debit - (float) $entry->credit, 4);
            return [
                'id' => $entry->id, 'date' => $entry->entry_date->format('Y-m-d'), 'type' => $entry->entry_type,
                'reference' => $entry->reference, 'company' => optional($entry->company)->name,
                'warehouse' => optional($entry->warehouse)->name, 'debit' => (float) $entry->debit,
                'credit' => (float) $entry->credit, 'balance' => $running, 'notes' => $entry->notes,
                'transfer_id' => $entry->transfer_id, 'payment_id' => $entry->stock_transfer_payment_id,
            ];
        });
        return response()->json(['opening_balance' => $opening, 'closing_balance' => $running, 'rows' => $rows]);
    }

    public function payments(Request $request)
    {
        $this->authorizeLedger($request, false);
        $query = StockTransferPayment::with(['company:id,name', 'warehouse:id,name', 'paymentMethod:id,name', 'account:id,account_name', 'receiver:id,username', 'allocations.transfer:id,Ref']);
        if ($request->filled('company_id')) $query->where('stock_transfer_company_id', $request->company_id);
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', $request->warehouse_id);
        if ($request->filled('date_from')) $query->whereDate('payment_date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('payment_date', '<=', $request->date_to);
        return response()->json(['payments' => $query->latest('payment_date')->latest('id')->limit(500)->get()->map(function ($payment) {
            $allocated = (float) $payment->allocations->sum('amount');
            return $payment->toArray() + ['allocated_amount' => $allocated, 'unallocated_amount' => round((float) $payment->amount - $allocated, 4)];
        })]);
    }

    public function storePayment(Request $request, StockTransferLedgerService $service)
    {
        $this->authorizeLedger($request, true, 'stock_transfer_payment_create');
        $data = $request->validate([
            'stock_transfer_company_id' => 'required|integer|exists:stock_transfer_companies,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id', 'payment_date' => 'required|date',
            'amount' => 'required|numeric|gt:0', 'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'account_id' => 'nullable|integer|exists:accounts,id', 'external_reference' => 'nullable|string|max:192',
            'idempotency_key' => 'nullable|uuid',
            'remarks' => 'nullable|string|max:5000', 'transfer_id' => 'nullable|integer|exists:transfers,id',
            'auto_allocate' => 'nullable|boolean', 'allocations' => 'nullable|array',
            'allocations.*.transfer_id' => 'required|integer|exists:transfers,id', 'allocations.*.amount' => 'required|numeric|gt:0',
        ]);
        if (! empty($data['external_reference'])) {
            $duplicate = StockTransferPayment::where('stock_transfer_company_id', $data['stock_transfer_company_id'])
                ->where('external_reference', $data['external_reference'])->exists();
            if ($duplicate) {
                throw \Illuminate\Validation\ValidationException::withMessages(['external_reference' => ['This payment reference has already been recorded for the company.']]);
            }
        }
        $payment = $service->recordPayment($data, $request->user('api'));
        return response()->json(['success' => true, 'payment' => $payment], 201);
    }

    public function allocatePayment(Request $request, int $id, StockTransferLedgerService $service)
    {
        $this->authorizeLedger($request, true, 'stock_transfer_payment_create');
        $data = $request->validate(['transfer_id' => 'required|integer|exists:transfers,id', 'amount' => 'required|numeric|gt:0']);
        $allocation = DB::transaction(fn () => $service->allocate(StockTransferPayment::findOrFail($id), (int) $data['transfer_id'], (float) $data['amount'], $request->user('api'), 'advance'), 10);
        return response()->json(['success' => true, 'allocation' => $allocation]);
    }

    public function meta(Request $request)
    {
        $this->authorizeLedger($request, false);
        $companies = StockTransferCompany::with(['warehouses' => fn ($query) => $query->whereNull('deleted_at')->orderBy('name')])->orderBy('name')->get();
        return response()->json([
            'companies' => $companies,
            'warehouses' => Warehouse::with('stockTransferCompany:id,name')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'ownership_type', 'stock_transfer_company_id']),
            'payment_methods' => PaymentMethod::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name', 'account_type', 'balance']),
            'open_transfers' => Transfer::with(['to_warehouse:id,name', 'stockTransferCompany:id,name'])
                ->where('ownership_type', 'external')->whereNotNull('financial_posted_at')->whereIn('payment_status', ['unpaid', 'partially_paid'])
                ->orderBy('date')->get(['id', 'Ref', 'date', 'to_warehouse_id', 'stock_transfer_company_id', 'transfer_value', 'outstanding_balance', 'payment_status']),
        ]);
    }

    public function storeCompany(Request $request, StockTransferLedgerService $service)
    {
        $this->authorizeLedger($request, true, 'stock_transfer_ledger_manage');
        $data = $request->validate($this->companyRules());
        $company = DB::transaction(function () use ($data, $request, $service) {
            $company = StockTransferCompany::create($data);
            $service->createOpeningEntry($company, $request->user('api'));
            return $company;
        }, 10);
        return response()->json(['success' => true, 'company' => $company], 201);
    }

    public function updateCompany(Request $request, int $id)
    {
        $this->authorizeLedger($request, true, 'stock_transfer_ledger_manage');
        $company = StockTransferCompany::findOrFail($id);
        $rules = $this->companyRules($company->id);
        unset($rules['opening_balance']);
        $company->update($request->validate($rules));
        return response()->json(['success' => true, 'company' => $company]);
    }

    public function assignWarehouse(Request $request, int $id)
    {
        $this->authorizeLedger($request, true, 'stock_transfer_ledger_manage');
        $data = $request->validate(['ownership_type' => 'required|in:internal,external', 'stock_transfer_company_id' => 'nullable|required_if:ownership_type,external|exists:stock_transfer_companies,id']);
        $warehouse = Warehouse::whereNull('deleted_at')->findOrFail($id);
        $warehouse->update(['ownership_type' => $data['ownership_type'], 'stock_transfer_company_id' => $data['ownership_type'] === 'external' ? $data['stock_transfer_company_id'] : null]);
        return response()->json(['success' => true, 'warehouse' => $warehouse->fresh('stockTransferCompany')]);
    }

    public function exportExcel(Request $request)
    {
        $this->authorizeLedger($request, false);
        $type = $request->input('export_type', 'transfers');
        $rows = $type === 'ledger' ? $this->ledgerExportRows($request) : $this->transferExportRows($request);
        return Excel::download(new StockTransferLedgerExport($rows, $type), 'stock-transfer-'.$type.'-'.now()->format('Y-m-d-His').'.xlsx');
    }

    public function exportPdf(Request $request)
    {
        $this->authorizeLedger($request, false);
        $type = $request->input('export_type', 'transfers');
        $rows = $type === 'ledger' ? $this->ledgerExportRows($request) : $this->transferExportRows($request);
        return \PDF::loadView('reports.stock_transfer_ledger', compact('rows', 'type'))->setPaper('a4', 'landscape')->download('stock-transfer-'.$type.'.pdf');
    }

    private function transferQuery(Request $request)
    {
        $query = Transfer::with([
            'from_warehouse:id,name',
            'to_warehouse.stockTransferCompany:id,name',
            'stockTransferCompany:id,name',
        ])
            ->withSum('details as transfer_quantity', 'quantity')->whereNull('deleted_at');
        if ($request->input('transfer_scope') === 'external') $query->where('ownership_type', 'external');
        if ($request->input('transfer_scope') === 'internal') $query->where('ownership_type', 'internal');
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('date', '<=', $request->date_to);
        if ($request->filled('source_warehouse_id')) $query->where('from_warehouse_id', $request->source_warehouse_id);
        if ($request->filled('destination_warehouse_id')) $query->where('to_warehouse_id', $request->destination_warehouse_id);
        if ($request->filled('company_id')) {
            $query->where(function ($companyQuery) use ($request) {
                $companyQuery->where('stock_transfer_company_id', $request->company_id)
                    ->orWhereHas('to_warehouse', fn ($warehouseQuery) => $warehouseQuery->where('stock_transfer_company_id', $request->company_id));
            });
        }
        if ($request->filled('payment_status')) $query->where('payment_status', $request->payment_status);
        if ($request->filled('ownership_type')) $query->where('ownership_type', $request->ownership_type);
        $user = $request->user('api');
        if (! $user->isSuperAdmin() && ! $user->is_all_warehouses) {
            $ids = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');
            $query->where(fn ($q) => $q->whereIn('from_warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids));
        }
        return $query->orderByDesc('date')->orderByDesc('id');
    }

    private function transferRow(Transfer $transfer): array
    {
        return [
            'id' => $transfer->id, 'date' => $transfer->date, 'reference' => $transfer->Ref,
            'from_warehouse' => optional($transfer->from_warehouse)->name, 'to_warehouse' => optional($transfer->to_warehouse)->name,
            'company' => optional($transfer->stockTransferCompany)->name
                ?: optional(optional($transfer->to_warehouse)->stockTransferCompany)->name,
            'ownership_type' => $transfer->ownership_type,
            'quantity' => (float) ($transfer->transfer_quantity ?? $transfer->details?->sum('quantity') ?? 0),
            'transfer_value' => (float) $transfer->transfer_value, 'paid_amount' => (float) $transfer->paid_amount,
            'outstanding_balance' => (float) $transfer->outstanding_balance, 'payment_status' => $transfer->payment_status,
            'workflow_status' => $transfer->workflow_status,
        ];
    }

    private function transferExportRows(Request $request): array
    {
        return $this->transferQuery($request)->get()->map(function ($transfer) {
            $row = $this->transferRow($transfer);
            return [$row['date'], $row['reference'], $row['from_warehouse'], $row['to_warehouse'], $row['company'], $row['quantity'], $row['transfer_value'], $row['paid_amount'], $row['outstanding_balance'], $row['payment_status']];
        })->all();
    }

    private function ledgerExportRows(Request $request): array
    {
        $response = $this->ledger($request)->getData(true);
        return collect($response['rows'])->map(fn ($row) => [$row['date'], $row['type'], $row['reference'], $row['company'], $row['warehouse'], $row['debit'], $row['credit'], $row['balance'], $row['notes']])->all();
    }

    private function companyRules(?int $id = null): array
    {
        return [
            'name' => 'required|string|max:192', 'code' => ['nullable', 'string', 'max:60', Rule::unique('stock_transfer_companies', 'code')->ignore($id)],
            'phone' => 'nullable|string|max:60', 'email' => 'nullable|email|max:192', 'address' => 'nullable|string|max:5000',
            'opening_balance' => 'nullable|numeric', 'is_active' => 'nullable|boolean', 'notes' => 'nullable|string|max:5000',
        ];
    }

    private function authorizeLedger(Request $request, bool $write, ?string $permission = null): void
    {
        $user = $request->user('api');
        $names = $user->effectivePermissionNames();
        $allowed = $user->isSuperAdmin() || $names->contains($permission ?: 'stock_transfer_ledger_view') || (! $write && $names->contains('transfer_view'));
        abort_unless($allowed, 403, 'You are not allowed to access the stock transfer ledger.');
    }
}
