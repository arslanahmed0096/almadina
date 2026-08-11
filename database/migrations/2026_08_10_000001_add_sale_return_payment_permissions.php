<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'payment_sale_returns_view' => [
            'legacy' => 'payment_returns_view',
            'label' => 'Show Sale Return Payments',
            'description' => 'Allows a user to view payments from the sale returns list.',
        ],
        'payment_sale_returns_add' => [
            'legacy' => 'payment_returns_add',
            'label' => 'Create Sale Return Payment',
            'description' => 'Allows a user to create payments from the sale returns list.',
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach ($this->permissions as $name => $attributes) {
                $permissionId = $this->upsertPermission($name, $attributes);
                $legacyId = DB::table('permissions')
                    ->where('name', $attributes['legacy'])
                    ->value('id');

                if ($legacyId) {
                    $this->copyRoleAssignments((int) $legacyId, $permissionId);
                    $this->copyUserOverrides((int) $legacyId, $permissionId);
                }

                $superAdminId = DB::table('roles')->where('name', 'Super Admin')->value('id');
                if ($superAdminId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => $permissionId,
                        'role_id' => $superAdminId,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', array_keys($this->permissions))
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

    private function upsertPermission(string $name, array $attributes): int
    {
        $permission = DB::table('permissions')->where('name', $name)->first();
        $values = [
            'label' => $attributes['label'],
            'description' => $attributes['description'],
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        if ($permission) {
            DB::table('permissions')->where('id', $permission->id)->update($values);

            return (int) $permission->id;
        }

        return (int) DB::table('permissions')->insertGetId(array_merge($values, [
            'name' => $name,
            'created_at' => now(),
        ]));
    }

    private function copyRoleAssignments(int $legacyPermissionId, int $permissionId): void
    {
        $roleIds = DB::table('permission_role')
            ->where('permission_id', $legacyPermissionId)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    private function copyUserOverrides(int $legacyPermissionId, int $permissionId): void
    {
        if (! Schema::hasTable('permission_user')) {
            return;
        }

        $overrides = DB::table('permission_user')
            ->where('permission_id', $legacyPermissionId)
            ->get(['user_id', 'type']);

        foreach ($overrides as $override) {
            DB::table('permission_user')->updateOrInsert(
                [
                    'permission_id' => $permissionId,
                    'user_id' => $override->user_id,
                ],
                [
                    'type' => $override->type,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
};
