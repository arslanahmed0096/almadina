<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $permissionName = 'customer_credit_limit_update';

    public function up(): void
    {
        DB::transaction(function () {
            $permission = DB::table('permissions')->where('name', $this->permissionName)->first();

            if ($permission) {
                DB::table('permissions')->where('id', $permission->id)->update([
                    'label' => 'Update Customer Credit Limit',
                    'description' => 'Allows a user to add or change a customer credit limit, including from the shipment workflow.',
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
                $permissionId = $permission->id;
            } else {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $this->permissionName,
                    'label' => 'Update Customer Credit Limit',
                    'description' => 'Allows a user to add or change a customer credit limit, including from the shipment workflow.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $superAdminId = DB::table('roles')->where('name', 'Super Admin')->value('id');
            if ($superAdminId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $superAdminId,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $permissionIds = DB::table('permissions')
                ->where('name', $this->permissionName)
                ->pluck('id');

            if ($permissionIds->isEmpty()) {
                return;
            }

            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            }
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        });
    }
};
