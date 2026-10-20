<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedTinyInteger('payment_due_days')->nullable()->after('payment_statut');
            $table->date('payment_due_date')->nullable()->after('payment_due_days');
            $table->index(['warehouse_id', 'payment_due_date'], 'purchases_warehouse_payment_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_warehouse_payment_due_idx');
            $table->dropColumn(['payment_due_days', 'payment_due_date']);
        });
    }
};
