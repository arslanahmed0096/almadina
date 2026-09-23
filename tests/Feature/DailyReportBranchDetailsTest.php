<?php

namespace Tests\Feature;

use App\Services\Reports\DailyReportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyReportBranchDetailsTest extends TestCase
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

    public function test_it_shows_later_customer_payments_on_the_receipt_date_with_remaining_balance(): void
    {
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'Plaza Branch']);
        DB::table('users')->insert(['id' => 1, 'username' => 'cashier']);
        DB::table('sales_agents')->insert(['id' => 1, 'name' => 'Sales Person']);
        DB::table('clients')->insert([
            'id' => 1, 'name' => 'Refrigerator Customer', 'phone' => '03001234567', 'adresse' => 'Customer address',
        ]);
        DB::table('payment_methods')->insert(['id' => 1, 'name' => 'Cash']);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Refrigerator Model RF-120', 'code' => 'INT-001'],
            ['id' => 2, 'name' => 'Air Conditioner Model AC-2', 'code' => 'INT-002'],
        ]);

        DB::table('sales')->insert([
            [
                'id' => 1, 'user_id' => 1, 'sales_agent_id' => 1, 'client_id' => 1, 'warehouse_id' => 1,
                'date' => '2026-08-01', 'time' => '10:00:00', 'Ref' => 'SL-OLD', 'GrandTotal' => 120000,
                'paid_amount' => 80000, 'payment_statut' => 'partial', 'statut' => 'completed',
                'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-09-01 11:00:00',
            ],
            [
                'id' => 2, 'user_id' => 1, 'sales_agent_id' => 1, 'client_id' => 1, 'warehouse_id' => 1,
                'date' => '2026-09-01', 'time' => '12:00:00', 'Ref' => 'ORD-TODAY', 'GrandTotal' => 60000,
                'paid_amount' => 10000, 'payment_statut' => 'partial', 'statut' => 'ordered',
                'created_at' => '2026-09-01 12:00:00', 'updated_at' => '2026-09-01 12:00:00',
            ],
        ]);
        DB::table('sale_details')->insert([
            ['id' => 1, 'sale_id' => 1, 'product_id' => 1, 'product_variant_id' => null, 'quantity' => 1, 'price' => 120000, 'total' => 120000],
            ['id' => 2, 'sale_id' => 2, 'product_id' => 2, 'product_variant_id' => null, 'quantity' => 2, 'price' => 30000, 'total' => 60000],
        ]);
        DB::table('payment_sales')->insert([
            [
                'id' => 1, 'sale_id' => 1, 'user_id' => 1, 'payment_method_id' => 1, 'Ref' => 'PAY-INITIAL',
                'date' => '2026-08-01', 'montant' => 30000, 'allocation_type' => 'current',
                'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00',
            ],
            [
                'id' => 2, 'sale_id' => 1, 'user_id' => 1, 'payment_method_id' => 1, 'Ref' => 'PAY-LATER',
                'date' => '2026-09-01', 'montant' => 50000, 'allocation_type' => null,
                'created_at' => '2026-09-01 11:00:00', 'updated_at' => '2026-09-01 11:00:00',
            ],
            [
                'id' => 3, 'sale_id' => 2, 'user_id' => 1, 'payment_method_id' => 1, 'Ref' => 'PAY-ADVANCE',
                'date' => '2026-09-01', 'montant' => 10000, 'allocation_type' => 'advance',
                'created_at' => '2026-09-01 12:00:00', 'updated_at' => '2026-09-01 12:00:00',
            ],
        ]);

        $details = app(DailyReportService::class)->branchDetails(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-01'),
            1
        );

        $this->assertCount(1, $details['transactions']);
        $this->assertSame('Order', $details['transactions'][0]['transaction_type']);
        $this->assertSame('Sales Person', $details['transactions'][0]['sold_by']);
        $this->assertSame(2.0, $details['transactions'][0]['quantity']);

        $this->assertCount(2, $details['receipts']);
        $laterPayment = $details['receipts']->firstWhere('receipt_reference', 'PAY-LATER');
        $this->assertSame('Previous balance payment', $laterPayment['receipt_type']);
        $this->assertSame(50000.0, $laterPayment['amount']);
        $this->assertSame(40000.0, $laterPayment['remaining_after_receipt']);
        $this->assertSame('Refrigerator Model RF-120', $laterPayment['items'][0]['model']);

        $advance = $details['receipts']->firstWhere('receipt_reference', 'PAY-ADVANCE');
        $this->assertSame('Advance payment', $advance['receipt_type']);
        $this->assertSame(50000.0, $advance['remaining_after_receipt']);

        $this->assertSame(60000.0, $details['totals']['payments_received']);
        $this->assertSame(50000.0, $details['totals']['previous_balance_received']);
        $this->assertSame(10000.0, $details['totals']['advance_received']);
        $this->assertSame(90000.0, $details['totals']['current_outstanding']);
    }

    private function createSchema(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id'); $table->string('username'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('sales_agents', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('clients', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->string('phone')->nullable();
            $table->string('adresse')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->string('code'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('product_variants', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('sales', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('user_id')->nullable(); $table->unsignedInteger('sales_agent_id')->nullable();
            $table->unsignedInteger('client_id'); $table->unsignedInteger('warehouse_id'); $table->date('date'); $table->time('time')->nullable();
            $table->string('Ref'); $table->decimal('GrandTotal', 15, 2); $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('payment_statut'); $table->string('statut'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('sale_details', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('sale_id'); $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variant_id')->nullable(); $table->decimal('quantity', 15, 2);
            $table->decimal('price', 15, 2); $table->decimal('total', 15, 2); $table->timestamps();
        });
        Schema::create('payment_sales', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('sale_id'); $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('payment_method_id')->nullable(); $table->string('Ref')->nullable(); $table->date('date');
            $table->decimal('montant', 15, 2); $table->string('allocation_type')->nullable();
            $table->string('source_sale_ref')->nullable(); $table->timestamps(); $table->softDeletes();
        });
    }
}
