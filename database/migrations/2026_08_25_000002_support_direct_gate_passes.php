<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
        });
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->change();
            $table->string('receipt_type', 20)->default('purchase_order')->after('purchase_order_id')->index();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
        });

        Schema::table('gate_pass_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_item_id']);
        });
        Schema::table('gate_pass_items', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->change();
            $table->integer('unit_id')->nullable()->after('product_variant_id')->index();
            $table->string('product_name', 255)->nullable()->after('unit_id');
            $table->string('variant_name', 255)->nullable()->after('product_name');
            $table->string('sku', 192)->nullable()->after('variant_name');
            $table->string('unit_name', 100)->nullable()->after('sku');
            $table->decimal('default_unit_cost', 20, 6)->default(0)->after('unit_name');
            $table->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->restrictOnDelete();
            $table->foreign('unit_id')->references('id')->on('units')->restrictOnDelete();
        });

        DB::table('gate_pass_items')->whereNotNull('purchase_order_item_id')->orderBy('id')->chunkById(500, function ($items) {
            $poItems = DB::table('purchase_order_items')->whereIn('id', $items->pluck('purchase_order_item_id'))->get()->keyBy('id');
            foreach ($items as $item) {
                $poItem = $poItems->get($item->purchase_order_item_id);
                if ($poItem) {
                    DB::table('gate_pass_items')->where('id', $item->id)->update([
                        'unit_id' => $poItem->unit_id,
                        'product_name' => $poItem->product_name,
                        'variant_name' => $poItem->variant_name,
                        'sku' => $poItem->sku,
                        'unit_name' => $poItem->unit_name,
                        'default_unit_cost' => $poItem->unit_price,
                    ]);
                }
            }
        });

        foreach (['procurement_stock_movements', 'supplier_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['purchase_order_id']);
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('purchase_order_id')->nullable()->change();
                $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            });
        }

        $warehouseRoleId = DB::table('roles')->where('name', 'Warehouse Manager')->value('id');
        if ($warehouseRoleId) {
            $permissionIds = DB::table('permissions')->whereIn('name', [
                'gate_passes_view', 'gate_passes_create', 'gate_passes_confirm',
                'gate_passes_reject', 'gate_passes_upload', 'supplier_invoices_view',
            ])->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $warehouseRoleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('gate_passes')->whereNull('purchase_order_id')->exists()) {
            throw new RuntimeException('Direct Gate Passes exist and prevent a safe rollback.');
        }

        foreach (['supplier_invoices', 'procurement_stock_movements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['purchase_order_id']);
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('purchase_order_id')->nullable(false)->change();
                $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            });
        }

        Schema::table('gate_pass_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_item_id']);
            $table->dropForeign(['unit_id']);
        });
        Schema::table('gate_pass_items', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_item_id')->nullable(false)->change();
            $table->dropColumn(['unit_id', 'product_name', 'variant_name', 'sku', 'unit_name', 'default_unit_cost']);
            $table->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->restrictOnDelete();
        });

        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropIndex(['receipt_type']);
        });
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable(false)->change();
            $table->dropColumn('receipt_type');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
        });
    }
};
