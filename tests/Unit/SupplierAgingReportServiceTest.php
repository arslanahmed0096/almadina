<?php

namespace Tests\Unit;

use App\Services\Reports\SupplierAgingReportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierAgingReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('warehouses', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('user_warehouse', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('warehouse_id');
        });
        Schema::create('providers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Ref');
            $table->date('date');
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('warehouse_id');
            $table->decimal('GrandTotal', 15, 2);
            $table->string('statut');
            $table->unsignedTinyInteger('payment_due_days')->nullable();
            $table->date('payment_due_date')->nullable();
            $table->softDeletes();
        });
        Schema::create('payment_purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('purchase_id');
            $table->date('date');
            $table->decimal('montant', 15, 2);
            $table->softDeletes();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code');
            $table->string('name');
            $table->string('symbol');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('currency_id');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('store_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('currency_code')->nullable();
        });

        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'Main Branch', 'deleted_at' => null],
            ['id' => 2, 'name' => 'Other Branch', 'deleted_at' => null],
        ]);
        DB::table('providers')->insert([
            ['id' => 1, 'name' => 'Alpha Supplier', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['id' => 2, 'name' => 'Beta Supplier', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
        ]);
        DB::table('currencies')->insert([
            'id' => 1, 'code' => 'pkr', 'name' => 'Rupee', 'symbol' => 'Rs',
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ]);
        DB::table('settings')->insert([
            'id' => 1, 'currency_id' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['store_settings', 'settings', 'currencies', 'payment_purchases', 'purchases', 'providers', 'user_warehouse', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_it_builds_as_of_balances_month_collections_and_overdue_totals(): void
    {
        DB::table('purchases')->insert([
            [
                'id' => 1, 'Ref' => 'PU-001', 'date' => '2026-08-01', 'provider_id' => 1,
                'warehouse_id' => 1, 'GrandTotal' => 1000, 'statut' => 'received',
                'payment_due_days' => 27, 'payment_due_date' => '2026-08-28', 'deleted_at' => null,
            ],
            [
                'id' => 2, 'Ref' => 'PU-002', 'date' => '2026-09-20', 'provider_id' => 1,
                'warehouse_id' => 1, 'GrandTotal' => 500, 'statut' => 'received',
                'payment_due_days' => 24, 'payment_due_date' => '2026-10-14', 'deleted_at' => null,
            ],
            [
                'id' => 3, 'Ref' => 'PU-OTHER', 'date' => '2026-07-01', 'provider_id' => 1,
                'warehouse_id' => 2, 'GrandTotal' => 9000, 'statut' => 'received',
                'payment_due_days' => 10, 'payment_due_date' => '2026-07-11', 'deleted_at' => null,
            ],
            [
                'id' => 4, 'Ref' => 'PU-PAID', 'date' => '2026-09-01', 'provider_id' => 2,
                'warehouse_id' => 1, 'GrandTotal' => 400, 'statut' => 'received',
                'payment_due_days' => null, 'payment_due_date' => null, 'deleted_at' => null,
            ],
        ]);
        DB::table('payment_purchases')->insert([
            ['purchase_id' => 1, 'date' => '2026-10-02', 'montant' => 200, 'deleted_at' => null],
            ['purchase_id' => 1, 'date' => '2026-10-10', 'montant' => 300, 'deleted_at' => null],
            ['purchase_id' => 2, 'date' => '2026-10-03', 'montant' => 100, 'deleted_at' => '2026-10-03 12:00:00'],
            ['purchase_id' => 4, 'date' => '2026-10-04', 'montant' => 400, 'deleted_at' => null],
        ]);

        $user = new class
        {
            public int $id = 1;
            public int $is_all_warehouses = 1;

            public function isSuperAdmin(): bool
            {
                return true;
            }
        };

        $report = app(SupplierAgingReportService::class)->generate(
            $user, '2026-10', Carbon::parse('2026-10-07'), 40, null, 1
        );

        $alpha = $report['rows']->firstWhere('supplier_id', 1);
        $beta = $report['rows']->firstWhere('supplier_id', 2);
        $this->assertSame(1300.0, $alpha['total_balance']);
        $this->assertSame(500.0, $alpha['due_payments']);
        $this->assertSame(200.0, $alpha['month_collection']);
        $this->assertSame(800.0, $alpha['overdue_amount']);
        $this->assertSame(40, $alpha['details'][0]['days_overdue']);
        $this->assertTrue($alpha['details'][0]['overdue_threshold_reached']);
        $this->assertSame('Due date 14-Oct-2026', $alpha['details'][1]['status']);
        $this->assertSame(0.0, $beta['total_balance']);
        $this->assertSame(400.0, $beta['month_collection']);
        $this->assertSame(1300.0, $report['totals']['total_balance']);
        $this->assertSame(600.0, $report['totals']['month_collection']);
        $this->assertSame('PKR', $report['currency']);
    }

    public function test_it_rejects_an_unassigned_branch(): void
    {
        DB::table('user_warehouse')->insert(['user_id' => 7, 'warehouse_id' => 1]);
        $user = new class
        {
            public int $id = 7;
            public int $is_all_warehouses = 0;

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('You do not have access to the selected branch.');
        app(SupplierAgingReportService::class)->generate(
            $user, '2026-10', Carbon::parse('2026-10-07'), 40, null, 2
        );
    }
}
