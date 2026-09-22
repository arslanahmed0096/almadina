<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_level_details', function (Blueprint $table) {
            $table->decimal('previous_company_rb_price', 15, 2)->nullable()->after('pricing_margins');
            $table->decimal('previous_mrp_price', 15, 2)->nullable()->after('previous_company_rb_price');
            $table->decimal('previous_cost', 15, 2)->nullable()->after('previous_mrp_price');
            $table->decimal('previous_purchase_price', 15, 2)->nullable()->after('previous_cost');
            $table->json('previous_pricing_margins')->nullable()->after('previous_purchase_price');
            $table->decimal('previous_fix_price', 15, 2)->nullable()->after('previous_pricing_margins');
            $table->decimal('previous_price', 15, 2)->nullable()->after('previous_fix_price');
            $table->decimal('previous_wholesale_price', 15, 2)->nullable()->after('previous_price');
            $table->decimal('previous_min_price', 15, 2)->nullable()->after('previous_wholesale_price');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_level_details', function (Blueprint $table) {
            $table->dropColumn([
                'previous_company_rb_price',
                'previous_mrp_price',
                'previous_cost',
                'previous_purchase_price',
                'previous_pricing_margins',
                'previous_fix_price',
                'previous_price',
                'previous_wholesale_price',
                'previous_min_price',
            ]);
        });
    }
};