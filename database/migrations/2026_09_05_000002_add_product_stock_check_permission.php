<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $permissionName = 'product_stock_check';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permission = DB::table('permissions')->where('name', $this->permissionName)->first();
        $permissionId = $permission?->id ?: DB::table('permissions')->insertGetId([
            'name' => $this->permissionName,
            'label' => 'Check Product Stock',
            'description' => 'Allows a user to check product stock across assigned branches.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->where('id', $permissionId)->update([
            'label' => 'Check Product Stock',
            'description' => 'Allows a user to check product stock across assigned branches.',
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('permission_role') && Schema::hasTable('roles')) {
            $roleIds = DB::table('roles')
                ->where('id', 1)
                ->orWhereIn('name', ['Super Admin', 'Admin'])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('name', $this->permissionName)
            ->pluck('id');

        if (Schema::hasTable('permission_user')) {
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
        }
        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        }
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
