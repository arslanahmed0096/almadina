<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $employees = DB::table('employees')->whereNull('deleted_at')->get();
        $users = DB::table('users')->whereNull('deleted_at')->get();

        $usersByEmail = $users
            ->filter(fn ($user) => trim((string) $user->email) !== '')
            ->keyBy(fn ($user) => strtolower(trim($user->email)));
        $usersByUsername = $users
            ->groupBy(fn ($user) => strtolower(trim((string) $user->username)))
            ->filter(fn ($matches) => $matches->count() === 1)
            ->map(fn ($matches) => $matches->first());
        $employeeUsernameCounts = $employees
            ->countBy(fn ($employee) => strtolower(trim((string) $employee->username)));
        $now = now();

        foreach ($employees as $employee) {
            if (DB::table('employee_branch_assignments')->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $email = strtolower(trim((string) $employee->email));
            $username = strtolower(trim((string) $employee->username));
            $user = $email !== '' ? $usersByEmail->get($email) : null;
            if (! $user && ($employeeUsernameCounts[$username] ?? 0) === 1) {
                $user = $usersByUsername->get($username);
            }
            if (! $user) {
                continue;
            }

            $warehouseIds = DB::table('user_warehouse')
                ->join('warehouses', 'warehouses.id', '=', 'user_warehouse.warehouse_id')
                ->where('user_warehouse.user_id', $user->id)
                ->whereNull('warehouses.deleted_at')
                ->pluck('user_warehouse.warehouse_id');

            foreach ($warehouseIds as $warehouseId) {
                DB::table('employee_branch_assignments')->insert([
                    'employee_id' => $employee->id,
                    'warehouse_id' => $warehouseId,
                    'designation_id' => $employee->designation_id,
                    'effective_from' => $employee->joining_date ?: $now->toDateString(),
                    'effective_to' => $employee->leaving_date,
                    'is_active' => true,
                    'created_by' => null,
                    'updated_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Legacy assignment inference is data migration and is intentionally retained.
    }
};
