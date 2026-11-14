<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'purchase_discounts.view' => 'View purchase discounts',
        'purchase_discounts.create' => 'Create purchase discounts',
        'purchase_discounts.update' => 'Update purchase discounts',
        'purchase_discounts.delete' => 'Delete unused purchase discounts',
    ];

    public function up(): void
    {
        Schema::create('purchase_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->enum('category', ['on_invoice', 'further_discount']);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('name', 'purchase_discounts_name_idx');
            $table->index(['category', 'status'], 'purchase_discounts_category_status_idx');
        });

        // Reserved for future purchase integration. No purchase calculation uses
        // this table yet; it only establishes an auditable reference and makes
        // deletion protection enforceable once a transaction links a type.
        Schema::create('purchase_discount_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_discount_id');
            // Legacy purchases and purchase_details use signed auto-increment
            // integers, so reference columns must use the same MySQL type.
            $table->integer('purchase_id');
            $table->integer('purchase_detail_id')->nullable();
            $table->timestamps();

            $table->foreign('purchase_discount_id', 'purchase_discount_usage_discount_fk')
                ->references('id')->on('purchase_discounts')->restrictOnDelete();
            $table->foreign('purchase_id', 'purchase_discount_usage_purchase_fk')
                ->references('id')->on('purchases')->restrictOnDelete();
            $table->foreign('purchase_detail_id', 'purchase_discount_usage_detail_fk')
                ->references('id')->on('purchase_details')->restrictOnDelete();
            $table->index(['purchase_id', 'purchase_detail_id'], 'purchase_discount_usage_transaction_idx');
        });

        if (Schema::hasTable('permissions')) {
            foreach ($this->permissions as $name => $label) {
                $permission = DB::table('permissions')->where('name', $name)->first();
                $permissionId = $permission?->id ?: DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'label' => $label,
                    'description' => $label,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($permission) {
                    DB::table('permissions')->where('id', $permissionId)->update([
                        'label' => $label,
                        'description' => $label,
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);
                }

                if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
                    continue;
                }
                $roleIds = DB::table('roles')
                    ->whereIn('name', ['Super Admin', 'Admin'])
                    ->orWhere('id', 1)
                    ->pluck('id')
                    ->unique();
                foreach ($roleIds as $roleId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
            }
            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            }
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        Schema::dropIfExists('purchase_discount_usages');
        Schema::dropIfExists('purchase_discounts');
    }
};
