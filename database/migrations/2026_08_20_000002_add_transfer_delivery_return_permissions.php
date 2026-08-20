<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'transfer_partial_receive' => 'Partially Receive Stock Transfer',
        'transfer_reject_items' => 'Reject Transfer Items',
        'transfer_return_create' => 'Create Transfer Return',
        'transfer_return_dispatch' => 'Dispatch Transfer Return',
        'transfer_return_receive' => 'Receive Transfer Return',
        'transfer_cancel' => 'Cancel Stock Transfer',
        'transfer_history' => 'View Stock Transfer History',
    ];

    public function up(): void
    {
        $legacyId = DB::table('permissions')->where('name', 'transfer_edit')->value('id');
        $roleIds = $legacyId ? DB::table('permission_role')->where('permission_id', $legacyId)->pluck('role_id') : collect();
        $roleIds = $roleIds->merge(DB::table('roles')->where('id', 1)->orWhere('name', 'Super Admin')->pluck('id'))->unique();

        foreach (self::PERMISSIONS as $name => $label) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $name, 'label' => $label, 'description' => $label,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        if (Schema::hasTable('permission_user')) DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
