<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->decimal('company_rb_price', 15, 2)->default(0)->after('cost');
            $table->decimal('mrp_price', 15, 2)->default(0)->after('company_rb_price');
            $table->decimal('sales_tax', 15, 2)->default(0)->after('TaxNet');
        });

        // Preserve sensible values for purchases created before the dedicated
        // MRP/RB purchase columns existed.
        DB::table('purchase_details')->update([
            'company_rb_price' => DB::raw('cost'),
            'mrp_price' => DB::raw('cost'),
            'sales_tax' => DB::raw("(cost - CASE WHEN discount_method = '2' THEN discount ELSE cost * discount / 100 END) * TaxNet / 100"),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropColumn(['company_rb_price', 'mrp_price', 'sales_tax']);
        });
    }
};
