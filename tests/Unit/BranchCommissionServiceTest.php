<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\BranchCommissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchCommissionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('warehouses', fn (Blueprint $t) => $this->base($t, ['name']));
        Schema::create('designations', fn (Blueprint $t) => $this->base($t, ['designation']));
        Schema::create('employees', function (Blueprint $t) {
            $this->base($t, ['username']); $t->date('joining_date')->nullable(); $t->date('leaving_date')->nullable(); $t->integer('designation_id');
        });
        Schema::create('products', function (Blueprint $t) { $this->base($t, ['name']); $t->json('pricing_margins')->nullable(); });
        Schema::create('product_variants', function (Blueprint $t) { $this->base($t, ['name']); $t->integer('product_id'); $t->json('pricing_margins')->nullable(); });
        Schema::create('sales', function (Blueprint $t) { $this->base($t, ['Ref', 'statut']); $t->date('date'); $t->integer('warehouse_id'); $t->integer('user_id')->nullable(); $t->integer('sales_agent_id')->nullable(); });
        Schema::create('sale_details', function (Blueprint $t) {
            $this->base($t); $t->integer('sale_id'); $t->integer('product_id'); $t->integer('product_variant_id')->nullable();
            $t->decimal('quantity', 15, 4); $t->string('price_type'); $t->decimal('total', 15, 2)->default(0);
        });
        Schema::create('employee_branch_assignments', function (Blueprint $t) {
            $this->base($t); $t->integer('employee_id'); $t->integer('warehouse_id'); $t->integer('designation_id');
            $t->date('effective_from'); $t->date('effective_to')->nullable(); $t->boolean('is_active')->default(true);
        });
        Schema::create('role_commission_rule_versions', function (Blueprint $t) {
            $this->base($t); $t->integer('designation_id'); $t->integer('version'); $t->decimal('almadina_percentage', 9, 4);
            $t->decimal('wholesale_percentage', 9, 4); $t->decimal('minimum_percentage', 9, 4); $t->boolean('is_active');
            $t->date('effective_from'); $t->date('effective_to')->nullable(); $t->boolean('overallocation_confirmed')->default(false);
        });
        Schema::create('employee_commission_entries', function (Blueprint $t) {
            $this->base($t); $t->string('event_key')->unique(); $t->string('entry_type'); $t->integer('original_entry_id')->nullable();
            foreach (['sale_id','sale_detail_id','sale_return_id','sale_return_detail_id','warehouse_id','employee_id','designation_id','product_id','product_variant_id','rule_version_id','created_by'] as $c) $t->integer($c)->nullable();
            $t->string('price_type'); $t->decimal('stored_profit_per_unit',15,4); $t->decimal('quantity',15,4);
            $t->decimal('total_applicable_profit',15,4); $t->decimal('commission_percentage',9,4); $t->decimal('commission_amount',15,4);
            $t->date('commission_date'); $t->string('status'); $t->string('reason')->nullable();
        });
        Schema::create('sale_returns', function (Blueprint $t) { $this->base($t, ['Ref','statut']); $t->integer('sale_id'); $t->date('date'); });
        Schema::create('sale_return_details', function (Blueprint $t) { $this->base($t); $t->integer('sale_return_id'); $t->integer('sale_detail_id'); $t->decimal('quantity',15,4); });
        Schema::create('payroll_items', function (Blueprint $t) { $this->base($t); $t->string('status'); $t->decimal('paid_amount',15,2)->default(0); });
        Schema::create('payroll_commission_links', function (Blueprint $t) { $this->base($t); $t->integer('payroll_item_id'); $t->integer('commission_entry_id')->unique(); $t->decimal('amount',15,4); });
        $this->seedBase();
    }

    public function test_kamra_sale_pays_only_active_kamra_employees_at_full_role_rate_and_is_idempotent(): void
    {
        $sale = $this->sale('completed', 1, 'retail', 2, '2026-09-10');
        $service = app(BranchCommissionService::class);
        $created = $service->generateForCompletedSale($sale);
        $this->assertCount(2, $created);
        $this->assertSame([1, 2], DB::table('employee_commission_entries')->pluck('employee_id')->sort()->values()->all());
        $this->assertSame(800.0, (float) DB::table('employee_commission_entries')->where('employee_id', 1)->value('commission_amount'));
        $this->assertSame(40000.0, (float) DB::table('employee_commission_entries')->where('employee_id', 1)->value('total_applicable_profit'));
        $this->assertCount(0, $service->generateForCompletedSale($sale));
        $this->assertSame(2, DB::table('employee_commission_entries')->count());
    }

    public function test_all_three_supported_price_types_select_the_correct_stored_profit(): void
    {
        foreach ([['retail', 20000], ['wholesale', 10000], ['minimum', 5000]] as $i => [$type, $profit]) {
            $sale = $this->sale('completed', 1, $type, 1, '2026-09-'.(11 + $i));
            app(BranchCommissionService::class)->generateForCompletedSale($sale);
            $this->assertSame((float) $profit, (float) DB::table('employee_commission_entries')->where('sale_id', $sale->id)->first()->stored_profit_per_unit);
        }
    }

    public function test_draft_sales_and_inactive_or_other_branch_employees_receive_nothing(): void
    {
        $sale = $this->sale('draft', 1, 'retail', 1, '2026-09-10');
        $this->assertSame([], app(BranchCommissionService::class)->generateForCompletedSale($sale));
        $this->assertDatabaseMissing('employee_commission_entries', ['employee_id' => 3]);
        $this->assertDatabaseMissing('employee_commission_entries', ['employee_id' => 4]);
    }

    public function test_branch_transfer_effective_date_is_respected(): void
    {
        DB::table('employee_branch_assignments')->where('employee_id', 1)->update(['effective_to' => '2026-09-15']);
        DB::table('employee_branch_assignments')->insert($this->row(['employee_id'=>1,'warehouse_id'=>2,'designation_id'=>1,'effective_from'=>'2026-09-16','effective_to'=>null,'is_active'=>1]));
        app(BranchCommissionService::class)->generateForCompletedSale($this->sale('completed', 1, 'retail', 1, '2026-09-20'));
        $this->assertDatabaseMissing('employee_commission_entries', ['employee_id' => 1]);
        app(BranchCommissionService::class)->generateForCompletedSale($this->sale('completed', 2, 'retail', 1, '2026-09-20'));
        $this->assertDatabaseHas('employee_commission_entries', ['employee_id' => 1, 'warehouse_id' => 2]);
    }

    public function test_product_profit_and_rule_changes_do_not_change_historical_snapshot(): void
    {
        $sale = $this->sale('completed', 1, 'retail', 1, '2026-09-10');
        app(BranchCommissionService::class)->generateForCompletedSale($sale);
        DB::table('products')->where('id', 1)->update(['pricing_margins' => json_encode($this->margins(1, 2, 3))]);
        DB::table('role_commission_rule_versions')->where('id', 1)->update(['almadina_percentage' => 99]);
        $entry = DB::table('employee_commission_entries')->where(['sale_id'=>$sale->id,'employee_id'=>1])->first();
        $this->assertSame(20000.0, (float) $entry->stored_profit_per_unit);
        $this->assertSame(2.0, (float) $entry->commission_percentage);
        $this->assertSame(400.0, (float) $entry->commission_amount);
    }

    public function test_partial_and_full_returns_create_auditable_snapshot_reversals(): void
    {
        $sale = $this->sale('completed', 1, 'retail', 2, '2026-09-10');
        app(BranchCommissionService::class)->generateForCompletedSale($sale);
        $returnId = DB::table('sale_returns')->insertGetId($this->row(['Ref'=>'RT-1','statut'=>'received','sale_id'=>$sale->id,'date'=>'2026-09-12']));
        DB::table('sale_return_details')->insert($this->row(['sale_return_id'=>$returnId,'sale_detail_id'=>$sale->details()->first()->id,'quantity'=>1]));
        app(BranchCommissionService::class)->reverseForReturn(SaleReturn::find($returnId));
        $this->assertSame(-400.0, (float) DB::table('employee_commission_entries')->where(['entry_type'=>'reversal','employee_id'=>1])->value('commission_amount'));
        $this->assertDatabaseHas('employee_commission_entries', ['entry_type'=>'reversal','sale_return_id'=>$returnId,'reason'=>'Sale return RT-1']);
        app(BranchCommissionService::class)->reverseForCancellation($sale);
        $this->assertSame('reversed', DB::table('employee_commission_entries')->where(['entry_type'=>'accrual','employee_id'=>1])->value('status'));
    }

    public function test_plaza_employees_do_not_receive_kamra_commission(): void
    {
        app(BranchCommissionService::class)->generateForCompletedSale($this->sale('completed',1,'retail',1,'2026-09-10'));
        $this->assertDatabaseMissing('employee_commission_entries',['employee_id'=>3]);
    }
    public function test_each_eligible_employee_receives_their_complete_role_percentage(): void
    {
        app(BranchCommissionService::class)->generateForCompletedSale($this->sale('completed',1,'retail',1,'2026-09-10'));
        $this->assertSame(400.0,(float)DB::table('employee_commission_entries')->where('employee_id',1)->value('commission_amount'));
        $this->assertSame(200.0,(float)DB::table('employee_commission_entries')->where('employee_id',2)->value('commission_amount'));
    }
    public function test_wholesale_commission_uses_wholesale_profit(): void
    {
        $sale=$this->sale('completed',1,'wholesale',1,'2026-09-10');app(BranchCommissionService::class)->generateForCompletedSale($sale);
        $this->assertSame(150.0,(float)DB::table('employee_commission_entries')->where(['sale_id'=>$sale->id,'employee_id'=>1])->value('commission_amount'));
    }
    public function test_minimum_commission_uses_minimum_profit(): void
    {
        $sale=$this->sale('completed',1,'minimum',1,'2026-09-10');app(BranchCommissionService::class)->generateForCompletedSale($sale);
        $this->assertSame(50.0,(float)DB::table('employee_commission_entries')->where(['sale_id'=>$sale->id,'employee_id'=>1])->value('commission_amount'));
    }
    public function test_quantity_multiplies_stored_profit_before_percentage(): void
    {
        $sale=$this->sale('completed',1,'retail',3,'2026-09-10');app(BranchCommissionService::class)->generateForCompletedSale($sale);
        $this->assertSame(1200.0,(float)DB::table('employee_commission_entries')->where(['sale_id'=>$sale->id,'employee_id'=>1])->value('commission_amount'));
    }
    public function test_cancelled_sale_generates_no_commission(): void
    {
        $this->assertSame([],app(BranchCommissionService::class)->generateForCompletedSale($this->sale('cancelled',1,'retail',1,'2026-09-10')));
    }
    public function test_employee_inactive_on_sale_date_is_excluded(): void
    {
        app(BranchCommissionService::class)->generateForCompletedSale($this->sale('completed',1,'retail',1,'2026-09-10'));
        $this->assertDatabaseMissing('employee_commission_entries',['employee_id'=>4]);
    }
    public function test_full_return_reverses_full_original_commission(): void
    {
        $sale=$this->sale('completed',1,'retail',2,'2026-09-10');app(BranchCommissionService::class)->generateForCompletedSale($sale);
        $rid=DB::table('sale_returns')->insertGetId($this->row(['Ref'=>'RT-F','statut'=>'received','sale_id'=>$sale->id,'date'=>'2026-09-12']));
        DB::table('sale_return_details')->insert($this->row(['sale_return_id'=>$rid,'sale_detail_id'=>$sale->details()->first()->id,'quantity'=>2]));
        app(BranchCommissionService::class)->reverseForReturn(SaleReturn::find($rid));
        $this->assertSame(-800.0,(float)DB::table('employee_commission_entries')->where(['entry_type'=>'reversal','employee_id'=>1])->value('commission_amount'));
    }
    public function test_sale_cancellation_reverses_unreturned_balance_only(): void
    {
        $sale=$this->sale('completed',1,'retail',2,'2026-09-10');app(BranchCommissionService::class)->generateForCompletedSale($sale);
        app(BranchCommissionService::class)->reverseForCancellation($sale);
        $this->assertSame(-800.0,(float)DB::table('employee_commission_entries')->where(['entry_type'=>'reversal','employee_id'=>1])->value('commission_amount'));
    }

    private function seedBase(): void
    {
        DB::table('warehouses')->insert([$this->row(['id'=>1,'name'=>'Kamra']),$this->row(['id'=>2,'name'=>'Plaza'])]);
        DB::table('designations')->insert([$this->row(['id'=>1,'designation'=>'Manager']),$this->row(['id'=>2,'designation'=>'Cashier'])]);
        DB::table('employees')->insert([
            $this->row(['id'=>1,'username'=>'Kamra Manager','designation_id'=>1,'joining_date'=>'2026-01-01','leaving_date'=>null]),
            $this->row(['id'=>2,'username'=>'Kamra Cashier','designation_id'=>2,'joining_date'=>'2026-01-01','leaving_date'=>null]),
            $this->row(['id'=>3,'username'=>'Plaza Manager','designation_id'=>1,'joining_date'=>'2026-01-01','leaving_date'=>null]),
            $this->row(['id'=>4,'username'=>'Inactive','designation_id'=>1,'joining_date'=>'2026-01-01','leaving_date'=>'2026-08-31']),
        ]);
        foreach ([[1,1,1],[2,1,2],[3,2,1],[4,1,1]] as [$e,$w,$d]) DB::table('employee_branch_assignments')->insert($this->row(['employee_id'=>$e,'warehouse_id'=>$w,'designation_id'=>$d,'effective_from'=>'2026-01-01','effective_to'=>null,'is_active'=>1]));
        DB::table('role_commission_rule_versions')->insert([
            $this->row(['id'=>1,'designation_id'=>1,'version'=>1,'almadina_percentage'=>2,'wholesale_percentage'=>1.5,'minimum_percentage'=>1,'is_active'=>1,'effective_from'=>'2026-01-01','effective_to'=>null,'overallocation_confirmed'=>0]),
            $this->row(['id'=>2,'designation_id'=>2,'version'=>1,'almadina_percentage'=>1,'wholesale_percentage'=>.75,'minimum_percentage'=>.5,'is_active'=>1,'effective_from'=>'2026-01-01','effective_to'=>null,'overallocation_confirmed'=>0]),
        ]);
        DB::table('products')->insert($this->row(['id'=>1,'name'=>'TV','pricing_margins'=>json_encode($this->margins(5000,10000,20000))]));
    }

    private function sale(string $status, int $warehouse, string $type, float $quantity, string $date): Sale
    {
        $id = DB::table('sales')->insertGetId($this->row(['Ref'=>'SL-'.uniqid(),'statut'=>$status,'date'=>$date,'warehouse_id'=>$warehouse,'user_id'=>null,'sales_agent_id'=>null]));
        DB::table('sale_details')->insert($this->row(['sale_id'=>$id,'product_id'=>1,'product_variant_id'=>null,'quantity'=>$quantity,'price_type'=>$type,'total'=>1]));
        return Sale::findOrFail($id);
    }
    private function margins($minimum,$wholesale,$almadina): array { return [['profit'=>$minimum],['profit'=>$wholesale],['profit'=>$almadina]]; }
    private function row(array $data): array { return $data + ['created_at'=>now(),'updated_at'=>now()]; }
    private function base(Blueprint $t, array $strings=[]): void { $t->increments('id'); foreach($strings as $s)$t->string($s); $t->timestamps(); $t->softDeletes(); }
}
