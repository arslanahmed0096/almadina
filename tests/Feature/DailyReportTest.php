<?php

namespace Tests\Feature;

use App\Services\Reports\DailyReportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (array_reverse(Schema::getTableListing()) as $table) {
            Schema::drop($table);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_it_builds_a_branch_daily_report_from_posted_cash_activity(): void
    {
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'Plaza Branch'],
            ['id' => 2, 'name' => 'Kamra Branch'],
        ]);
        DB::table('payment_methods')->insert([
            ['id' => 1, 'name' => 'Cash'],
            ['id' => 2, 'name' => 'Bank'],
        ]);
        DB::table('clients')->insert(['id' => 1, 'name' => 'Customer', 'opening_balance' => 500]);
        DB::table('providers')->insert([
            ['id' => 1, 'name' => 'Supplier', 'opening_balance' => 400],
            ['id' => 2, 'name' => 'Other Supplier', 'opening_balance' => 200],
        ]);
        DB::table('accounts')->insert(['id' => 1, 'balance' => 9999]);
        DB::table('expense_categories')->insert(['id' => 1, 'name' => 'Fuel']);

        DB::table('sales')->insert([
            ['id' => 1, 'date' => '2026-09-04', 'warehouse_id' => 1, 'GrandTotal' => 1000, 'paid_amount' => 600, 'statut' => 'completed'],
            ['id' => 2, 'date' => '2026-09-04', 'warehouse_id' => 1, 'GrandTotal' => 500, 'paid_amount' => 0, 'statut' => 'ordered'],
            ['id' => 3, 'date' => '2026-09-04', 'warehouse_id' => 2, 'GrandTotal' => 700, 'paid_amount' => 700, 'statut' => 'completed'],
        ]);
        DB::table('sale_returns')->insert(['id' => 1, 'date' => '2026-09-04', 'warehouse_id' => 1, 'client_id' => 1, 'GrandTotal' => 100, 'paid_amount' => 40, 'statut' => 'completed']);
        DB::table('cash_registers')->insert([
            'id' => 1, 'warehouse_id' => 1, 'opening_balance' => 200, 'cash_in' => 20, 'cash_out' => 10,
            'closing_balance' => 500, 'difference' => 5, 'status' => 'closed', 'opened_at' => '2026-09-04 08:00:00',
        ]);
        DB::table('payment_sales')->insert([
            'id' => 1, 'sale_id' => 1, 'date' => '2026-09-04', 'montant' => 600, 'payment_method_id' => 1,
        ]);
        DB::table('expenses')->insert([
            'id' => 1, 'date' => '2026-09-04', 'Ref' => 'EXP-1', 'details' => 'Branch fuel', 'amount' => 50,
            'warehouse_id' => 1, 'expense_category_id' => 1, 'payment_method_id' => 1,
        ]);
        DB::table('purchases')->insert([
            'id' => 1, 'date' => '2026-09-04', 'Ref' => 'PUR-1', 'warehouse_id' => 1, 'provider_id' => 1,
            'GrandTotal' => 300, 'paid_amount' => 200, 'statut' => 'received',
        ]);
        DB::table('payment_purchases')->insert([
            'id' => 1, 'purchase_id' => 1, 'date' => '2026-09-04', 'Ref' => 'PAY-P-1', 'montant' => 200,
            'payment_method_id' => 2, 'notes' => null,
        ]);
        DB::table('payment_sale_returns')->insert([
            'id' => 1, 'sale_return_id' => 1, 'date' => '2026-09-04', 'Ref' => 'PAY-SR-1', 'montant' => 40,
            'payment_method_id' => 1, 'notes' => null,
        ]);
        DB::table('purchase_returns')->insert([
            'id' => 1, 'date' => '2026-09-04', 'warehouse_id' => 1, 'provider_id' => 1, 'GrandTotal' => 100,
            'paid_amount' => 30, 'statut' => 'completed',
        ]);
        DB::table('payment_purchase_returns')->insert([
            'id' => 1, 'purchase_return_id' => 1, 'date' => '2026-09-04', 'montant' => 30,
            'payment_method_id' => 1,
        ]);
        DB::table('client_opening_balance_payments')->insert([
            'id' => 1, 'client_id' => 1, 'date' => '2026-09-04', 'Ref' => 'COB-1', 'montant' => 50,
            'payment_method_id' => 1, 'notes' => null,
        ]);
        DB::table('provider_opening_balance_payments')->insert([
            'id' => 1, 'provider_id' => 1, 'date' => '2026-09-04', 'Ref' => 'POB-1', 'montant' => 25,
            'payment_method_id' => 2, 'notes' => null,
        ]);

        $warehouses = DB::table('warehouses')->where('id', 1)->get();
        $report = app(DailyReportService::class)->build(Carbon::parse('2026-09-04'), $warehouses, false);

        $this->assertSame('Plaza Branch', $report['scope']);
        $this->assertSame(1000.0, $report['totals']['gross_sales']);
        $this->assertSame(100.0, $report['totals']['sale_returns']);
        $this->assertSame(900.0, $report['totals']['net_sales']);
        $this->assertSame(850.0, $report['totals']['cash_available']);
        $this->assertSame(300.0, $report['totals']['total_outflows']);
        $this->assertSame(550.0, $report['totals']['calculated_closing']);
        $this->assertSame(340.0, $report['totals']['customer_receivable']);
        $this->assertSame(30.0, $report['totals']['supplier_payable']);
        $this->assertNull($report['totals']['account_balances']);
        $this->assertCount(4, $report['outflows']);

        $cash = $report['payment_methods']->firstWhere('payment_method', 'Cash');
        $this->assertSame(650.0, $cash['inflow']);
        $this->assertSame(100.0, $cash['outflow']);
        $this->assertSame(550.0, $cash['net']);

        $allWarehouses = DB::table('warehouses')->orderBy('id')->get();
        $consolidated = app(DailyReportService::class)->build(Carbon::parse('2026-09-04'), $allWarehouses, true);
        $this->assertSame(1700.0, $consolidated['totals']['gross_sales']);
        $this->assertSame(900.0, $consolidated['totals']['cash_available']);
        $this->assertSame(325.0, $consolidated['totals']['total_outflows']);
        $this->assertSame(575.0, $consolidated['totals']['calculated_closing']);
        $this->assertSame(840.0, $consolidated['totals']['customer_receivable']);
        $this->assertSame(630.0, $consolidated['totals']['supplier_payable']);
        $this->assertSame(9999.0, $consolidated['totals']['account_balances']);
        $this->assertCount(5, $consolidated['outflows']);

        $supplierReport = app(DailyReportService::class)->build(Carbon::parse('2026-09-04'), $allWarehouses, true, 1);
        $this->assertSame('Supplier', $supplierReport['supplier_scope']);
        $this->assertSame(225.0, $supplierReport['totals']['supplier_payments']);
        $this->assertSame(430.0, $supplierReport['totals']['supplier_payable']);
    }

    private function createSchema(): void
    {
        Schema::create('warehouses', fn (Blueprint $t) => $this->base($t, fn () => $t->string('name')));
        Schema::create('payment_methods', fn (Blueprint $t) => $this->base($t, fn () => $t->string('name')));
        Schema::create('clients', fn (Blueprint $t) => $this->base($t, function () use ($t) { $t->string('name'); $t->decimal('opening_balance', 15, 2)->default(0); }));
        Schema::create('providers', fn (Blueprint $t) => $this->base($t, function () use ($t) { $t->string('name'); $t->decimal('opening_balance', 15, 2)->default(0); }));
        Schema::create('accounts', fn (Blueprint $t) => $this->base($t, fn () => $t->decimal('balance', 15, 2)->default(0)));
        Schema::create('expense_categories', fn (Blueprint $t) => $this->base($t, fn () => $t->string('name')));

        foreach (['sales', 'sale_returns', 'purchases', 'purchase_returns'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $this->base($t, function () use ($t, $table) {
                    $t->date('date'); $t->unsignedInteger('warehouse_id'); $t->decimal('GrandTotal', 15, 2);
                    $t->decimal('paid_amount', 15, 2)->default(0); $t->string('statut');
                    if ($table === 'sale_returns') $t->unsignedInteger('client_id')->nullable();
                    if (in_array($table, ['purchases', 'purchase_returns'], true)) $t->unsignedInteger('provider_id')->nullable();
                    if ($table === 'purchases') $t->string('Ref')->nullable();
                });
            });
        }

        Schema::create('cash_registers', function (Blueprint $t) {
            $this->base($t, function () use ($t) {
                $t->unsignedInteger('warehouse_id'); $t->decimal('opening_balance', 15, 2); $t->decimal('cash_in', 15, 2)->default(0);
                $t->decimal('cash_out', 15, 2)->default(0); $t->decimal('closing_balance', 15, 2)->nullable();
                $t->decimal('difference', 15, 2)->nullable(); $t->string('status'); $t->dateTime('opened_at');
            });
        });
        Schema::create('expenses', function (Blueprint $t) {
            $this->base($t, function () use ($t) {
                $t->date('date'); $t->string('Ref')->nullable(); $t->string('details')->nullable(); $t->decimal('amount', 15, 2);
                $t->unsignedInteger('warehouse_id'); $t->unsignedInteger('expense_category_id')->nullable(); $t->unsignedInteger('payment_method_id')->nullable();
            });
        });

        $this->paymentTable('payment_sales', 'sale_id');
        $this->paymentTable('payment_purchases', 'purchase_id', true);
        $this->paymentTable('payment_sale_returns', 'sale_return_id', true);
        $this->paymentTable('payment_purchase_returns', 'purchase_return_id');
        $this->openingBalancePaymentTable('client_opening_balance_payments', 'client_id');
        $this->openingBalancePaymentTable('provider_opening_balance_payments', 'provider_id');
    }

    private function paymentTable(string $table, string $parentColumn, bool $withReference = false): void
    {
        Schema::create($table, function (Blueprint $t) use ($parentColumn, $withReference) {
            $this->base($t, function () use ($t, $parentColumn, $withReference) {
                $t->unsignedInteger($parentColumn); $t->date('date'); $t->decimal('montant', 15, 2);
                $t->unsignedInteger('payment_method_id')->nullable();
                if ($withReference) { $t->string('Ref')->nullable(); $t->text('notes')->nullable(); }
            });
        });
    }

    private function openingBalancePaymentTable(string $table, string $parentColumn): void
    {
        Schema::create($table, function (Blueprint $t) use ($parentColumn) {
            $this->base($t, function () use ($t, $parentColumn) {
                $t->unsignedInteger($parentColumn); $t->date('date'); $t->string('Ref')->nullable();
                $t->decimal('montant', 15, 2); $t->unsignedInteger('payment_method_id')->nullable(); $t->text('notes')->nullable();
            });
        });
    }

    private function base(Blueprint $table, callable $columns): void
    {
        $table->increments('id');
        $columns();
        $table->timestamps();
        $table->softDeletes();
    }
}
