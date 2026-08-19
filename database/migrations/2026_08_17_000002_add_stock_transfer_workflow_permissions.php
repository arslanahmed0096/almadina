<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'transfer_request' => ['Request Stock Transfer', 'Create a stock request from the central warehouse.'],
        'transfer_approve' => ['Approve Stock Transfer', 'Fully approve a stock transfer request.'],
        'transfer_partial_approve' => ['Partially Approve Stock Transfer', 'Approve only part of a stock transfer request.'],
        'transfer_decline' => ['Decline Stock Transfer', 'Decline a stock transfer request.'],
        'transfer_acknowledge' => ['Acknowledge Stock Transfer', 'Acknowledge the central warehouse response.'],
        'transfer_dispatch' => ['Dispatch Stock Transfer', 'Dispatch approved stock from the source warehouse.'],
        'transfer_receive' => ['Receive Stock Transfer', 'Receive dispatched stock into the destination warehouse.'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $legacyMap = [
                'transfer_request' => 'transfer_add',
                'transfer_approve' => 'transfer_edit',
                'transfer_partial_approve' => 'transfer_edit',
                'transfer_decline' => 'transfer_edit',
                'transfer_acknowledge' => 'transfer_edit',
                'transfer_dispatch' => 'transfer_edit',
                'transfer_receive' => 'transfer_edit',
            ];

            foreach (self::PERMISSIONS as $name => [$label, $description]) {
                $permission = DB::table('permissions')->where('name', $name)->first();
                $values = compact('label', 'description') + ['deleted_at' => null, 'updated_at' => now()];
                $permissionId = $permission
                    ? (int) $permission->id
                    : (int) DB::table('permissions')->insertGetId(['name' => $name, 'created_at' => now()] + $values);

                if ($permission) {
                    DB::table('permissions')->where('id', $permissionId)->update($values);
                }

                $legacyId = DB::table('permissions')->where('name', $legacyMap[$name])->value('id');
                $roleIds = $legacyId
                    ? DB::table('permission_role')->where('permission_id', $legacyId)->pluck('role_id')
                    : collect();
                $superAdminIds = DB::table('roles')->where('id', 1)->orWhere('name', 'Super Admin')->pluck('id');

                foreach ($roleIds->merge($superAdminIds)->unique() as $roleId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }

                if (Schema::hasTable('permission_user') && $legacyId) {
                    $overrides = DB::table('permission_user')->where('permission_id', $legacyId)->get();
                    foreach ($overrides as $override) {
                        DB::table('permission_user')->updateOrInsert(
                            ['permission_id' => $permissionId, 'user_id' => $override->user_id],
                            ['type' => $override->type, 'created_at' => now(), 'updated_at' => now()]
                        );
                    }
                }
            }
        });
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        if (Schema::hasTable('permission_user')) {
            DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
