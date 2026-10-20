<?php

namespace App\Services\Reports;

use App\Models\Provider;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierAgingReportService
{
    public function generate(
        $user,
        string $month,
        Carbon $asOf,
        int $overdueDays = 40,
        ?int $supplierId = null,
        ?int $warehouseId = null
    ): array {
        $hasAllWarehouses = $user->isSuperAdmin() || (bool) $user->is_all_warehouses;
        $accessibleWarehouseIds = $hasAllWarehouses
            ? Warehouse::whereNull('deleted_at')->pluck('id')
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');

        if ($warehouseId && ! $accessibleWarehouseIds->contains($warehouseId)) {
            abort(403, 'You do not have access to the selected branch.');
        }
        if ($supplierId) {
            Provider::whereNull('deleted_at')->findOrFail($supplierId);
        }

        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $asOf = $asOf->copy()->startOfDay();

        $purchases = DB::table('purchases')
            ->join('providers', 'providers.id', '=', 'purchases.provider_id')
            ->whereNull('purchases.deleted_at')
            ->whereNull('providers.deleted_at')
            ->where('purchases.statut', 'received')
            ->whereDate('purchases.date', '<=', $asOf->toDateString())
            ->when($supplierId, fn ($query) => $query->where('purchases.provider_id', $supplierId))
            ->when($warehouseId, fn ($query) => $query->where('purchases.warehouse_id', $warehouseId))
            ->when(! $warehouseId, fn ($query) => $query->whereIn('purchases.warehouse_id', $accessibleWarehouseIds))
            ->select([
                'purchases.id', 'purchases.Ref', 'purchases.date', 'purchases.provider_id',
                'purchases.GrandTotal', 'purchases.payment_due_days', 'purchases.payment_due_date',
                'providers.name as supplier_name',
            ])
            ->orderBy('providers.name')->orderBy('purchases.date')->orderBy('purchases.id')
            ->get();

        $purchaseIds = $purchases->pluck('id');
        $paidByPurchase = $purchaseIds->isEmpty()
            ? collect()
            : DB::table('payment_purchases')
                ->whereNull('deleted_at')
                ->whereIn('purchase_id', $purchaseIds)
                ->whereDate('date', '<=', $asOf->toDateString())
                ->groupBy('purchase_id')
                ->selectRaw('purchase_id, SUM(montant) as paid_amount')
                ->get()->keyBy('purchase_id');

        $collectionBySupplier = $this->monthlyCollections(
            $accessibleWarehouseIds, $monthStart, $monthEnd, $asOf, $supplierId, $warehouseId
        );

        $details = $purchases->map(function ($purchase) use ($paidByPurchase, $asOf, $monthStart, $monthEnd, $overdueDays) {
            $paid = (float) ($paidByPurchase->get($purchase->id)->paid_amount ?? 0);
            $outstanding = round(max(0, (float) $purchase->GrandTotal - $paid), 2);
            if ($outstanding <= 0) {
                return null;
            }

            $purchaseDate = Carbon::parse($purchase->date)->startOfDay();
            $termsDays = $purchase->payment_due_days === null ? 15 : (int) $purchase->payment_due_days;
            $dueDate = $purchase->payment_due_date
                ? Carbon::parse($purchase->payment_due_date)->startOfDay()
                : $purchaseDate->copy()->addDays($termsDays);
            $daysOverdue = $dueDate->lt($asOf) ? (int) $dueDate->diffInDays($asOf) : 0;
            $daysUntilDue = $dueDate->gt($asOf) ? (int) $asOf->diffInDays($dueDate) : 0;

            return [
                'purchase_id' => (int) $purchase->id,
                'purchase_reference' => $purchase->Ref,
                'purchase_date' => $purchaseDate->toDateString(),
                'supplier_id' => (int) $purchase->provider_id,
                'supplier_name' => $purchase->supplier_name,
                'invoice_age_days' => (int) $purchaseDate->diffInDays($asOf),
                'payment_terms_days' => $termsDays,
                'due_date' => $dueDate->toDateString(),
                'days_overdue' => $daysOverdue,
                'days_until_due' => $daysUntilDue,
                'outstanding' => $outstanding,
                'due_in_month' => $dueDate->betweenIncluded($monthStart, $monthEnd),
                'overdue_threshold_reached' => $daysOverdue >= $overdueDays,
                'status' => $this->statusLabel($dueDate, $asOf, $daysOverdue),
            ];
        })->filter()->values();

        $detailsBySupplier = $details->groupBy('supplier_id');
        $supplierIds = $detailsBySupplier->keys()->merge($collectionBySupplier->keys())->unique()->values();
        $supplierNames = Provider::whereNull('deleted_at')->whereIn('id', $supplierIds)->pluck('name', 'id');

        $rows = $supplierIds->map(function ($providerId) use ($detailsBySupplier, $collectionBySupplier, $supplierNames) {
            $supplierDetails = $detailsBySupplier->get($providerId, collect())->values();

            return [
                'supplier_id' => (int) $providerId,
                'supplier_name' => $supplierNames->get($providerId, 'Unknown supplier'),
                'total_balance' => round((float) $supplierDetails->sum('outstanding'), 2),
                'due_payments' => round((float) $supplierDetails->where('due_in_month', true)->sum('outstanding'), 2),
                'month_collection' => round((float) ($collectionBySupplier->get($providerId)->amount ?? 0), 2),
                'overdue_amount' => round((float) $supplierDetails->where('overdue_threshold_reached', true)->sum('outstanding'), 2),
                'details' => $supplierDetails,
            ];
        })->sortBy(fn ($row) => mb_strtolower($row['supplier_name']))->values();

        return [
            'month' => $monthStart->format('Y-m'),
            'month_name' => $monthStart->format('F Y'),
            'as_of' => $asOf->toDateString(),
            'overdue_days' => $overdueDays,
            'currency' => strtoupper((new helpers)->Get_Currency_Code()),
            'rows' => $rows,
            'totals' => [
                'total_balance' => round((float) $rows->sum('total_balance'), 2),
                'due_payments' => round((float) $rows->sum('due_payments'), 2),
                'month_collection' => round((float) $rows->sum('month_collection'), 2),
                'overdue_amount' => round((float) $rows->sum('overdue_amount'), 2),
            ],
            'suppliers' => Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'branches' => Warehouse::whereNull('deleted_at')
                ->whereIn('id', $accessibleWarehouseIds)->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function monthlyCollections(
        Collection $accessibleWarehouseIds,
        Carbon $monthStart,
        Carbon $monthEnd,
        Carbon $asOf,
        ?int $supplierId,
        ?int $warehouseId
    ): Collection {
        $collectionEnd = $asOf->lt($monthEnd) ? $asOf : $monthEnd;
        if ($collectionEnd->lt($monthStart)) {
            return collect();
        }

        return DB::table('payment_purchases')
            ->join('purchases', 'purchases.id', '=', 'payment_purchases.purchase_id')
            ->whereNull('payment_purchases.deleted_at')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.statut', 'received')
            ->whereBetween('payment_purchases.date', [$monthStart->toDateString(), $collectionEnd->toDateString()])
            ->when($supplierId, fn ($query) => $query->where('purchases.provider_id', $supplierId))
            ->when($warehouseId, fn ($query) => $query->where('purchases.warehouse_id', $warehouseId))
            ->when(! $warehouseId, fn ($query) => $query->whereIn('purchases.warehouse_id', $accessibleWarehouseIds))
            ->groupBy('purchases.provider_id')
            ->selectRaw('purchases.provider_id, SUM(payment_purchases.montant) as amount')
            ->get()->keyBy('provider_id');
    }

    private function statusLabel(Carbon $dueDate, Carbon $asOf, int $daysOverdue): string
    {
        if ($daysOverdue > 0) {
            return 'Overdue by '.$daysOverdue.' '.($daysOverdue === 1 ? 'day' : 'days');
        }
        if ($dueDate->isSameDay($asOf)) {
            return 'Due today';
        }

        return 'Due date '.$dueDate->format('d-M-Y');
    }
}
