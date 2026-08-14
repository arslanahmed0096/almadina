<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_PERMISSION = 'pricing_level';

    private array $permissions = [
        'pricing_level_view' => [
            'label' => 'View Price Level',
            'description' => 'Allows a user to view price level entries and pricing history.',
        ],
        'pricing_level_add' => [
            'label' => 'Create Price Level',
            'description' => 'Allows a user to create price level entries.',
        ],
        'pricing_level_edit' => [
            'label' => 'Edit Price Level',
            'description' => 'Allows a user to edit existing price level entries.',
        ],
        'pricing_level_delete' => [
            'label' => 'Delete Price Level',
            'description' => 'Allows a user to delete price level entries.',
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $legacyId = DB::table('permissions')
                ->where('name', self::LEGACY_PERMISSION)
                ->value('id');

            foreach ($this->permissions as $name => $attributes) {
                $permissionId = $this->upsertPermission($name, $attributes);

                if ($legacyId) {
                    $this->copyRoleAssignments((int) $legacyId, $permissionId);
                    $this->copyUserOverrides((int) $legacyId, $permissionId);
                }

                $superAdminRoleIds = DB::table('roles')
                    ->where('id', 1)
                    ->orWhere('name', 'Super Admin')
                    ->pluck('id');

                foreach ($superAdminRoleIds as $roleId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }

            if ($legacyId) {
                $this->deletePermission((int) $legacyId);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $newPermissionIds = DB::table('permissions')
                ->whereIn('name', array_keys($this->permissions))
                ->pluck('id');

            $legacyId = $this->upsertPermission(self::LEGACY_PERMISSION, [
                'label' => 'Pricing Level Access',
                'description' => 'Allows access to view and update product pricing levels.',
            ]);

            if ($newPermissionIds->isNotEmpty()) {
                $roleIds = DB::table('permission_role')
                    ->whereIn('permission_id', $newPermissionIds)
                    ->pluck('role_id')
                    ->unique();

                foreach ($roleIds as $roleId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => $legacyId,
                        'role_id' => $roleId,
                    ]);
                }

                $this->mergeUserOverrides($newPermissionIds, $legacyId);

                foreach ($newPermissionIds as $permissionId) {
                    $this->deletePermission((int) $permissionId);
                }
            }
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

    private function copyRoleAssignments(int $sourcePermissionId, int $targetPermissionId): void
    {
        $roleIds = DB::table('permission_role')
            ->where('permission_id', $sourcePermissionId)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $targetPermissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    private function copyUserOverrides(int $sourcePermissionId, int $targetPermissionId): void
    {
        if (! Schema::hasTable('permission_user')) {
            return;
        }

        $overrides = DB::table('permission_user')
            ->where('permission_id', $sourcePermissionId)
            ->get(['user_id', 'type']);

        foreach ($overrides as $override) {
            DB::table('permission_user')->updateOrInsert(
                [
                    'permission_id' => $targetPermissionId,
                    'user_id' => $override->user_id,
                ],
                [
                    'type' => $override->type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function mergeUserOverrides($sourcePermissionIds, int $targetPermissionId): void
    {
        if (! Schema::hasTable('permission_user')) {
            return;
        }

        $overrides = DB::table('permission_user')
            ->whereIn('permission_id', $sourcePermissionIds)
            ->get(['user_id', 'type'])
            ->groupBy('user_id');

        foreach ($overrides as $userId => $userOverrides) {
            DB::table('permission_user')->updateOrInsert(
                [
                    'permission_id' => $targetPermissionId,
                    'user_id' => $userId,
                ],
                [
                    'type' => $userOverrides->contains('type', 'allow') ? 'allow' : 'deny',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function deletePermission(int $permissionId): void
    {
        if (Schema::hasTable('permission_user')) {
            DB::table('permission_user')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
