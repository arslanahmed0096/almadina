<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientController;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\CustomerCreditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerSaleBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clients', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->softDeletes();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->string('Ref');
            $table->date('date');
            $table->decimal('GrandTotal', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('statut');
            $table->softDeletes();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('sale_id');
            $table->unsignedInteger('client_id');
            $table->decimal('GrandTotal', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->softDeletes();
        });

        Gate::before(fn () => true);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('clients');
        parent::tearDown();
    }

    public function test_sale_balance_combines_opening_balance_with_completed_invoice_due(): void
    {
        DB::table('clients')->insert([
            'id' => 1,
            'opening_balance' => 46121,
            'credit_limit' => 100000,
        ]);

        DB::table('sales')->insert([
            [
                'id' => 1,
                'client_id' => 1,
                'Ref' => 'SL_COMPLETED',
                'date' => '2026-10-14',
                'GrandTotal' => 12000,
                'paid_amount' => 2000,
                'statut' => 'completed',
            ],
            [
                'id' => 2,
                'client_id' => 1,
                'Ref' => 'SL_ORDERED',
                'date' => '2026-10-15',
                'GrandTotal' => 5000,
                'paid_amount' => 0,
                'statut' => 'ordered',
            ],
        ]);

        $request = Request::create('/api/clients/1/sale-balance', 'GET');
        $request->setUserResolver(fn () => new User);

        $response = (new ClientController)->saleBalance(
            $request,
            1,
            app(CustomerCreditService::class)
        );
        $data = $response->getData(true);

        $this->assertEquals(46121.0, $data['opening_balance']);
        $this->assertEquals(10000.0, $data['invoice_balance']);
        $this->assertEquals(56121.0, $data['previous_balance']);
        $this->assertEquals(56121.0, $data['credit_balance']);
        $this->assertCount(1, $data['invoices']);
        $this->assertSame('SL_COMPLETED', $data['invoices'][0]['reference']);
    }

    public function test_customer_total_due_includes_existing_order_and_opening_balance(): void
    {
        DB::table('clients')->insert([
            'id' => 1,
            'opening_balance' => 46121,
            'credit_limit' => 0,
        ]);

        DB::table('sales')->insert([
            'id' => 5092,
            'client_id' => 1,
            'Ref' => 'SL_5092',
            'date' => '2026-10-15',
            'GrandTotal' => 221400,
            'paid_amount' => 0,
            'statut' => 'ordered',
        ]);

        $summary = app(CustomerBalanceService::class)->summary(1);

        $this->assertSame(46121.0, $summary['opening_balance']);
        $this->assertSame(221400.0, $summary['invoice_due']);
        $this->assertSame(267521.0, $summary['total_due']);
    }
}
