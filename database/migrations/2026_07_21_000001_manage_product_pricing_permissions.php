<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $pharmacyPermissions = [
        'batch_view',
        'batch_manage',
        'batch_writeoff',
        'batch_force_override',
        'expiry_report',
        'Batch_Register_Report',
        'view_batches',
        'manage_batches',
        'writeoff_batches',
    ];

    private array $productPermissions = [
        'products_cost_view' => [
            'label' => 'View Product Cost',
            'description' => 'Allows product cost to be displayed and exported from the product list.',
        ],
        'pricing_level' => [
            'label' => 'Pricing Level Access',
            'description' => 'Allows access to view and update product pricing levels.',
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $pharmacyIds = DB::table('permissions')
                ->whereIn('name', $this->pharmacyPermissions)
                ->pluck('id');

            if ($pharmacyIds->isNotEmpty()) {
                if (Schema::hasTable('permission_user')) {
                    DB::table('permission_user')->whereIn('permission_id', $pharmacyIds)->delete();
                }
                DB::table('permission_role')->whereIn('permission_id', $pharmacyIds)->delete();
                DB::table('permissions')->whereIn('id', $pharmacyIds)->delete();
            }

            foreach ($this->productPermissions as $name => $attributes) {
                $permission = DB::table('permissions')->where('name', $name)->first();

                if ($permission) {
                    DB::table('permissions')->where('id', $permission->id)->update([
                        'label' => $attributes['label'],
                        'description' => $attributes['description'],
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);
                    $permissionId = $permission->id;
                } else {
                    $permissionId = DB::table('permissions')->insertGetId([
                        'name' => $name,
                        'label' => $attributes['label'],
                        'description' => $attributes['description'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if (DB::table('roles')->where('id', 1)->exists()) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => $permissionId,
                        'role_id' => 1,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $newPermissionIds = DB::table('permissions')
                ->whereIn('name', array_keys($this->productPermissions))
                ->pluck('id');

            if ($newPermissionIds->isNotEmpty()) {
                if (Schema::hasTable('permission_user')) {
                    DB::table('permission_user')->whereIn('permission_id', $newPermissionIds)->delete();
                }
                DB::table('permission_role')->whereIn('permission_id', $newPermissionIds)->delete();
                DB::table('permissions')->whereIn('id', $newPermissionIds)->delete();
            }

            foreach ($this->pharmacyPermissions as $name) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'label' => ucwords(str_replace('_', ' ', $name)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (DB::table('roles')->where('id', 1)->exists()) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $permissionId,
                        'role_id' => 1,
                    ]);
                }
            }
        });
    }
};
