<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('withholding_tax', 15, 2)->default(0)->after('TaxNet');
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->decimal('withholding_tax', 15, 2)->default(0)->after('sales_tax');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropColumn('withholding_tax');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('withholding_tax');
        });
    }
};
