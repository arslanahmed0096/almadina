<?php

namespace App\Services\Reports;

use App\Models\Provider;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SupplierYearComparisonService
{
    public function generate($user, int $baselineYear, int $comparisonYear, ?int $supplierId, ?int $warehouseId): array
    {
        $hasAllWarehouses = $user->isSuperAdmin() || (bool) $user->is_all_warehouses;
        $accessibleWarehouseIds = $hasAllWarehouses
            ? Warehouse::whereNull('deleted_at')->pluck('id')
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');

        if ($warehouseId && ! $accessibleWarehouseIds->contains($warehouseId)) {
            abort(403, 'You do not have access to the selected branch.');
        }

        $supplier = $supplierId
            ? Provider::whereNull('deleted_at')->findOrFail($supplierId)
            : null;

        [$purchaseYear, $purchaseMonth] = $this->dateParts('purchases.date');
        [$paymentYear, $paymentMonth] = $this->dateParts('payment_purchases.date');
        $years = [$baselineYear, $comparisonYear];

        $purchases = DB::table('purchases')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.statut', 'received')
            ->whereIn(DB::raw($purchaseYear), $years)
            ->when($supplierId, fn ($query) => $query->where('purchases.provider_id', $supplierId))
            ->when($warehouseId, fn ($query) => $query->where('purchases.warehouse_id', $warehouseId))
            ->when(! $warehouseId, fn ($query) => $query->whereIn('purchases.warehouse_id', $accessibleWarehouseIds))
            ->groupBy(DB::raw($purchaseYear), DB::raw($purchaseMonth))
            ->selectRaw("{$purchaseYear} as report_year, {$purchaseMonth} as report_month, SUM(purchases.GrandTotal) as total")
            ->get()
            ->keyBy(fn ($row) => ((int) $row->report_year).'-'.((int) $row->report_month));

        $payments = DB::table('payment_purchases')
            ->join('purchases', 'purchases.id', '=', 'payment_purchases.purchase_id')
            ->whereNull('payment_purchases.deleted_at')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.statut', 'received')
            ->whereIn(DB::raw($paymentYear), $years)
            ->when($supplierId, fn ($query) => $query->where('purchases.provider_id', $supplierId))
            ->when($warehouseId, fn ($query) => $query->where('purchases.warehouse_id', $warehouseId))
            ->when(! $warehouseId, fn ($query) => $query->whereIn('purchases.warehouse_id', $accessibleWarehouseIds))
            ->groupBy(DB::raw($paymentYear), DB::raw($paymentMonth))
            ->selectRaw("{$paymentYear} as report_year, {$paymentMonth} as report_month, SUM(payment_purchases.montant) as total")
            ->get()
            ->keyBy(fn ($row) => ((int) $row->report_year).'-'.((int) $row->report_month));

        $now = Carbon::now();
        $rows = collect(range(1, 12))->map(function (int $month) use ($baselineYear, $comparisonYear, $purchases, $payments, $now) {
            $baselineAvailable = $this->monthIsAvailable($baselineYear, $month, $now);
            $comparisonAvailable = $this->monthIsAvailable($comparisonYear, $month, $now);
            $baselineSales = $baselineAvailable ? (float) ($purchases[$baselineYear.'-'.$month]->total ?? 0) : null;
            $baselinePayments = $baselineAvailable ? (float) ($payments[$baselineYear.'-'.$month]->total ?? 0) : null;
            $comparisonSales = $comparisonAvailable ? (float) ($purchases[$comparisonYear.'-'.$month]->total ?? 0) : null;
            $comparisonPayments = $comparisonAvailable ? (float) ($payments[$comparisonYear.'-'.$month]->total ?? 0) : null;
            $growthComparison = $comparisonSales ?? 0;

            return [
                'month_number' => $month,
                'month' => Carbon::create(2000, $month, 1)->format('F'),
                'baseline_sales' => $baselineSales === null ? null : round($baselineSales, 2),
                'baseline_payments' => $baselinePayments === null ? null : round($baselinePayments, 2),
                'baseline_variance' => $baselineSales === null ? null : round($baselineSales - $baselinePayments, 2),
                'comparison_sales' => $comparisonSales === null ? null : round($comparisonSales, 2),
                'comparison_payments' => $comparisonPayments === null ? null : round($comparisonPayments, 2),
                'comparison_variance' => $comparisonSales === null ? null : round($comparisonSales - $comparisonPayments, 2),
                'growth' => $baselineSales > 0
                    ? round((($growthComparison - $baselineSales) / $baselineSales) * 100, 2)
                    : ($growthComparison > 0 ? null : 0),
            ];
        });

        $sum = fn (string $key) => round((float) $rows->sum(fn ($row) => $row[$key] ?? 0), 2);
        $baselineTotal = $sum('baseline_sales');
        $comparisonTotal = $sum('comparison_sales');
        $totals = [
            'baseline_sales' => $baselineTotal,
            'baseline_payments' => $sum('baseline_payments'),
            'baseline_variance' => $sum('baseline_variance'),
            'comparison_sales' => $comparisonTotal,
            'comparison_payments' => $sum('comparison_payments'),
            'comparison_variance' => $sum('comparison_variance'),
            'growth' => $baselineTotal > 0
                ? round((($comparisonTotal - $baselineTotal) / $baselineTotal) * 100, 2)
                : ($comparisonTotal > 0 ? null : 0),
        ];

        return [
            'rows' => $rows->values(),
            'totals' => $totals,
            'baseline_year' => $baselineYear,
            'comparison_year' => $comparisonYear,
            'supplier' => $supplier ? ['id' => (int) $supplier->id, 'name' => $supplier->name] : null,
            'branch' => $warehouseId
                ? ['id' => $warehouseId, 'name' => Warehouse::findOrFail($warehouseId)->name]
                : null,
            'currency' => strtoupper((new helpers)->Get_Currency_Code()),
            'is_partial_comparison' => $comparisonYear >= (int) $now->year,
            'comparison_through' => $comparisonYear === (int) $now->year ? $now->format('F j, Y') : null,
            'suppliers' => Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'branches' => Warehouse::whereNull('deleted_at')->whereIn('id', $accessibleWarehouseIds)->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function monthIsAvailable(int $year, int $month, Carbon $now): bool
    {
        return $year < (int) $now->year || ($year === (int) $now->year && $month <= (int) $now->month);
    }

    private function dateParts(string $column): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return ["CAST(strftime('%Y', {$column}) AS INTEGER)", "CAST(strftime('%m', {$column}) AS INTEGER)"];
        }

        return ["YEAR({$column})", "MONTH({$column})"];
    }
}
