<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['statut', 'date'], 'purchases_status_date_aging_idx');
        });
        Schema::table('payment_purchases', function (Blueprint $table) {
            $table->index(['date', 'purchase_id'], 'payment_purchases_date_purchase_aging_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_purchases', function (Blueprint $table) {
            $table->dropIndex('payment_purchases_date_purchase_aging_idx');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_status_date_aging_idx');
        });
    }
};
