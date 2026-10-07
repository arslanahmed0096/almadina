<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('supplier_target_lines', 'further_discounts')) {
            Schema::table('supplier_target_lines', function (Blueprint $table) {
                $table->json('further_discounts')->nullable()->after('further_discount_per_unit');
            });
        }

        DB::table('supplier_target_lines')
            ->where('further_discount_per_unit', '>', 0)
            ->whereNull('further_discounts')
            ->orderBy('id')
            ->each(function ($line) {
                DB::table('supplier_target_lines')->where('id', $line->id)->update([
                    'further_discounts' => json_encode([[
                        'label' => 'Further Discount / Unit',
                        'type' => 'fixed',
                        'value' => (float) $line->further_discount_per_unit,
                    ]]),
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('supplier_target_lines', 'further_discounts')) {
            Schema::table('supplier_target_lines', fn (Blueprint $table) => $table->dropColumn('further_discounts'));
        }
    }
};
