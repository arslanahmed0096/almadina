<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'daily_reports_view' => 'View Daily Reports',
        'daily_reports_export' => 'Print and export Daily Reports',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($this->permissions as $name => $label) {
            $permission = DB::table('permissions')->where('name', $name)->first();
            $permissionId = $permission?->id ?: DB::table('permissions')->insertGetId([
                'name' => $name,
                'label' => $label,
                'description' => $label,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('permissions')->where('id', $permissionId)->update([
                'label' => $label,
                'description' => $label,
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('permission_role')) {
                $roleIds = DB::table('roles')->where('id', 1)->orWhereIn('name', ['Super Admin', 'Admin'])->pluck('id');
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
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        if (Schema::hasTable('permission_user')) {
            DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        }
        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
