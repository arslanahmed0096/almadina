<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('company_rb_price', 15, 2)->default(0)->after('cost');
            $table->decimal('mrp_price', 15, 2)->default(0)->after('company_rb_price');
            $table->decimal('fix_price', 15, 2)->default(0)->after('price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('company_rb_price', 15, 2)->default(0)->after('cost');
            $table->decimal('mrp_price', 15, 2)->default(0)->after('company_rb_price');
            $table->decimal('fix_price', 15, 2)->default(0)->after('price');
        });

        // Preserve current selling-price behaviour for existing records.
        DB::table('products')->update(['fix_price' => DB::raw('price')]);
        DB::table('product_variants')->update(['fix_price' => DB::raw('price')]);
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['company_rb_price', 'mrp_price', 'fix_price']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['company_rb_price', 'mrp_price', 'fix_price']);
        });
    }
};
