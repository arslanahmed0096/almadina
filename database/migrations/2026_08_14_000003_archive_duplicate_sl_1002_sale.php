<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DUPLICATE_REF = 'SL_1002';

    private const CANONICAL_REF = 'SL_1053';

    private const PRODUCT_CODE = 'AME-MIC-260130';

    public function up(): void
    {
        DB::transaction(function () {
            $duplicate = DB::table('sales')
                ->where('Ref', self::DUPLICATE_REF)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            // The correction was already applied, or this installation never had
            // the duplicate sale.
            if (! $duplicate) {
                return;
            }

            $canonical = DB::table('sales')
                ->where('Ref', self::CANONICAL_REF)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if (! $canonical) {
                throw new RuntimeException('SL_1002 correction stopped because active SL_1053 was not found.');
            }

            if ((int) $duplicate->client_id !== (int) $canonical->client_id
                || (int) $duplicate->warehouse_id !== (int) $canonical->warehouse_id
                || round((float) $duplicate->GrandTotal, 2) !== 15500.00
                || round((float) $duplicate->paid_amount, 2) !== 15500.00
                || strtolower((string) $duplicate->statut) !== 'completed') {
                throw new RuntimeException('SL_1002 correction stopped because the active sale does not match the confirmed duplicate.');
            }

            $details = DB::table('sale_details as d')
                ->join('products as p', 'p.id', '=', 'd.product_id')
                ->where('d.sale_id', $duplicate->id)
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
                ]);

            if ($details->count() !== 1
                || $details->first()->code !== self::PRODUCT_CODE
                || round((float) $details->first()->quantity, 3) !== 1.000
                || round((float) $details->first()->total, 2) !== 15500.00) {
                throw new RuntimeException('SL_1002 correction stopped because its sale item does not match the confirmed duplicate.');
            }

            $canonicalHasProduct = DB::table('sale_details as d')
                ->join('products as p', 'p.id', '=', 'd.product_id')
                ->where('d.sale_id', $canonical->id)
                ->where('p.code', self::PRODUCT_CODE)
                ->exists();
            if (! $canonicalHasProduct) {
                throw new RuntimeException('SL_1002 correction stopped because the microwave is missing from SL_1053.');
            }

            if (DB::table('sale_returns')->where('sale_id', $duplicate->id)->whereNull('deleted_at')->exists()) {
                throw new RuntimeException('SL_1002 correction stopped because the duplicate sale has an active return.');
            }
            if (DB::table('shipments')->where('sale_id', $duplicate->id)->whereNull('deleted_at')->exists()) {
                throw new RuntimeException('SL_1002 correction stopped because the duplicate sale has an active shipment.');
            }
            if (! empty($duplicate->quickbooks_invoice_id)) {
                throw new RuntimeException('SL_1002 correction stopped because its QuickBooks invoice must be removed first.');
            }

            $detail = $details->first();
            $this->restoreInventory($duplicate, $detail);

            $now = now();
            if (Schema::hasTable('payment_sales')) {
                $payments = DB::table('payment_sales')
                    ->where('sale_id', $duplicate->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($payments as $payment) {
                    if ($payment->account_id) {
                        DB::table('accounts')
                            ->where('id', $payment->account_id)
                            ->decrement('balance', (float) $payment->montant, ['updated_at' => $now]);
                    }
                }

                DB::table('payment_sales')
                    ->where('sale_id', $duplicate->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            DB::table('sales')->where('id', $duplicate->id)->update([
                'deleted_at' => $now,
                'shipping_status' => null,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * This one-way business correction must not recreate a confirmed duplicate sale.
     */
    public function down(): void
    {
        // Intentionally not reversible.
    }

    private function restoreInventory(object $sale, object $detail): void
    {
        if ($detail->type === 'is_service') {
            return;
        }
        if ((bool) $detail->is_batch_tracked) {
            throw new RuntimeException('SL_1002 correction stopped because its product is batch tracked.');
        }

        $unitId = $detail->sale_unit_id ?: $detail->unit_sale_id;
        $unit = DB::table('units')->where('id', $unitId)->first();
        if (! $unit || (float) $unit->operator_value <= 0) {
            throw new RuntimeException('SL_1002 correction stopped because its sale unit is invalid.');
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
            throw new RuntimeException('SL_1002 correction stopped because its warehouse stock row is missing.');
        }

        $stockQuery->update([
            'qte' => (float) $stock->qte + $baseQuantity,
            'updated_at' => now(),
        ]);
    }
};
