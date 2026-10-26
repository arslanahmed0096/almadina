<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_payments', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('reference')->unique();
            $table->unique(['stock_transfer_company_id', 'external_reference'], 'st_payment_company_external_ref_unique');
        });

        $writePermissionIds = DB::table('permissions')->whereIn('name', [
            'stock_transfer_ledger_manage', 'stock_transfer_payment_create',
        ])->pluck('id');
        $legacyEditId = DB::table('permissions')->where('name', 'transfer_edit')->value('id');
        $allowedRoleIds = $legacyEditId
            ? DB::table('permission_role')->where('permission_id', $legacyEditId)->pluck('role_id')
            : collect();
        $allowedRoleIds = $allowedRoleIds->merge(DB::table('roles')->where('id', 1)->orWhere('name', 'Super Admin')->pluck('id'))->unique();

        if ($writePermissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $writePermissionIds)
                ->when($allowedRoleIds->isNotEmpty(), fn ($query) => $query->whereNotIn('role_id', $allowedRoleIds))
                ->when($allowedRoleIds->isEmpty(), fn ($query) => $query)
                ->delete();
            foreach ($writePermissionIds as $permissionId) {
                foreach ($allowedRoleIds as $roleId) {
                    DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('stock_transfer_payments', function (Blueprint $table) {
            $table->dropUnique('stock_transfer_payments_idempotency_key_unique');
            $table->dropUnique('st_payment_company_external_ref_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
