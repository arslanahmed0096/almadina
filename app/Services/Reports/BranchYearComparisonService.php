<?php

namespace App\Services\Reports;

use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BranchYearComparisonService
{
    public function generate($user, int $baselineYear, int $comparisonYear): array
    {
        $hasAllWarehouses = $user->isSuperAdmin() || (bool) $user->is_all_warehouses;
        $accessibleWarehouseIds = $hasAllWarehouses
            ? Warehouse::whereNull('deleted_at')->pluck('id')
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');

        $warehouses = Warehouse::whereNull('deleted_at')
            ->whereIn('id', $accessibleWarehouseIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $yearExpression = $this->yearExpression('sales.date');
        $monthExpression = $this->monthExpression('sales.date');
        $sales = DB::table('sales')
            ->whereNull('sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->whereIn('sales.warehouse_id', $accessibleWarehouseIds)
            ->whereIn(DB::raw($yearExpression), [$baselineYear, $comparisonYear])
            ->groupBy('sales.warehouse_id', DB::raw($yearExpression), DB::raw($monthExpression))
            ->selectRaw("sales.warehouse_id, {$yearExpression} as report_year, {$monthExpression} as report_month, SUM(sales.GrandTotal) as total")
            ->get()
            ->keyBy(fn ($row) => ((int) $row->warehouse_id).'-'.((int) $row->report_year).'-'.((int) $row->report_month));

        $rows = $warehouses->map(function (Warehouse $warehouse) use ($sales, $baselineYear, $comparisonYear) {
            $baselineTotal = round((float) collect(range(1, 12))->sum(
                fn (int $month) => (float) ($sales[$warehouse->id.'-'.$baselineYear.'-'.$month]->total ?? 0)
            ), 2);
            $comparisonTotal = round((float) collect(range(1, 12))->sum(
                fn (int $month) => (float) ($sales[$warehouse->id.'-'.$comparisonYear.'-'.$month]->total ?? 0)
            ), 2);

            return $this->comparisonRow((int) $warehouse->id, $warehouse->name, $baselineTotal, $comparisonTotal);
        });

        $baselineTotal = round((float) $rows->sum('baseline_total'), 2);
        $comparisonTotal = round((float) $rows->sum('comparison_total'), 2);
        $totals = $this->comparisonRow(null, 'GRAND TOTAL', $baselineTotal, $comparisonTotal);

        $now = Carbon::now();
        $months = collect(range(1, 12))->map(function (int $month) use ($warehouses, $sales, $baselineYear, $comparisonYear, $now) {
            $baselineAvailable = $this->monthIsAvailable($baselineYear, $month, $now);
            $comparisonAvailable = $this->monthIsAvailable($comparisonYear, $month, $now);

            return [
                'month_number' => $month,
                'month' => Carbon::create(2000, $month, 1)->format('F'),
                'rows' => $warehouses->map(function (Warehouse $warehouse) use ($sales, $baselineYear, $comparisonYear, $month, $baselineAvailable, $comparisonAvailable) {
                    $baselineTotal = $baselineAvailable
                        ? round((float) ($sales[$warehouse->id.'-'.$baselineYear.'-'.$month]->total ?? 0), 2)
                        : null;
                    $comparisonTotal = $comparisonAvailable
                        ? round((float) ($sales[$warehouse->id.'-'.$comparisonYear.'-'.$month]->total ?? 0), 2)
                        : null;

                    return $this->comparisonRow((int) $warehouse->id, $warehouse->name, $baselineTotal, $comparisonTotal);
                })->values(),
            ];
        });

        return [
            'rows' => $rows->values(),
            'months' => $months->values(),
            'totals' => $totals,
            'baseline_year' => $baselineYear,
            'comparison_year' => $comparisonYear,
            'currency' => strtoupper((new helpers)->Get_Currency_Code()),
            'comparison_through' => $comparisonYear === (int) $now->year ? $now->format('F j, Y') : null,
        ];
    }

    private function comparisonRow(?int $warehouseId, string $branch, ?float $baselineTotal, ?float $comparisonTotal): array
    {
        $baselineValue = $baselineTotal ?? 0.0;
        $comparisonValue = $comparisonTotal ?? 0.0;
        $difference = round($comparisonValue - $baselineValue, 2);

        return [
            'warehouse_id' => $warehouseId,
            'branch' => $branch,
            'baseline_total' => $baselineTotal,
            'comparison_total' => $comparisonTotal,
            'difference' => $difference,
            'change_percent' => $baselineValue > 0
                ? round(($difference / $baselineValue) * 100, 2)
                : 0.0,
            'trend' => $difference > 0 ? 'Up' : ($difference < 0 ? 'Down' : 'Flat'),
        ];
    }

    private function yearExpression(string $column): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(strftime('%Y', {$column}) AS INTEGER)";
        }

        return "YEAR({$column})";
    }

    private function monthExpression(string $column): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(strftime('%m', {$column}) AS INTEGER)";
        }

        return "MONTH({$column})";
    }

    private function monthIsAvailable(int $year, int $month, Carbon $now): bool
    {
        return $year < (int) $now->year || ($year === (int) $now->year && $month <= (int) $now->month);
    }
}
