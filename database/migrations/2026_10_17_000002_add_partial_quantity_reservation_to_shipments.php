<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_items', function (Blueprint $table) {
            $table->decimal('quantity', 20, 6)->nullable()->after('sale_detail_id');
        });
        DB::statement('UPDATE shipment_items si INNER JOIN sale_details sd ON sd.id = si.sale_detail_id SET si.quantity = sd.quantity');

        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('reserved_quantity', 20, 6)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', fn (Blueprint $table) => $table->dropColumn('reserved_quantity'));
        Schema::table('shipment_items', fn (Blueprint $table) => $table->dropColumn('quantity'));
    }
};
