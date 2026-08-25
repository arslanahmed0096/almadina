<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\TransactionTaxSnapshot;
use App\Models\UserWarehouse;
use App\Services\Tax\Decimal;
use Illuminate\Http\Request;

class TaxReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user && ($user->isSuperAdmin() || $user->effectivePermissionNames()->contains('taxes.report')), 403);
        $validated = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'tax_id' => ['nullable', 'integer'], 'tax_code' => ['nullable', 'string'],
            'transaction_type' => ['nullable', 'string'], 'behavior' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = TransactionTaxSnapshot::query();
        foreach (['tax_id', 'tax_code', 'behavior'] as $field) if (! empty($validated[$field])) $query->where($field, $validated[$field]);
        $transactions = $this->transactions($request, $validated);
        $query->where(function ($scoped) use ($transactions, $validated) {
            $scoped->whereRaw('1 = 0');
            foreach ($transactions as $type => $items) {
                if (! empty($validated['transaction_type']) && $validated['transaction_type'] !== $type) continue;
                $ids = $items->keys();
                if ($ids->isNotEmpty()) $scoped->orWhere(fn ($group) => $group->where('transaction_type', $type)->whereIn('transaction_id', $ids));
            }
        });
        $summaryQuery = clone $query;
        $rows = $query->latest()->paginate($validated['limit'] ?? 20);
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($transactions) {
            $transaction = ($transactions[$row->transaction_type] ?? collect())->get($row->transaction_id);
            $array = $row->toArray();
            if (! $transaction) return $array;
            $party = in_array($row->transaction_type, ['purchase', 'purchase_return'], true)
                ? optional($transaction->provider)->name : optional($transaction->client)->name;
            return $array + [
                'date' => (string) $transaction->date, 'reference' => $transaction->Ref,
                'branch' => optional($transaction->warehouse)->name, 'party' => $party,
                'transaction_status' => $transaction->statut,
            ];
        }));
        $all = $summaryQuery->get(['taxable_base', 'tax_amount', 'behavior', 'is_reversal']);
        $sum = fn ($items, $field) => $items->reduce(fn ($total, $item) => Decimal::add($total, (string) $item->{$field}), '0.000000');
        $reversed = $all->where('is_reversal', true);
        $normal = $all->where('is_reversal', false);
        $additive = $normal->where('behavior', 'additive');
        $deductive = $normal->where('behavior', 'deductive');
        return response()->json([
            'rows' => $rows->items(), 'totalRows' => $rows->total(),
            'summary' => [
                'taxable_amount' => $sum($normal, 'taxable_base'), 'additive_taxes' => $sum($additive, 'tax_amount'),
                'deductive_taxes' => $sum($deductive, 'tax_amount'), 'reversed_taxes' => $sum($reversed, 'tax_amount'),
                'net_tax' => Decimal::sub(Decimal::sub($sum($additive, 'tax_amount'), $sum($deductive, 'tax_amount')), $sum($reversed, 'tax_amount')),
            ],
        ]);
    }

    private function transactions(Request $request, array $filters): array
    {
        $user = $request->user('api');
        $allowed = $user->is_all_warehouses ? null : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');
        $base = function ($query, string $partyKey = null) use ($allowed, $filters) {
            $query->with(['warehouse:id,name', $partyKey === 'provider_id' ? 'provider:id,name' : 'client:id,name']);
            if ($allowed !== null) $query->whereIn('warehouse_id', $allowed);
            if (! empty($filters['warehouse_id'])) $query->where('warehouse_id', $filters['warehouse_id']);
            if (! empty($filters['status'])) $query->where('statut', $filters['status']);
            if (! empty($filters['from'])) $query->whereDate('date', '>=', $filters['from']);
            if (! empty($filters['to'])) $query->whereDate('date', '<=', $filters['to']);
            if ($partyKey && ! empty($filters[$partyKey])) $query->where($partyKey, $filters[$partyKey]);
            return $query;
        };

        $purchases = $base(Purchase::query(), 'provider_id')->get()->keyBy('id');
        $sales = $base(Sale::query(), 'client_id')->get();
        $saleReturns = $base(SaleReturn::query(), 'client_id')->get()->keyBy('id');
        $purchaseReturns = $base(PurchaseReturn::query(), 'provider_id')->get()->keyBy('id');
        return [
            'purchase' => $purchases,
            'sale_invoice' => $sales->where('is_pos', 0)->keyBy('id'),
            'pos' => $sales->where('is_pos', 1)->keyBy('id'),
            'sale_return' => $saleReturns,
            'purchase_return' => $purchaseReturns,
        ];
    }
}
