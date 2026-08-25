<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'taxes.view' => 'View taxes', 'taxes.create' => 'Create taxes', 'taxes.update' => 'Update taxes',
        'taxes.delete' => 'Delete unused taxes', 'taxes.activate' => 'Activate or deactivate taxes',
        'taxes.apply' => 'Apply approved taxes', 'taxes.override' => 'Override transaction taxes',
        'taxes.report' => 'View tax reports',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $name => $label) {
            $permission = DB::table('permissions')->where('name', $name)->first();
            $id = $permission ? (int) $permission->id : (int) DB::table('permissions')->insertGetId([
                'name' => $name, 'label' => $label, 'description' => $label,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($permission) {
                DB::table('permissions')->where('id', $id)->update(['label' => $label, 'description' => $label, 'deleted_at' => null, 'updated_at' => now()]);
            }
            $roles = DB::table('roles')->where('id', 1)->orWhereIn('name', ['Super Admin', 'Admin'])->pluck('id');
            foreach ($roles as $roleId) {
                DB::table('permission_role')->updateOrInsert(['permission_id' => $id, 'role_id' => $roleId]);
            }

        }

        // Preserve transaction access according to existing capabilities. Tax
        // management privileges remain explicitly assignable by Super Admin.
        $applyId = DB::table('permissions')->where('name', 'taxes.apply')->value('id');
        $salesPermissionIds = DB::table('permissions')
            ->whereIn('name', ['Sales_add', 'Pos_view'])
            ->pluck('id');
        $salesRoleIds = DB::table('permission_role')
            ->whereIn('permission_id', $salesPermissionIds)
            ->pluck('role_id')
            ->unique();

        foreach ($salesRoleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $applyId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        if (Schema::hasTable('permission_user')) DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
