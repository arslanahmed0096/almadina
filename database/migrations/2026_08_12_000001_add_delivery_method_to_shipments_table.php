<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('delivery_method', 32)
                ->default('self_delivery')
                ->after('shipping_details');
            $table->string('driver_name', 192)
                ->nullable()
                ->after('delivery_method');
        });

        Schema::table('shipment_items', function (Blueprint $table) {
            $table->string('delivery_method', 32)
                ->default('self_delivery')
                ->after('shipped_by');
            $table->string('driver_name', 192)
                ->nullable()
                ->after('delivery_method');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_items', function (Blueprint $table) {
            $table->dropColumn(['delivery_method', 'driver_name']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['delivery_method', 'driver_name']);
        });
    }
};
