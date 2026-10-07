<?php

namespace App\Services\Targets;

use App\Models\Product;
use App\Models\SupplierTarget;
use App\Models\SupplierTargetLine;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TargetAllocationService
{
    public function equally(float $total, int $warehouseCount, int $precision = 3): array
    {
        if ($total < 0 || $warehouseCount < 1) {
            throw new InvalidArgumentException('A non-negative total and at least one warehouse are required.');
        }
        $factor = 10 ** $precision;
        $minorTotal = (int) round($total * $factor);
        $each = intdiv($minorTotal, $warehouseCount);
        $remainder = $minorTotal - ($each * $warehouseCount);

        return array_map(fn ($index) => (float) (($each + ($index < $remainder ? 1 : 0)) / $factor), range(0, $warehouseCount - 1));
    }

    public function weighted(float $total, array $weights, int $precision = 3): array
    {
        if ($total < 0 || $weights === [] || collect($weights)->contains(fn ($weight) => (float) $weight < 0)) {
            throw new InvalidArgumentException('A non-negative total and non-negative branch weights are required.');
        }
        $weights = array_values(array_map('floatval', $weights));
        $weightTotal = array_sum($weights);
        if ($weightTotal <= 0) {
            return $this->equally($total, count($weights), $precision);
        }

        $factor = 10 ** $precision;
        $minorTotal = (int) round($total * $factor);
        $raw = array_map(fn ($weight) => $minorTotal * $weight / $weightTotal, $weights);
        $minor = array_map(fn ($share) => (int) floor($share), $raw);
        $remainder = $minorTotal - array_sum($minor);
        $order = array_keys($weights);
        usort($order, function ($left, $right) use ($raw) {
            $fraction = ($raw[$right] - floor($raw[$right])) <=> ($raw[$left] - floor($raw[$left]));

            return $fraction !== 0 ? $fraction : $left <=> $right;
        });
        for ($index = 0; $index < $remainder; $index++) {
            $minor[$order[$index]]++;
        }

        return array_map(fn ($share) => (float) ($share / $factor), $minor);
    }

    public function byHistoricalSales(SupplierTarget $target, Collection $warehouseIds): array
    {
        $warehouseIds = $warehouseIds->map(fn ($id) => (int) $id)->unique()->values();
        if ($warehouseIds->isEmpty()) {
            throw new InvalidArgumentException('At least one accessible branch is required.');
        }
        $target->loadMissing('lines');
        $targetStart = Carbon::parse($target->start_date);
        $targetEnd = Carbon::parse($target->end_date);
        $periodDays = (int) $targetStart->diffInDays($targetEnd) + 1;
        $historyEnd = $targetStart->copy()->subDay();
        $historyStart = $historyEnd->copy()->subDays($periodDays - 1);
        $allocations = collect();
        $fallbackLineIds = [];

        foreach ($target->lines as $line) {
            $quantities = $this->historicalNetQuantities(
                $line,
                $warehouseIds,
                $historyStart->format('Y-m-d'),
                $historyEnd->format('Y-m-d')
            );
            $weightTotal = (float) $quantities->sum();
            if ($weightTotal <= 0) {
                $fallbackLineIds[] = (int) $line->id;
            }
            $shares = $this->weighted((float) $line->target_quantity, $quantities->values()->all());
            foreach ($warehouseIds as $index => $warehouseId) {
                $historicalQuantity = (float) $quantities->get($warehouseId, 0);
                $allocations->push([
                    'supplier_target_line_id' => (int) $line->id,
                    'warehouse_id' => (int) $warehouseId,
                    'allocated_quantity' => $shares[$index],
                    'historical_quantity' => round($historicalQuantity, 3),
                    'historical_share' => $weightTotal > 0 ? round($historicalQuantity / $weightTotal * 100, 1) : 0,
                ]);
            }
        }

        return [
            'method' => 'sales_weighted',
            'history_start' => $historyStart->format('Y-m-d'),
            'history_end' => $historyEnd->format('Y-m-d'),
            'fallback_line_ids' => $fallbackLineIds,
            'line_allocations' => $allocations->values(),
        ];
    }

    private function historicalNetQuantities(SupplierTargetLine $line, Collection $warehouseIds, string $start, string $end): Collection
    {
        $productIds = $line->product_id
            ? collect([(int) $line->product_id])
            : Product::whereNull('deleted_at')->where(function ($query) use ($line) {
                $query->where('category_id', $line->category_id)
                    ->orWhereHas('categories', fn ($q) => $q->where('categories.id', $line->category_id));
            })->pluck('id')->map(fn ($id) => (int) $id);
        $quantities = $warehouseIds->mapWithKeys(fn ($warehouseId) => [(int) $warehouseId => 0.0]);
        if ($productIds->isEmpty()) {
            return $quantities;
        }

        $sales = DB::table('sale_details as sd')
            ->join('sales as s', 's.id', '=', 'sd.sale_id')
            ->leftJoin('shipment_items as shi', 'shi.sale_detail_id', '=', 'sd.id')
            ->whereNull('s.deleted_at')
            ->whereIn('s.warehouse_id', $warehouseIds)
            ->whereIn('sd.product_id', $productIds)
            ->where(function ($query) use ($start, $end) {
                $query->where(function ($completed) use ($start, $end) {
                    $completed->where('s.statut', 'completed')->whereBetween('s.date', [$start, $end]);
                })->orWhere(function ($shipped) use ($start, $end) {
                    $shipped->where('s.statut', 'ordered')->whereNotNull('shi.id')
                        ->whereBetween(DB::raw('DATE(shi.shipped_at)'), [$start, $end]);
                });
            })
            ->select('sd.id', 's.warehouse_id', 'sd.quantity')->distinct()->get();
        foreach ($sales as $sale) {
            $warehouseId = (int) $sale->warehouse_id;
            $quantities[$warehouseId] = (float) $quantities[$warehouseId] + (float) $sale->quantity;
        }

        $returns = DB::table('sale_return_details as srd')
            ->join('sale_returns as sr', 'sr.id', '=', 'srd.sale_return_id')
            ->whereNull('sr.deleted_at')->where('sr.statut', 'completed')
            ->whereBetween('sr.date', [$start, $end])
            ->whereIn('sr.warehouse_id', $warehouseIds)
            ->whereIn('srd.product_id', $productIds)
            ->select('sr.warehouse_id', 'srd.quantity')->get();
        foreach ($returns as $return) {
            $warehouseId = (int) $return->warehouse_id;
            $quantities[$warehouseId] = (float) $quantities[$warehouseId] - (float) $return->quantity;
        }

        return $quantities->map(fn ($quantity) => max(0, round((float) $quantity, 3)));
    }
}
