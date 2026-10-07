<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'targets.discount_ledger' => 'View supplier further discount ledger',
        'targets.discount_postings' => 'Post supplier further discount credits',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('supplier_target_lines', 'further_discount_per_unit')) {
            Schema::table('supplier_target_lines', function (Blueprint $table) {
                $table->decimal('further_discount_per_unit', 20, 2)->default(0)->after('target_quantity');
            });
        }
        if (! Schema::hasTable('supplier_target_line_allocations')) {
            Schema::create('supplier_target_line_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_target_id');
                $table->foreignId('supplier_target_line_id');
                $table->integer('warehouse_id');
                $table->decimal('allocated_quantity', 20, 3)->default(0);
                $table->timestamps();
                $table->foreign('supplier_target_id', 'stla_target_fk')->references('id')->on('supplier_targets')->cascadeOnDelete();
                $table->foreign('supplier_target_line_id', 'stla_line_fk')->references('id')->on('supplier_target_lines')->cascadeOnDelete();
                $table->foreign('warehouse_id', 'stla_warehouse_fk')->references('id')->on('warehouses')->restrictOnDelete();
                $table->unique(['supplier_target_line_id', 'warehouse_id'], 'target_line_warehouse_unique');
                $table->index(['supplier_target_id', 'warehouse_id'], 'target_line_allocations_scope_idx');
            });
        }
        if (! Schema::hasTable('supplier_target_discount_postings')) {
            Schema::create('supplier_target_discount_postings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_target_id');
                $table->foreignId('supplier_target_line_id');
                $table->date('posting_date');
                $table->decimal('amount', 20, 2);
                $table->string('reference', 191)->nullable();
                $table->text('notes')->nullable();
                $table->integer('created_by');
                $table->timestamps();
                $table->foreign('supplier_target_id', 'stdp_target_fk')->references('id')->on('supplier_targets')->cascadeOnDelete();
                $table->foreign('supplier_target_line_id', 'stdp_line_fk')->references('id')->on('supplier_target_lines')->restrictOnDelete();
                $table->foreign('created_by', 'stdp_creator_fk')->references('id')->on('users')->restrictOnDelete();
                $table->index(['supplier_target_id', 'posting_date'], 'target_discount_postings_date_idx');
            });
        }

        $this->backfillLineAllocations();
        foreach ($this->permissions as $name => $label) {
            DB::table('permissions')->updateOrInsert(['name' => $name], compact('label') + ['description' => $label]);
        }
        foreach (['Super Admin', 'Admin', 'Accountant'] as $role) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
            if (! $roleId) continue;
            foreach (array_keys($this->permissions) as $name) {
                $permissionId = DB::table('permissions')->where('name', $name)->value('id');
                DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    private function backfillLineAllocations(): void
    {
        DB::table('supplier_targets')->orderBy('id')->each(function ($target) {
            $lines = DB::table('supplier_target_lines')->where('supplier_target_id', $target->id)->orderBy('id')->get();
            $warehouses = DB::table('supplier_target_allocations')->where('supplier_target_id', $target->id)->orderBy('id')->get();
            $warehouseTotal = (float) $warehouses->sum('allocated_quantity');
            if ($lines->isEmpty() || $warehouses->isEmpty() || $warehouseTotal <= 0) return;
            foreach ($lines as $line) {
                $remaining = (int) round((float) $line->target_quantity * 1000);
                foreach ($warehouses as $index => $warehouse) {
                    $minor = $index === $warehouses->count() - 1
                        ? $remaining
                        : (int) round((float) $line->target_quantity * 1000 * ((float) $warehouse->allocated_quantity / $warehouseTotal));
                    $minor = max(0, min($minor, $remaining));
                    DB::table('supplier_target_line_allocations')->insert([
                        'supplier_target_id' => $target->id, 'supplier_target_line_id' => $line->id,
                        'warehouse_id' => $warehouse->warehouse_id, 'allocated_quantity' => $minor / 1000,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $remaining -= $minor;
                }
            }
        });
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('supplier_target_discount_postings');
        Schema::dropIfExists('supplier_target_line_allocations');
        Schema::table('supplier_target_lines', fn (Blueprint $table) => $table->dropColumn('further_discount_per_unit'));
    }
};
