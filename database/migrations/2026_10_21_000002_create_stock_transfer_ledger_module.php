<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'stock_transfer_ledger_view' => 'View Stock Transfer Ledger',
        'stock_transfer_ledger_manage' => 'Manage External Transfer Companies',
        'stock_transfer_payment_create' => 'Receive Stock Transfer Payments',
    ];

    public function up(): void
    {
        Schema::create('stock_transfer_companies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 192);
            $table->string('code', 60)->nullable()->unique();
            $table->string('phone', 60)->nullable();
            $table->string('email', 192)->nullable();
            $table->text('address')->nullable();
            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['is_active', 'deleted_at']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('ownership_type', 20)->default('internal')->after('country');
            $table->unsignedInteger('stock_transfer_company_id')->nullable()->after('ownership_type');
            $table->index(['ownership_type', 'stock_transfer_company_id'], 'warehouses_transfer_owner_idx');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->string('ownership_type', 20)->default('internal')->after('transfer_type');
            $table->unsignedInteger('stock_transfer_company_id')->nullable()->after('ownership_type');
            $table->decimal('transfer_value', 20, 4)->default(0)->after('GrandTotal');
            $table->decimal('previous_outstanding', 20, 4)->default(0)->after('transfer_value');
            $table->decimal('advance_applied', 20, 4)->default(0)->after('previous_outstanding');
            $table->decimal('paid_amount', 20, 4)->default(0)->after('advance_applied');
            $table->decimal('outstanding_balance', 20, 4)->default(0)->after('paid_amount');
            $table->string('payment_status', 30)->default('not_applicable')->after('outstanding_balance');
            $table->timestamp('financial_posted_at')->nullable()->after('payment_status');
            $table->index(['ownership_type', 'stock_transfer_company_id', 'payment_status'], 'transfers_financial_idx');
        });

        Schema::table('transfer_details', function (Blueprint $table) {
            $table->decimal('unit_transfer_price', 20, 4)->nullable()->after('cost');
            $table->decimal('transfer_total', 20, 4)->nullable()->after('unit_transfer_price');
        });

        Schema::create('stock_transfer_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('stock_transfer_company_id');
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 20, 4);
            $table->unsignedInteger('payment_method_id')->nullable();
            $table->unsignedInteger('account_id')->nullable();
            $table->string('reference', 80)->unique();
            $table->string('external_reference', 192)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('received_by');
            $table->string('status', 20)->default('posted');
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
            $table->timestamps();
            $table->index(['stock_transfer_company_id', 'payment_date'], 'st_payments_company_date_idx');
            $table->index(['warehouse_id', 'payment_date'], 'st_payments_warehouse_date_idx');
        });

        Schema::create('stock_transfer_payment_allocations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('stock_transfer_payment_id');
            $table->unsignedInteger('transfer_id');
            $table->decimal('amount', 20, 4);
            $table->string('allocation_type', 20)->default('payment');
            $table->unsignedInteger('allocated_by')->nullable();
            $table->timestamps();
            $table->unique(['stock_transfer_payment_id', 'transfer_id'], 'st_payment_transfer_unique');
            $table->index(['transfer_id', 'allocation_type'], 'st_alloc_transfer_type_idx');
        });

        Schema::create('stock_transfer_ledger_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('stock_transfer_company_id');
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->unsignedInteger('transfer_id')->nullable();
            $table->unsignedInteger('stock_transfer_payment_id')->nullable();
            $table->unsignedInteger('transfer_return_id')->nullable();
            $table->unsignedInteger('reversal_of_id')->nullable();
            $table->date('entry_date');
            $table->string('entry_type', 40);
            $table->string('reference', 192);
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('posted_by')->nullable();
            $table->string('idempotency_key', 192)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['stock_transfer_company_id', 'entry_date', 'id'], 'st_ledger_company_date_idx');
            $table->index(['warehouse_id', 'entry_date', 'id'], 'st_ledger_warehouse_date_idx');
            $table->index(['transfer_id', 'entry_type'], 'st_ledger_transfer_type_idx');
        });

        $legacyId = DB::table('permissions')->where('name', 'transfer_view')->value('id');
        $roleIds = $legacyId ? DB::table('permission_role')->where('permission_id', $legacyId)->pluck('role_id') : collect();
        $roleIds = $roleIds->merge(DB::table('roles')->where('id', 1)->orWhere('name', 'Super Admin')->pluck('id'))->unique();
        foreach (self::PERMISSIONS as $name => $label) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $name, 'label' => $label, 'description' => $label,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        if (Schema::hasTable('permission_user')) DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::dropIfExists('stock_transfer_ledger_entries');
        Schema::dropIfExists('stock_transfer_payment_allocations');
        Schema::dropIfExists('stock_transfer_payments');
        Schema::table('transfer_details', function (Blueprint $table) {
            $table->dropColumn(['unit_transfer_price', 'transfer_total']);
        });
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndex('transfers_financial_idx');
            $table->dropColumn(['ownership_type', 'stock_transfer_company_id', 'transfer_value', 'previous_outstanding', 'advance_applied', 'paid_amount', 'outstanding_balance', 'payment_status', 'financial_posted_at']);
        });
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropIndex('warehouses_transfer_owner_idx');
            $table->dropColumn(['ownership_type', 'stock_transfer_company_id']);
        });
        Schema::dropIfExists('stock_transfer_companies');
    }
};
