<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'targets.view' => 'View supplier targets', 'targets.create' => 'Create supplier targets',
        'targets.edit' => 'Edit supplier targets', 'targets.activate' => 'Activate supplier targets',
        'targets.cancel' => 'Cancel supplier targets', 'targets.delete' => 'Delete draft supplier targets',
        'targets.view_all_warehouses' => 'View all warehouse targets',
        'targets.reports' => 'View target reports', 'targets.export' => 'Export target reports',
    ];

    public function up(): void
    {
        Schema::create('supplier_targets', function (Blueprint $table) {
            $table->id();
            $table->integer('supplier_id');
            $table->string('target_name', 191);
            $table->string('period_type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('measurement_type', 20)->default('quantity');
            $table->string('status', 20)->default('draft');
            $table->text('description')->nullable();
            $table->boolean('allocation_requires_review')->default(false);
            $table->integer('created_by');
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('supplier_id')->references('id')->on('providers')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['supplier_id', 'period_type', 'start_date', 'end_date'], 'supplier_targets_period_idx');
            $table->index(['status', 'start_date', 'end_date'], 'supplier_targets_status_period_idx');
        });
        Schema::create('supplier_target_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_target_id');
            $table->integer('product_id')->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('unit_id')->nullable();
            $table->decimal('target_quantity', 20, 3);
            $table->timestamps();
            $table->foreign('supplier_target_id')->references('id')->on('supplier_targets')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            $table->unique(['supplier_target_id', 'product_id'], 'supplier_target_lines_product_unique');
            $table->unique(['supplier_target_id', 'category_id'], 'supplier_target_lines_category_unique');
        });
        Schema::create('supplier_target_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_target_id');
            $table->integer('warehouse_id');
            $table->decimal('allocated_quantity', 20, 3)->default(0);
            $table->timestamps();
            $table->foreign('supplier_target_id')->references('id')->on('supplier_targets')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['supplier_target_id', 'warehouse_id'], 'supplier_target_allocations_unique');
        });
        Schema::create('supplier_target_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_target_id');
            $table->integer('user_id')->nullable();
            $table->string('event', 60);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();
            $table->foreign('supplier_target_id')->references('id')->on('supplier_targets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['supplier_target_id', 'created_at']);
        });
        Schema::table('sales', fn (Blueprint $t) => $t->index(['statut', 'date', 'warehouse_id'], 'sales_target_idx'));
        Schema::table('sale_details', fn (Blueprint $t) => $t->index(['product_id', 'sale_id'], 'sale_details_target_idx'));
        Schema::table('sale_returns', fn (Blueprint $t) => $t->index(['statut', 'date', 'warehouse_id'], 'sale_returns_target_idx'));

        foreach ($this->permissions as $name => $label) {
            DB::table('permissions')->updateOrInsert(['name' => $name], compact('label') + ['description' => $label]);
        }
        $grants = [
            'Super Admin' => array_keys($this->permissions), 'Admin' => array_keys($this->permissions),
            'Branch Manager' => ['targets.view'],
            'Accountant' => ['targets.view', 'targets.reports', 'targets.export'],
        ];
        foreach ($grants as $role => $names) {
            if (! $roleId = DB::table('roles')->where('name', $role)->value('id')) {
                continue;
            }
            foreach ($names as $name) {
                $permissionId = DB::table('permissions')->where('name', $name)->value('id');
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId, 'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::table('sale_returns', fn (Blueprint $t) => $t->dropIndex('sale_returns_target_idx'));
        Schema::table('sale_details', fn (Blueprint $t) => $t->dropIndex('sale_details_target_idx'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropIndex('sales_target_idx'));
        Schema::dropIfExists('supplier_target_histories');
        Schema::dropIfExists('supplier_target_allocations');
        Schema::dropIfExists('supplier_target_lines');
        Schema::dropIfExists('supplier_targets');
    }
};
