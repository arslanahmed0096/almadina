<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->after('purchase_id');
            $table->decimal('gate_pass_quantity', 20, 6)->default(0)->after('quantity');
            $table->decimal('invoice_excess_quantity', 20, 6)->default(0)->after('gate_pass_quantity');
            $table->index('purchase_order_item_id', 'purchase_details_po_item_index');
            $table->foreign('purchase_order_item_id', 'purchase_details_po_item_fk')
                ->references('id')->on('purchase_order_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropForeign('purchase_details_po_item_fk');
            $table->dropIndex('purchase_details_po_item_index');
            $table->dropColumn(['purchase_order_item_id', 'gate_pass_quantity', 'invoice_excess_quantity']);
        });
    }
};
