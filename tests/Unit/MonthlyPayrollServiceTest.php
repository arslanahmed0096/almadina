<?php

namespace Tests\Unit;

use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Services\MonthlyPayrollService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonthlyPayrollServiceTest extends TestCase
{
    private MonthlyPayrollService $service;
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('warehouses', fn (Blueprint $t) => $this->named($t, 'name'));
        Schema::create('designations', fn (Blueprint $t) => $this->named($t, 'designation'));
        Schema::create('employees', function (Blueprint $t) { $this->named($t, 'username'); $t->date('joining_date')->nullable(); $t->date('leaving_date')->nullable(); });
        Schema::create('employee_branch_assignments', function (Blueprint $t) { $this->plain($t); foreach(['employee_id','warehouse_id','designation_id'] as $c)$t->integer($c); $t->date('effective_from'); $t->date('effective_to')->nullable(); $t->boolean('is_active'); });
        Schema::create('employee_salary_histories', function (Blueprint $t) { $this->plain($t); $t->integer('employee_id'); $t->decimal('monthly_salary',15,2); $t->date('effective_from'); $t->date('effective_to')->nullable(); $t->boolean('is_active'); });
        Schema::create('employee_commission_entries', function (Blueprint $t) { $this->plain($t); $t->integer('warehouse_id'); $t->integer('employee_id'); $t->string('entry_type'); $t->decimal('commission_amount',15,4); $t->date('commission_date'); $t->string('status'); });
        Schema::create('payroll_periods', function (Blueprint $t) {
            $this->plain($t); $t->string('reference')->unique(); $t->integer('warehouse_id'); $t->integer('year'); $t->integer('month');
            $t->date('period_start'); $t->date('period_end'); $t->date('commission_cutoff_date'); $t->date('payment_date')->nullable();
            $t->string('status'); $t->text('note')->nullable(); $t->integer('generated_by')->nullable(); $t->dateTime('generated_at')->nullable();
            $t->integer('approved_by')->nullable(); $t->dateTime('approved_at')->nullable(); $t->integer('cancelled_by')->nullable(); $t->dateTime('cancelled_at')->nullable();
        });
        Schema::create('payroll_items', function (Blueprint $t) {
            $this->plain($t); foreach(['payroll_period_id','employee_id','warehouse_id','designation_id'] as $c)$t->integer($c);
            foreach(['basic_salary','commission','bonus','allowances','overtime','absence_deduction','deductions','salary_advance','loan_recovery','commission_adjustments','gross_salary','total_deductions','net_payable','paid_amount','remaining_amount'] as $c)$t->decimal($c,15,2)->default(0);
            $t->string('status');
        });
        Schema::create('payroll_item_components', function (Blueprint $t) { $this->plain($t); $t->integer('payroll_item_id'); $t->string('type'); $t->string('label'); $t->decimal('amount',15,2); $t->text('note')->nullable(); $t->integer('created_by')->nullable(); });
        Schema::create('payroll_commission_links', function (Blueprint $t) { $this->plain($t); $t->integer('payroll_item_id'); $t->integer('commission_entry_id')->unique(); $t->decimal('amount',15,4); });
        Schema::create('payment_methods', fn (Blueprint $t) => $this->named($t, 'name'));
        Schema::create('accounts', function (Blueprint $t) { $this->named($t, 'account_name'); $t->string('account_type'); $t->decimal('balance',15,2); });
        Schema::create('payroll_payments', function (Blueprint $t) { $this->plain($t); $t->string('reference')->unique(); $t->integer('payroll_item_id'); $t->integer('account_id')->nullable(); $t->integer('payment_method_id'); $t->decimal('amount',15,2); $t->date('payment_date'); $t->string('transaction_reference')->nullable(); $t->text('note')->nullable(); $t->integer('processed_by')->nullable(); $t->dateTime('processed_at'); });
        $this->seedPayrollData();
        $this->service = app(MonthlyPayrollService::class);
        $this->data = ['month'=>9,'year'=>2026,'warehouse_id'=>1,'commission_cutoff_date'=>'2026-09-30','payment_date'=>'2026-09-30','note'=>null,'items'=>[]];
    }

    public function test_preview_uses_effective_salary_and_only_same_branch_month_commission(): void
    {
        $rows = $this->service->preview($this->data);
        $this->assertCount(1, $rows); $this->assertSame(30000.0, $rows[0]['basic_salary']); $this->assertSame(800.0, $rows[0]['commission']);
    }
    public function test_previous_paid_payroll_reversal_is_carried_into_next_open_payroll(): void
    {
        DB::table('employee_commission_entries')->insert($this->row(['warehouse_id'=>1,'employee_id'=>1,'entry_type'=>'reversal','commission_amount'=>-200,'commission_date'=>'2026-08-20','status'=>'accrued']));
        $row = $this->service->preview($this->data)[0]; $this->assertSame(-200.0, $row['commission_adjustments']); $this->assertSame(30600.0, $row['net_payable']);
    }
    public function test_generation_links_each_commission_once_and_snapshots_components(): void
    {
        $period = $this->service->generate($this->data, 1);
        $this->assertSame(1, $period->items()->count()); $this->assertSame(1, DB::table('payroll_commission_links')->count());
        $this->assertSame(10, DB::table('payroll_item_components')->count()); $this->assertSame('included_in_payroll', DB::table('employee_commission_entries')->where('id',1)->value('status'));
    }
    public function test_linked_commission_is_not_available_to_another_payroll(): void
    {
        $this->service->generate($this->data, 1); $data=$this->data; $data['month']=10; $data['commission_cutoff_date']='2026-10-31';
        $this->assertSame(0.0, $this->service->preview($data)[0]['commission']);
    }
    public function test_duplicate_branch_month_payroll_is_prevented(): void
    {
        $this->service->generate($this->data, 1); $this->expectException(ValidationException::class); $this->service->generate($this->data, 1);
    }
    public function test_approval_changes_draft_items_to_approved_unpaid(): void
    {
        $period=$this->service->generate($this->data,1); $this->service->approve($period,2);
        $this->assertSame('approved_unpaid', $period->fresh()->status); $this->assertSame('approved_unpaid', $period->items()->first()->status);
    }
    public function test_partial_payment_updates_status_and_accounting_balance(): void
    {
        $item=$this->approvedItem(); $this->service->pay($item,['amount'=>10000,'payment_date'=>'2026-09-30','payment_method_id'=>2,'account_id'=>1],3);
        $this->assertSame('partially_paid',$item->fresh()->status); $this->assertSame(90000.0,(float)DB::table('accounts')->where('id',1)->value('balance')); $this->assertDatabaseHas('payroll_payments',['amount'=>10000]);
    }
    public function test_full_payment_marks_item_period_and_commissions_paid(): void
    {
        $item=$this->approvedItem(); $this->service->pay($item,['amount'=>(float)$item->remaining_amount,'payment_date'=>'2026-09-30','payment_method_id'=>1,'account_id'=>null],3);
        $this->assertSame('paid',$item->fresh()->status); $this->assertSame('paid',$item->period->fresh()->status); $this->assertSame('paid',DB::table('employee_commission_entries')->where('id',1)->value('status'));
    }
    public function test_payment_cannot_exceed_remaining_amount_and_rolls_back(): void
    {
        $item=$this->approvedItem(); try{$this->service->pay($item,['amount'=>999999,'payment_date'=>'2026-09-30','payment_method_id'=>2,'account_id'=>1],3);$this->fail();}catch(ValidationException $e){}
        $this->assertSame(0,DB::table('payroll_payments')->count()); $this->assertSame(100000.0,(float)DB::table('accounts')->where('id',1)->value('balance'));
    }
    public function test_unapproved_payroll_cannot_be_paid(): void
    {
        $item=$this->service->generate($this->data,1)->items()->first(); $this->expectException(ValidationException::class);
        $this->service->pay($item,['amount'=>1,'payment_date'=>'2026-09-30','payment_method_id'=>1,'account_id'=>null],3);
    }

    private function approvedItem(): PayrollItem { $p=$this->service->generate($this->data,1);$this->service->approve($p,2);return $p->items()->first()->fresh('period'); }
    private function seedPayrollData(): void
    {
        DB::table('warehouses')->insert([$this->row(['id'=>1,'name'=>'Kamra']),$this->row(['id'=>2,'name'=>'Plaza'])]);
        DB::table('designations')->insert($this->row(['id'=>1,'designation'=>'Salesman']));
        DB::table('employees')->insert($this->row(['id'=>1,'username'=>'Ali','joining_date'=>'2026-01-01','leaving_date'=>null]));
        DB::table('employee_branch_assignments')->insert($this->row(['employee_id'=>1,'warehouse_id'=>1,'designation_id'=>1,'effective_from'=>'2026-01-01','effective_to'=>null,'is_active'=>1]));
        DB::table('employee_salary_histories')->insert([$this->row(['employee_id'=>1,'monthly_salary'=>25000,'effective_from'=>'2026-01-01','effective_to'=>'2026-08-31','is_active'=>1]),$this->row(['employee_id'=>1,'monthly_salary'=>30000,'effective_from'=>'2026-09-01','effective_to'=>null,'is_active'=>1])]);
        DB::table('employee_commission_entries')->insert([$this->row(['id'=>1,'warehouse_id'=>1,'employee_id'=>1,'entry_type'=>'accrual','commission_amount'=>800,'commission_date'=>'2026-09-10','status'=>'accrued']),$this->row(['id'=>2,'warehouse_id'=>2,'employee_id'=>1,'entry_type'=>'accrual','commission_amount'=>900,'commission_date'=>'2026-09-10','status'=>'accrued']),$this->row(['id'=>3,'warehouse_id'=>1,'employee_id'=>1,'entry_type'=>'accrual','commission_amount'=>700,'commission_date'=>'2026-08-10','status'=>'accrued'])]);
        DB::table('payment_methods')->insert([$this->row(['id'=>1,'name'=>'Cash']),$this->row(['id'=>2,'name'=>'bank transfer'])]);
        DB::table('accounts')->insert($this->row(['id'=>1,'account_name'=>'Bank','account_type'=>'bank','balance'=>100000]));
    }
    private function row(array $x): array{return $x+['created_at'=>now(),'updated_at'=>now()];}
    private function plain(Blueprint $t): void{$t->increments('id');$t->timestamps();}
    private function named(Blueprint $t,string $name): void{$this->plain($t);$t->string($name);$t->softDeletes();}
}
