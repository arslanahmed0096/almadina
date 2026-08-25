<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $applyPermissionId = DB::table('permissions')
            ->where('name', 'taxes.apply')
            ->value('id');

        if (! $applyPermissionId) {
            return;
        }

        $transactionPermissionIds = DB::table('permissions')
            ->whereIn('name', ['Sales_add', 'Pos_view'])
            ->pluck('id');

        $salesRoleIds = DB::table('permission_role')
            ->whereIn('permission_id', $transactionPermissionIds)
            ->pluck('role_id')
            ->unique();

        foreach ($salesRoleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $applyPermissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        // This data backfill intentionally has no rollback: removing a permission
        // that an administrator may since have assigned would be destructive.
    }
};
