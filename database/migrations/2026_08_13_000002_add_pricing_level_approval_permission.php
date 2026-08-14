<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'pricing_level_approve';

    public function up(): void
    {
        DB::transaction(function () {
            $permission = DB::table('permissions')
                ->where('name', self::PERMISSION)
                ->first();

            $values = [
                'label' => 'Approve Price Level',
                'description' => 'Allows a user to approve price level entries.',
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            if ($permission) {
                DB::table('permissions')->where('id', $permission->id)->update($values);
                $permissionId = (int) $permission->id;
            } else {
                $permissionId = (int) DB::table('permissions')->insertGetId(array_merge($values, [
                    'name' => self::PERMISSION,
                    'created_at' => now(),
                ]));
            }

            $superAdminRoleIds = DB::table('roles')
                ->where('id', 1)
                ->orWhere('name', 'Super Admin')
                ->pluck('id')
                ->unique();

            foreach ($superAdminRoleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $permissionIds = DB::table('permissions')
                ->where('name', self::PERMISSION)
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
