<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_details', fn (Blueprint $table) => $table->dropColumn('reserved_quantity'));
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('reserved_quantity', 20, 6)->default(0)->after('quantity');
        });
    }
};
