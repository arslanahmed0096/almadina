<?php

namespace App\Services\Targets;

use App\Models\Product;
use App\Models\SupplierTarget;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TargetAchievementService
{
    public function productIds(SupplierTarget $target): Collection
    {
        $target->loadMissing('lines');
        $direct = $target->lines->pluck('product_id')->filter()->map(fn ($id) => (int) $id);
        $categories = $target->lines->pluck('category_id')->filter()->map(fn ($id) => (int) $id);
        $categoryProducts = $categories->isEmpty() ? collect() : Product::whereNull('deleted_at')
            ->where(function ($query) use ($categories) {
                $query->whereIn('category_id', $categories)
                    ->orWhereHas('categories', fn ($q) => $q->whereIn('categories.id', $categories));
            })->pluck('id')->map(fn ($id) => (int) $id);

        return $direct->merge($categoryProducts)->unique()->values();
    }

    public function calculate(SupplierTarget $target, ?array $warehouseScope = null): array
    {
        $target->loadMissing(['lines.product', 'lines.category', 'allocations.warehouse']);
        $warehouses = $target->allocations->pluck('warehouse_id')->map(fn ($id) => (int) $id);
        if ($warehouseScope !== null) {
            $warehouses = $warehouses->intersect(array_map('intval', $warehouseScope));
        }
        $products = $this->productIds($target);
        $sales = collect();
        $returns = collect();
        if ($warehouses->isNotEmpty() && $products->isNotEmpty()) {
            $sales = DB::table('sale_details as sd')
                ->join('sales as s', 's.id', '=', 'sd.sale_id')
                ->leftJoin('shipment_items as shi', 'shi.sale_detail_id', '=', 'sd.id')
                ->whereNull('s.deleted_at')
                ->whereIn('s.warehouse_id', $warehouses)
                ->whereIn('sd.product_id', $products)
                ->where(function ($query) use ($target) {
                    $query->where(function ($q) use ($target) {
                        $q->where('s.statut', 'completed')
                            ->whereBetween('s.date', [$target->start_date, $target->end_date]);
                    })->orWhere(function ($q) use ($target) {
                        $q->where('s.statut', 'ordered')->whereNotNull('shi.id')
                            ->whereBetween(DB::raw('DATE(shi.shipped_at)'), [$target->start_date, $target->end_date]);
                    });
                })
                ->select('sd.id', 'sd.product_id', 's.warehouse_id', 'sd.quantity',
                    DB::raw('CASE WHEN s.statut = \'ordered\' THEN DATE(shi.shipped_at) ELSE s.date END as activity_date'))
                ->distinct()->get();
            $returns = DB::table('sale_return_details as srd')
                ->join('sale_returns as sr', 'sr.id', '=', 'srd.sale_return_id')
                ->leftJoin('sales as os', 'os.id', '=', 'sr.sale_id')
                ->leftJoin('shipment_items as oshi', 'oshi.sale_detail_id', '=', 'srd.sale_detail_id')
                ->whereNull('sr.deleted_at')->where('sr.statut', 'completed')
                ->whereIn('sr.warehouse_id', $warehouses)->whereIn('srd.product_id', $products)
                ->where(function ($query) use ($target) {
                    $query->where(function ($q) use ($target) {
                        $q->where('os.statut', 'completed')
                            ->whereBetween('os.date', [$target->start_date, $target->end_date]);
                    })->orWhere(function ($q) use ($target) {
                        $q->where('os.statut', 'ordered')->whereNotNull('oshi.id')
                            ->whereBetween(DB::raw('DATE(oshi.shipped_at)'), [$target->start_date, $target->end_date]);
                    });
                })->select('srd.product_id', 'sr.warehouse_id', 'srd.quantity',
                    DB::raw('CASE WHEN os.statut = \'ordered\' THEN DATE(oshi.shipped_at) ELSE os.date END as activity_date'))->get();
        }
        $net = $sales->map(fn ($row) => [
            'product_id' => (int) $row->product_id, 'warehouse_id' => (int) $row->warehouse_id,
            'quantity' => (float) $row->quantity, 'date' => (string) $row->activity_date,
        ])->merge($returns->map(fn ($row) => [
            'product_id' => (int) $row->product_id, 'warehouse_id' => (int) $row->warehouse_id,
            'quantity' => -(float) $row->quantity, 'date' => (string) $row->activity_date,
        ]));

        return $this->present($target, $warehouses, $net);
    }

    private function present(SupplierTarget $target, Collection $warehouses, Collection $net): array
    {
        $targetTotal = round((float) $target->lines->sum('target_quantity'), 3);
        $achieved = round(max(0, (float) $net->sum('quantity')), 3);
        $percentage = $targetTotal > 0 ? round($achieved / $targetTotal * 100, 1) : 0;
        $expected = $this->expectedPercentage($target);
        $warehouseRows = $target->allocations
            ->filter(fn ($allocation) => $warehouses->contains((int) $allocation->warehouse_id))
            ->map(function ($allocation) use ($net) {
                $value = max(0, (float) $net->where('warehouse_id', (int) $allocation->warehouse_id)->sum('quantity'));

                return $this->row((int) $allocation->warehouse_id, $allocation->warehouse->name, (float) $allocation->allocated_quantity, $value);
            })->values();
        $lineRows = $target->lines->map(function ($line) use ($net, $target) {
            $ids = $line->product_id ? collect([(int) $line->product_id]) : Product::whereNull('deleted_at')
                ->where(function ($query) use ($line) {
                    $query->where('category_id', $line->category_id)
                        ->orWhereHas('categories', fn ($q) => $q->where('categories.id', $line->category_id));
                })->pluck('id')->map(fn ($id) => (int) $id);
            $value = max(0, (float) $net->whereIn('product_id', $ids)->sum('quantity'));
            $name = $line->product_id ? optional($line->product)->name : optional($line->category)->name;

            return $this->row((int) $line->id, $name ?: 'Deleted item', (float) $line->target_quantity, $value, $this->status($value, (float) $line->target_quantity, $this->expectedPercentage($target)));
        })->values();

        return [
            'target' => $targetTotal, 'achieved' => $achieved,
            'remaining' => round(max($targetTotal - $achieved, 0), 3),
            'percentage' => $percentage, 'status' => $this->status($achieved, $targetTotal, $expected),
            'expected_percentage' => $expected, 'monthly' => $this->chart($target, $net, $targetTotal),
            'warehouses' => $warehouseRows, 'lines' => $lineRows,
        ];
    }

    private function row(int $id, string $name, float $target, float $achieved, ?string $status = null): array
    {
        $percentage = $target > 0 ? round($achieved / $target * 100, 1) : 0;

        return [
            'id' => $id, 'name' => $name, 'target' => round($target, 3), 'achieved' => round($achieved, 3),
            'remaining' => round(max($target - $achieved, 0), 3), 'percentage' => $percentage,
            'status' => $status ?: ($percentage >= 100 ? 'Achieved' : 'On Track'),
        ];
    }

    private function chart(SupplierTarget $target, Collection $net, float $total): Collection
    {
        $periods = [];
        if ($target->period_type === 'annual') {
            $year = Carbon::parse($target->start_date)->year;
            for ($month = 1; $month <= 12; $month++) {
                $periods[] = ['label' => Carbon::create(null, $month)->format('M'), 'start' => sprintf('%04d-%02d-01', $year, $month), 'end' => Carbon::create($year, $month)->endOfMonth()->format('Y-m-d')];
            }
        } else {
            $cursor = Carbon::parse($target->start_date)->startOfWeek();
            $end = Carbon::parse($target->end_date);
            while ($cursor->lte($end)) {
                $periods[] = ['label' => $cursor->format('M j'), 'start' => $cursor->format('Y-m-d'), 'end' => $cursor->copy()->endOfWeek()->format('Y-m-d')];
                $cursor->addWeek();
            }
        }
        $reference = count($periods) ? $total / count($periods) : 0;

        return collect($periods)->map(function ($period) use ($net, $reference) {
            $value = $net->filter(fn ($row) => $row['date'] >= $period['start'] && $row['date'] <= $period['end'])->sum('quantity');

            return ['label' => $period['label'], 'target' => round($reference, 3), 'achieved' => round(max(0, (float) $value), 3)];
        })->values();
    }

    private function expectedPercentage(SupplierTarget $target): float
    {
        $start = Carbon::parse($target->start_date)->startOfDay();
        $end = Carbon::parse($target->end_date)->endOfDay();
        if (now()->lte($start)) {
            return 0;
        }
        if (now()->gte($end)) {
            return 100;
        }

        return round($start->diffInSeconds(now()) / max(1, $start->diffInSeconds($end)) * 100, 1);
    }

    private function status(float $achieved, float $target, float $expected): string
    {
        $actual = $target > 0 ? $achieved / $target * 100 : 0;
        if ($actual >= 100) {
            return $actual > 100 ? 'Exceeded' : 'Achieved';
        }
        $ahead = (float) config('targets.status_thresholds.ahead_percentage_points', 10);
        $behind = (float) config('targets.status_thresholds.behind_percentage_points', 10);
        if ($actual >= $expected + $ahead) {
            return 'Ahead';
        }
        if ($actual < max(0, $expected - $behind)) {
            return 'Behind';
        }

        return 'On Track';
    }
}
