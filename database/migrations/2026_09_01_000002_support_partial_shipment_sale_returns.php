<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->integer('sale_detail_id')->nullable()->after('sale_return_id');
            $table->index('sale_detail_id', 'sale_return_details_sale_detail_index');
        });

        // Existing data did not retain the original sale line. Backfill only when
        // product/variant identifies exactly one line on the related sale.
        DB::table('sale_return_details as return_detail')
            ->join('sale_returns as sale_return', 'sale_return.id', '=', 'return_detail.sale_return_id')
            ->whereNull('return_detail.sale_detail_id')
            ->select([
                'return_detail.id',
                'return_detail.product_id',
                'return_detail.product_variant_id',
                'sale_return.sale_id',
            ])
            ->chunkById(250, function ($rows) {
                foreach ($rows as $row) {
                    if (! $row->sale_id) {
                        continue;
                    }

                    $matches = DB::table('sale_details')
                        ->where('sale_id', $row->sale_id)
                        ->where('product_id', $row->product_id)
                        ->when(
                            $row->product_variant_id,
                            fn ($query) => $query->where('product_variant_id', $row->product_variant_id),
                            fn ($query) => $query->whereNull('product_variant_id')
                        )
                        ->pluck('id');

                    if ($matches->count() === 1) {
                        DB::table('sale_return_details')
                            ->where('id', $row->id)
                            ->update(['sale_detail_id' => $matches->first()]);
                    }
                }
            }, 'return_detail.id', 'id');

        if (! DB::table('payment_methods')->whereRaw('LOWER(name) = ?', ['easypaisa'])->exists()) {
            DB::table('payment_methods')->insert([
                'name' => 'EasyPaisa',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->dropIndex('sale_return_details_sale_detail_index');
            $table->dropColumn('sale_detail_id');
        });
    }
};
