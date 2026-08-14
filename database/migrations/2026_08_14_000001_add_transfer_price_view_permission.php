<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'transfer_price_view';

    private const TRANSFER_PERMISSIONS = [
        'transfer_view',
        'transfer_add',
        'transfer_edit',
        'transfer_delete',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $permission = DB::table('permissions')->where('name', self::PERMISSION)->first();
            $values = [
                'label' => 'Show Transfer Product Price',
                'description' => 'Allows product prices and monetary totals to be displayed in stock transfers.',
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

            $sourcePermissionIds = DB::table('permissions')
                ->whereIn('name', self::TRANSFER_PERMISSIONS)
                ->pluck('id');

            $roleIds = DB::table('permission_role')
                ->whereIn('permission_id', $sourcePermissionIds)
                ->pluck('role_id')
                ->unique();

            $superAdminRoleIds = DB::table('roles')
                ->where('id', 1)
                ->orWhere('name', 'Super Admin')
                ->pluck('id');

            foreach ($roleIds->merge($superAdminRoleIds)->unique() as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }

            if (Schema::hasTable('permission_user')) {
                $allowedUserIds = DB::table('permission_user')
                    ->whereIn('permission_id', $sourcePermissionIds)
                    ->where('type', 'allow')
                    ->pluck('user_id')
                    ->unique();

                foreach ($allowedUserIds as $userId) {
                    DB::table('permission_user')->updateOrInsert(
                        ['permission_id' => $permissionId, 'user_id' => $userId],
                        ['type' => 'allow', 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $permissionIds = DB::table('permissions')
                ->where('name', self::PERMISSION)
                ->pluck('id');

            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            }

            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        });
    }
};
