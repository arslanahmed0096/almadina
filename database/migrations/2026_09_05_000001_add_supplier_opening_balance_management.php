<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $permissionName = 'supplier_opening_balance';

    public function up(): void
    {
        if (Schema::hasTable('providers') && ! Schema::hasColumn('providers', 'opening_balance_date')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->date('opening_balance_date')->nullable()->after('opening_balance');
            });
        }

        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permission = DB::table('permissions')->where('name', $this->permissionName)->first();
        $permissionId = $permission?->id ?: DB::table('permissions')->insertGetId([
            'name' => $this->permissionName,
            'label' => 'Manage Supplier Opening Balance',
            'description' => 'Allows a user to set a supplier opening balance and its effective date.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->where('id', $permissionId)->update([
            'label' => 'Manage Supplier Opening Balance',
            'description' => 'Allows a user to set a supplier opening balance and its effective date.',
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
        if (Schema::hasTable('permissions')) {
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

        if (Schema::hasTable('providers') && Schema::hasColumn('providers', 'opening_balance_date')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->dropColumn('opening_balance_date');
            });
        }
    }
};
