<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_branch_assignments', function (Blueprint $t) {
            $t->id(); $t->integer('employee_id'); $t->integer('warehouse_id'); $t->integer('designation_id');
            $t->date('effective_from'); $t->date('effective_to')->nullable(); $t->boolean('is_active')->default(true);
            $t->integer('created_by')->nullable(); $t->integer('updated_by')->nullable(); $t->timestamps();
            $t->index(['warehouse_id', 'effective_from', 'effective_to'], 'employee_branch_period_idx');
            $t->index(['employee_id', 'effective_from', 'effective_to'], 'employee_assignment_period_idx');
            $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('designation_id')->references('id')->on('designations')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('employee_salary_histories', function (Blueprint $t) {
            $t->id(); $t->integer('employee_id'); $t->decimal('monthly_salary', 15, 2);
            $t->date('effective_from'); $t->date('effective_to')->nullable(); $t->boolean('is_active')->default(true);
            $t->integer('created_by')->nullable(); $t->integer('updated_by')->nullable(); $t->timestamps();
            $t->index(['employee_id', 'effective_from', 'effective_to'], 'employee_salary_period_idx');
            $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('role_commission_rule_versions', function (Blueprint $t) {
            $t->id(); $t->integer('designation_id'); $t->unsignedInteger('version');
            $t->decimal('almadina_percentage', 9, 4)->default(0); $t->decimal('wholesale_percentage', 9, 4)->default(0); $t->decimal('minimum_percentage', 9, 4)->default(0);
            $t->boolean('is_active')->default(true); $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->boolean('overallocation_confirmed')->default(false); $t->integer('created_by')->nullable(); $t->timestamps();
            $t->unique(['designation_id', 'version'], 'role_commission_version_unique');
            $t->index(['designation_id', 'effective_from', 'effective_to'], 'role_commission_effective_idx');
            $t->foreign('designation_id')->references('id')->on('designations')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('payroll_periods', function (Blueprint $t) {
            $t->id(); $t->string('reference', 40)->unique(); $t->integer('warehouse_id');
            $t->unsignedSmallInteger('year'); $t->unsignedTinyInteger('month'); $t->date('period_start'); $t->date('period_end');
            $t->date('commission_cutoff_date'); $t->date('payment_date')->nullable(); $t->string('status', 32)->default('draft'); $t->text('note')->nullable();
            $t->integer('generated_by')->nullable(); $t->timestamp('generated_at')->nullable();
            $t->integer('approved_by')->nullable(); $t->timestamp('approved_at')->nullable();
            $t->integer('cancelled_by')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->timestamps();
            $t->unique(['warehouse_id', 'year', 'month'], 'payroll_branch_period_unique');
            $t->index(['status', 'year', 'month'], 'payroll_period_status_idx');
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            foreach (['generated_by', 'approved_by', 'cancelled_by'] as $column) {
                $t->foreign($column)->references('id')->on('users')->nullOnDelete();
            }
        });
        Schema::create('payroll_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('payroll_period_id'); $t->integer('employee_id'); $t->integer('warehouse_id'); $t->integer('designation_id');
            foreach (['basic_salary', 'commission', 'bonus', 'allowances', 'overtime', 'absence_deduction', 'deductions', 'salary_advance', 'loan_recovery', 'commission_adjustments', 'gross_salary', 'total_deductions', 'net_payable', 'paid_amount', 'remaining_amount'] as $column) {
                $t->decimal($column, 15, 2)->default(0);
            }
            $t->string('status', 32)->default('draft'); $t->timestamps();
            $t->unique(['payroll_period_id', 'employee_id'], 'payroll_period_employee_unique');
            $t->index(['warehouse_id', 'status'], 'payroll_item_branch_status_idx');
            $t->foreign('payroll_period_id')->references('id')->on('payroll_periods')->restrictOnDelete();
            $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('designation_id')->references('id')->on('designations')->restrictOnDelete();
        });
        Schema::create('payroll_item_components', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('payroll_item_id'); $t->string('type', 40); $t->string('label', 120); $t->decimal('amount', 15, 2);
            $t->text('note')->nullable(); $t->integer('created_by')->nullable(); $t->timestamps();
            $t->index(['payroll_item_id', 'type']);
            $t->foreign('payroll_item_id')->references('id')->on('payroll_items')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('employee_commission_entries', function (Blueprint $t) {
            $t->id(); $t->string('event_key', 191)->unique(); $t->string('entry_type', 20)->default('accrual');
            $t->unsignedBigInteger('original_entry_id')->nullable(); $t->integer('sale_id'); $t->integer('sale_detail_id');
            $t->integer('sale_return_id')->nullable(); $t->integer('sale_return_detail_id')->nullable();
            $t->integer('warehouse_id'); $t->integer('employee_id'); $t->integer('designation_id');
            $t->integer('product_id'); $t->integer('product_variant_id')->nullable(); $t->unsignedBigInteger('rule_version_id');
            $t->string('price_type', 24); $t->decimal('stored_profit_per_unit', 15, 4);
            $t->decimal('quantity', 15, 4); $t->decimal('total_applicable_profit', 15, 4);
            $t->decimal('commission_percentage', 9, 4); $t->decimal('commission_amount', 15, 4);
            $t->date('commission_date'); $t->string('status', 32)->default('accrued');
            $t->string('reason', 255)->nullable(); $t->integer('created_by')->nullable(); $t->timestamps();
            $t->index(['warehouse_id', 'commission_date', 'status'], 'commission_branch_date_status_idx');
            $t->index(['employee_id', 'commission_date', 'status'], 'commission_employee_date_status_idx');
            $t->index(['sale_id', 'sale_detail_id'], 'commission_sale_line_idx');
            $t->index(['product_id', 'price_type'], 'commission_product_price_idx');
            $t->foreign('original_entry_id')->references('id')->on('employee_commission_entries')->restrictOnDelete();
            $t->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
            $t->foreign('sale_return_id')->references('id')->on('sale_returns')->restrictOnDelete();
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $t->foreign('designation_id')->references('id')->on('designations')->restrictOnDelete();
            $t->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $t->foreign('rule_version_id')->references('id')->on('role_commission_rule_versions')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('payroll_commission_links', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('payroll_item_id');
            $t->unsignedBigInteger('commission_entry_id')->unique('commission_entry_payroll_unique');
            $t->decimal('amount', 15, 4); $t->timestamps();
            $t->foreign('payroll_item_id')->references('id')->on('payroll_items')->restrictOnDelete();
            $t->foreign('commission_entry_id')->references('id')->on('employee_commission_entries')->restrictOnDelete();
        });
        Schema::create('payroll_payments', function (Blueprint $t) {
            $t->id(); $t->string('reference', 50)->unique(); $t->unsignedBigInteger('payroll_item_id');
            $t->integer('account_id')->nullable(); $t->integer('payment_method_id'); $t->decimal('amount', 15, 2);
            $t->date('payment_date'); $t->string('transaction_reference', 120)->nullable(); $t->text('note')->nullable();
            $t->integer('processed_by')->nullable(); $t->timestamp('processed_at'); $t->timestamps();
            $t->index(['payroll_item_id', 'payment_date']);
            $t->foreign('payroll_item_id')->references('id')->on('payroll_items')->restrictOnDelete();
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign('payment_method_id')->references('id')->on('payment_methods')->restrictOnDelete();
            $t->foreign('processed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('payroll_adjustments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('payroll_item_id')->nullable(); $t->integer('employee_id');
            $t->integer('warehouse_id'); $t->string('type', 40); $t->decimal('amount', 15, 2);
            $t->string('status', 24)->default('pending'); $t->text('reason');
            $t->unsignedBigInteger('source_commission_entry_id')->nullable();
            $t->integer('created_by')->nullable(); $t->timestamps();
            $t->index(['employee_id', 'warehouse_id', 'status'], 'payroll_adjustment_open_idx');
            $t->foreign('payroll_item_id')->references('id')->on('payroll_items')->restrictOnDelete();
            $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('source_commission_entry_id')->references('id')->on('employee_commission_entries')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('payrolls', fn (Blueprint $t) => $t->boolean('is_legacy')->default(true)->after('payment_status')->index());

        $permissions = [
            'payroll_salary_view' => 'View basic salaries', 'payroll_salary_manage' => 'Manage basic salaries',
            'payroll_commission_rules_view' => 'View commission rules', 'payroll_commission_rules_manage' => 'Manage commission rules',
            'payroll_commission_ledger_view' => 'View commission ledger', 'payroll_generate' => 'Generate payroll',
            'payroll_approve' => 'Approve payroll', 'payroll_pay' => 'Pay payroll',
            'payroll_payments_view' => 'View payroll payments', 'payroll_payslip_print' => 'Print payslips',
            'payroll_all_branches' => 'View payroll for all branches', 'payroll_assigned_branch' => 'View assigned branch payroll',
        ];
        foreach ($permissions as $name => $label) {
            $id = DB::table('permissions')->where('name', $name)->value('id')
                ?: DB::table('permissions')->insertGetId(['name' => $name, 'label' => $label]);
            DB::table('permission_role')->updateOrInsert(['permission_id' => $id, 'role_id' => 1], []);
        }
    }

    public function down(): void
    {
        Schema::table('payrolls', fn (Blueprint $t) => $t->dropColumn('is_legacy'));
        foreach (['payroll_adjustments', 'payroll_payments', 'payroll_commission_links', 'employee_commission_entries', 'payroll_item_components', 'payroll_items', 'payroll_periods', 'role_commission_rule_versions', 'employee_salary_histories', 'employee_branch_assignments'] as $table) {
            Schema::dropIfExists($table);
        }
        $permissionIds = DB::table('permissions')->whereIn('name', [
            'payroll_salary_view', 'payroll_salary_manage', 'payroll_commission_rules_view',
            'payroll_commission_rules_manage', 'payroll_commission_ledger_view', 'payroll_generate',
            'payroll_approve', 'payroll_pay', 'payroll_payments_view', 'payroll_payslip_print',
            'payroll_all_branches', 'payroll_assigned_branch',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
