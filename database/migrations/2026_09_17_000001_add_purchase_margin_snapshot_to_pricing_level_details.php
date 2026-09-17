<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_level_details', function (Blueprint $table) {
            $table->decimal('purchase_price', 15, 2)->nullable()->after('cost');
            $table->json('pricing_margins')->nullable()->after('purchase_price');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_level_details', function (Blueprint $table) {
            $table->dropColumn(['purchase_price', 'pricing_margins']);
        });
    }
};
