<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            // Legacy parent tables use signed INT primary keys in this project.
            // These columns must match exactly or MySQL rejects the foreign keys.
            $table->integer('shipment_id');
            $table->integer('sale_detail_id');
            $table->integer('shipped_by');
            $table->decimal('item_total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            $table->decimal('credit_amount', 15, 2)->default(0);
            $table->timestamp('shipped_at');
            $table->timestamps();

            $table->unique('sale_detail_id', 'shipment_items_sale_detail_unique');
            $table->index('shipment_id', 'shipment_items_shipment_index');
            $table->foreign('shipment_id', 'shipment_items_shipment_fk')
                ->references('id')->on('shipments')->onUpdate('RESTRICT')->onDelete('RESTRICT');
            $table->foreign('sale_detail_id', 'shipment_items_sale_detail_fk')
                ->references('id')->on('sale_details')->onUpdate('RESTRICT')->onDelete('RESTRICT');
            $table->foreign('shipped_by', 'shipment_items_user_fk')
                ->references('id')->on('users')->onUpdate('RESTRICT')->onDelete('RESTRICT');
        });

        // Existing terminal shipment headers represented the whole sale. Record their
        // details as shipped so the new item workflow cannot ship them a second time.
        $now = now();
        DB::table('shipments')
            ->whereNull('shipments.deleted_at')
            ->whereIn('shipments.status', ['shipped', 'delivered'])
            ->join('sale_details', function ($join) {
                $join->on('sale_details.sale_id', '=', 'shipments.sale_id');
            })
            ->select([
                'shipments.id as shipment_id',
                'shipments.user_id as shipped_by',
                'shipments.date as shipped_at',
                'sale_details.id as sale_detail_id',
                'sale_details.total as item_total',
            ])
            ->orderBy('sale_details.id')
            ->chunk(500, function ($rows) use ($now) {
                $records = [];
                foreach ($rows as $row) {
                    $records[] = [
                        'shipment_id' => $row->shipment_id,
                        'sale_detail_id' => $row->sale_detail_id,
                        'shipped_by' => $row->shipped_by,
                        'item_total' => round((float) $row->item_total, 2),
                        'paid_amount' => 0,
                        'outstanding_amount' => 0,
                        'credit_amount' => 0,
                        'shipped_at' => $row->shipped_at ?: $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($records) {
                    DB::table('shipment_items')->insertOrIgnore($records);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
    }
};
