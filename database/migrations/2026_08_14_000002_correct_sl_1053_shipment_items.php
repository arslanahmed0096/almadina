<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SALE_REF = 'SL_1053';

    private const DELIVERED_PRODUCT_CODE = 'AME-MIC-260130';

    private const NOT_DELIVERED_PRODUCT_CODES = [
        'AME-WAS-260048',
        'AME-REF-260049',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('shipment_items')) {
            return;
        }

        DB::transaction(function () {
            $sale = DB::table('sales')
                ->where('Ref', self::SALE_REF)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $sale) {
                return;
            }

            $details = DB::table('sale_details as d')
                ->join('products as p', 'p.id', '=', 'd.product_id')
                ->where('d.sale_id', $sale->id)
                ->whereIn('p.code', array_merge(self::NOT_DELIVERED_PRODUCT_CODES, [self::DELIVERED_PRODUCT_CODE]))
                ->orderBy('d.id')
                ->lockForUpdate()
                ->get([
                    'd.id',
                    'd.product_id',
                    'd.product_variant_id',
                    'd.sale_unit_id',
                    'd.quantity',
                    'd.total',
                    'p.code',
                    'p.type',
                    'p.unit_sale_id',
                    'p.is_batch_tracked',
                ])
                ->keyBy('code');

            $requiredCodes = collect(self::NOT_DELIVERED_PRODUCT_CODES)
                ->push(self::DELIVERED_PRODUCT_CODE);
            if ($requiredCodes->contains(fn ($code) => ! $details->has($code))) {
                throw new RuntimeException('SL_1053 shipment correction stopped because an expected sale item is missing.');
            }

            $shipment = DB::table('shipments')
                ->where('sale_id', $sale->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $shipment) {
                throw new RuntimeException('SL_1053 shipment correction stopped because its shipment header is missing.');
            }

            $shipmentItems = DB::table('shipment_items')
                ->where('shipment_id', $shipment->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($item) => (int) $item->sale_detail_id);

            $sourceItem = null;
            foreach (self::NOT_DELIVERED_PRODUCT_CODES as $code) {
                $detail = $details->get($code);
                $item = $shipmentItems->get((int) $detail->id);
                if (! $item) {
                    continue;
                }

                $sourceItem ??= $item;
                $this->adjustInventory($sale, $detail, 1);
                DB::table('shipment_items')->where('id', $item->id)->delete();
            }

            $deliveredDetail = $details->get(self::DELIVERED_PRODUCT_CODE);
            $deliveredItemExists = DB::table('shipment_items')
                ->where('sale_detail_id', $deliveredDetail->id)
                ->exists();

            if (! $deliveredItemExists) {
                $this->adjustInventory($sale, $deliveredDetail, -1);
                $allocation = $this->fifoPaymentAllocation((int) $sale->id, (int) $deliveredDetail->id, $sale->paid_amount);
                $shippedAt = $sourceItem->shipped_at
                    ?? $shipment->created_at
                    ?? now();

                DB::table('shipment_items')->insert([
                    'shipment_id' => $shipment->id,
                    'sale_detail_id' => $deliveredDetail->id,
                    'shipped_by' => $sourceItem->shipped_by ?? $shipment->user_id,
                    'delivery_method' => $sourceItem->delivery_method ?? $shipment->delivery_method ?? 'self_delivery',
                    'driver_name' => $sourceItem->driver_name ?? $shipment->driver_name,
                    'item_total' => round((float) $deliveredDetail->total, 2),
                    'paid_amount' => $allocation['paid'],
                    'outstanding_amount' => $allocation['outstanding'],
                    'credit_amount' => 0,
                    'shipped_at' => $shippedAt,
                    'created_at' => $shippedAt,
                    'updated_at' => now(),
                ]);
            }

            $totalCount = DB::table('sale_details')->where('sale_id', $sale->id)->count();
            $shippedCount = DB::table('shipment_items')
                ->join('sale_details', 'sale_details.id', '=', 'shipment_items.sale_detail_id')
                ->where('sale_details.sale_id', $sale->id)
                ->count();
            $allShipped = $totalCount > 0 && $shippedCount === $totalCount;

            DB::table('shipments')->where('id', $shipment->id)->update([
                'status' => $allShipped ? 'shipped' : 'ordered',
                'updated_at' => now(),
            ]);
            DB::table('sales')->where('id', $sale->id)->update([
                'statut' => $allShipped ? 'completed' : 'ordered',
                'shipping_status' => $allShipped ? 'shipped' : 'ordered',
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * This is a one-way correction of confirmed physical delivery facts.
     * Rolling it back must not mark undelivered products as delivered again.
     */
    public function down(): void
    {
        // Intentionally not reversible.
    }

    private function adjustInventory(object $sale, object $detail, int $direction): void
    {
        if ($detail->type === 'is_service') {
            return;
        }
        if ((bool) $detail->is_batch_tracked) {
            throw new RuntimeException('SL_1053 shipment correction stopped because an affected product is batch tracked.');
        }

        $unitId = $detail->sale_unit_id ?: $detail->unit_sale_id;
        $unit = DB::table('units')->where('id', $unitId)->first();
        if (! $unit || (float) $unit->operator_value <= 0) {
            throw new RuntimeException('SL_1053 shipment correction stopped because an affected sale unit is invalid.');
        }

        $baseQuantity = $unit->operator === '/'
            ? (float) $detail->quantity / (float) $unit->operator_value
            : (float) $detail->quantity * (float) $unit->operator_value;

        $stockQuery = DB::table('product_warehouse')
            ->whereNull('deleted_at')
            ->where('warehouse_id', $sale->warehouse_id)
            ->where('product_id', $detail->product_id)
            ->when(
                $detail->product_variant_id,
                fn ($query) => $query->where('product_variant_id', $detail->product_variant_id),
                fn ($query) => $query->whereNull('product_variant_id')
            );
        $stock = (clone $stockQuery)->lockForUpdate()->first();
        if (! $stock) {
            throw new RuntimeException('SL_1053 shipment correction stopped because an affected warehouse stock row is missing.');
        }

        $newQuantity = (float) $stock->qte + ($direction * $baseQuantity);
        if ($newQuantity < 0) {
            throw new RuntimeException('SL_1053 shipment correction stopped because it would create negative stock.');
        }

        $stockQuery->update([
            'qte' => $newQuantity,
            'updated_at' => now(),
        ]);
    }

    /** @return array{paid: float, outstanding: float} */
    private function fifoPaymentAllocation(int $saleId, int $targetDetailId, $salePaidAmount): array
    {
        $remainingCents = (int) round((float) $salePaidAmount * 100);
        $details = DB::table('sale_details')
            ->where('sale_id', $saleId)
            ->orderBy('id')
            ->get(['id', 'total']);

        foreach ($details as $detail) {
            $totalCents = max(0, (int) round((float) $detail->total * 100));
            $paidCents = min($totalCents, $remainingCents);
            $remainingCents -= $paidCents;

            if ((int) $detail->id === $targetDetailId) {
                return [
                    'paid' => round($paidCents / 100, 2),
                    'outstanding' => round(($totalCents - $paidCents) / 100, 2),
                ];
            }
        }

        throw new RuntimeException('SL_1053 shipment correction stopped because the delivered sale item is missing.');
    }
};
