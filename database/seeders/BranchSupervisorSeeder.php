<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class BranchSupervisorSeeder extends Seeder
{
    /**
     * Create one read-only closing supervisor for every active BRN warehouse.
     *
     * Existing supervisor passwords are deliberately left unchanged, making
     * this seeder safe to run again after supervisors change their passwords.
     */
    public function run(): void
    {
        $defaultPassword = (string) env('BRANCH_SUPERVISOR_DEFAULT_PASSWORD', '');
        $emailDomain = trim((string) env(
            'BRANCH_SUPERVISOR_EMAIL_DOMAIN',
            'al-madinaelectronics.com'
        ));

        if (strlen($defaultPassword) < 12) {
            throw new RuntimeException(
                'Set BRANCH_SUPERVISOR_DEFAULT_PASSWORD to a temporary password of at least 12 characters.'
            );
        }

        if ($emailDomain === '') {
            throw new RuntimeException('BRANCH_SUPERVISOR_EMAIL_DOMAIN cannot be empty.');
        }

        $permissionNames = [
            'dashboard',
            'Sales_view',
            'payment_sales_view',
            'Reports_sales',
            'Reports_payments_Sales',
            'cash_register_report',
        ];

        $permissions = Permission::query()
            ->whereNull('deleted_at')
            ->whereIn('name', $permissionNames)
            ->get();

        $missingPermissions = array_values(array_diff(
            $permissionNames,
            $permissions->pluck('name')->all()
        ));

        if ($missingPermissions !== []) {
            throw new RuntimeException(
                'Missing required permissions: '.implode(', ', $missingPermissions)
            );
        }

        $branches = Warehouse::query()
            ->whereNull('deleted_at')
            ->where('name', 'like', '%(BRN-%')
            ->orderBy('id')
            ->get();

        if ($branches->isEmpty()) {
            throw new RuntimeException('No active BRN warehouses were found.');
        }

        $results = DB::transaction(function () use (
            $branches,
            $defaultPassword,
            $emailDomain,
            $permissions
        ) {
            $role = Role::query()
                ->whereNull('deleted_at')
                ->where('name', 'Branch Supervisor')
                ->first();

            if (! $role) {
                $role = Role::create([
                    'name' => 'Branch Supervisor',
                    'label' => 'Branch Supervisor',
                    'description' => 'Read-only branch sales and closing supervisor',
                    'status' => 1,
                ]);
            } else {
                $role->update([
                    'label' => 'Branch Supervisor',
                    'description' => 'Read-only branch sales and closing supervisor',
                    'status' => 1,
                ]);
            }

            // Keep this role intentionally minimal, even when the seeder is rerun.
            $role->permissions()->sync($permissions->pluck('id')->all());

            $results = [];

            foreach ($branches as $branch) {
                if (! preg_match('/\(BRN-(\d+)\)/i', $branch->name, $matches)) {
                    continue;
                }

                $branchNumber = (int) $matches[1];
                $username = "branch{$branchNumber}.supervisor";
                $email = "{$username}@{$emailDomain}";

                $emailUser = User::query()->where('email', $email)->first();
                $usernameUser = User::query()->where('username', $username)->first();

                if ($emailUser && $usernameUser && $emailUser->id !== $usernameUser->id) {
                    throw new RuntimeException(
                        "Cannot create {$username}: its username and email belong to different users."
                    );
                }

                $user = $emailUser ?: $usernameUser;
                $wasCreated = ! $user;

                if (! $user) {
                    $user = new User;
                    $user->password = Hash::make($defaultPassword);
                    $user->avatar = 'default_avatar.png';
                    $user->phone = '';
                }

                $user->firstname = "Branch {$branchNumber}";
                $user->lastname = 'Supervisor';
                $user->username = $username;
                $user->email = $email;
                $user->role_id = $role->id;
                $user->statut = 1;
                $user->is_all_warehouses = 0;
                $user->record_view = 1;
                $user->deleted_at = null;
                $user->save();

                $user->roles()->sync([$role->id]);
                $user->assignedWarehouses()->sync([$branch->id]);
                $user->permissionOverrides()->detach();

                $results[] = [
                    'branch' => $branch->name,
                    'username' => $username,
                    'email' => $email,
                    'result' => $wasCreated ? 'created' : 'updated',
                ];
            }

            return $results;
        }, 3);

        if ($this->command) {
            $this->command->table(
                ['Branch', 'Username', 'Email', 'Result'],
                array_map(function ($result) {
                    return [
                        $result['branch'],
                        $result['username'],
                        $result['email'],
                        $result['result'],
                    ];
                }, $results)
            );
        }
    }
}
