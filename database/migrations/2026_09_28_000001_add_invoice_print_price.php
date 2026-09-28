<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('invoice_print_price', 15, 2)->default(0)->after('fix_price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('invoice_print_price', 15, 2)->default(0)->after('fix_price');
        });

        Schema::table('pricing_level_details', function (Blueprint $table) {
            $table->decimal('invoice_print_price', 15, 2)->default(0)->after('fix_price');
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('invoice_print_price', 15, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('invoice_print_price');
        });

        Schema::table('pricing_level_details', function (Blueprint $table) {
            $table->dropColumn('invoice_print_price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('invoice_print_price');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('invoice_print_price');
        });
    }
};
