<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_key')->unique();
            $table->string('policy_name');
            $table->string('policy_value');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });

        DB::table('policies')->insert([
            'policy_key' => 'credit_limit',
            'policy_name' => 'Credit Limit Policy',
            'policy_value' => '30',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedTinyInteger('credit_days')->nullable()->after('payment_statut');
            $table->date('credit_due_date')->nullable()->after('credit_days');
            $table->index(['client_id', 'credit_due_date'], 'sales_client_credit_due_idx');
            $table->index(['warehouse_id', 'credit_due_date'], 'sales_warehouse_credit_due_idx');
            $table->index(['payment_statut', 'statut'], 'sales_credit_status_idx');
        });

        Schema::create('overdue_credit_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sale_id');
            $table->unsignedInteger('user_id');
            $table->date('reminder_date');
            $table->timestamps();
            $table->unique(['sale_id', 'user_id', 'reminder_date'], 'overdue_credit_daily_unique');
            $table->index(['reminder_date', 'sale_id']);
        });

        foreach ([
            'policies.view' => ['View Policies', 'View configured business policies.'],
            'policies.update' => ['Update Policies', 'Update global business policies.'],
        ] as $name => [$label, $description]) {
            $permission = DB::table('permissions')->where('name', $name)->first();
            $permissionId = $permission?->id ?: DB::table('permissions')->insertGetId([
                'name' => $name,
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('permissions')->where('id', $permissionId)->update([
                'label' => $label,
                'description' => $description,
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            $roleIds = DB::table('roles')->whereIn('name', ['Super Admin', 'Admin'])->pluck('id');
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
        $permissionIds = DB::table('permissions')->whereIn('name', ['policies.view', 'policies.update'])->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            }
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('overdue_credit_reminders');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_client_credit_due_idx');
            $table->dropIndex('sales_warehouse_credit_due_idx');
            $table->dropIndex('sales_credit_status_idx');
            $table->dropColumn(['credit_days', 'credit_due_date']);
        });
        Schema::dropIfExists('policies');
    }
};
