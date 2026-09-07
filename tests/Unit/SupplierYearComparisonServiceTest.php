<?php

namespace Tests\Unit;

use App\Services\Reports\SupplierYearComparisonService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierYearComparisonServiceTest extends TestCase
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
            $table->date('date');
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('warehouse_id');
            $table->decimal('GrandTotal', 15, 2);
            $table->string('statut');
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
            'id' => 1, 'name' => 'Alpha Supplier', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
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
        Carbon::setTestNow();
        foreach (['store_settings', 'settings', 'currencies', 'payment_purchases', 'purchases', 'providers', 'user_warehouse', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_it_calculates_months_totals_growth_and_selected_branch(): void
    {
        Carbon::setTestNow('2026-09-07 12:00:00');

        DB::table('purchases')->insert([
            ['id' => 1, 'date' => '2025-01-15', 'provider_id' => 1, 'warehouse_id' => 1, 'GrandTotal' => 1000, 'statut' => 'received', 'deleted_at' => null],
            ['id' => 2, 'date' => '2026-01-15', 'provider_id' => 1, 'warehouse_id' => 1, 'GrandTotal' => 1200, 'statut' => 'received', 'deleted_at' => null],
            ['id' => 3, 'date' => '2026-01-20', 'provider_id' => 1, 'warehouse_id' => 2, 'GrandTotal' => 9000, 'statut' => 'received', 'deleted_at' => null],
        ]);
        DB::table('payment_purchases')->insert([
            ['purchase_id' => 1, 'date' => '2025-01-20', 'montant' => 800, 'deleted_at' => null],
            ['purchase_id' => 2, 'date' => '2026-01-20', 'montant' => 900, 'deleted_at' => null],
            ['purchase_id' => 3, 'date' => '2026-01-22', 'montant' => 7000, 'deleted_at' => null],
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

        $report = app(SupplierYearComparisonService::class)->generate($user, 2025, 2026, 1, 1);
        $january = $report['rows'][0];

        $this->assertSame(1000.0, $january['baseline_sales']);
        $this->assertSame(800.0, $january['baseline_payments']);
        $this->assertSame(200.0, $january['baseline_variance']);
        $this->assertSame(1200.0, $january['comparison_sales']);
        $this->assertSame(900.0, $january['comparison_payments']);
        $this->assertSame(300.0, $january['comparison_variance']);
        $this->assertSame(20.0, $january['growth']);
        $this->assertNull($report['rows'][9]['comparison_sales']);
        $this->assertSame(1200.0, $report['totals']['comparison_sales']);
        $this->assertSame('Main Branch', $report['branch']['name']);
        $this->assertSame('PKR', $report['currency']);
    }

    public function test_it_rejects_a_branch_not_assigned_to_the_user(): void
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

        app(SupplierYearComparisonService::class)->generate($user, 2025, 2026, null, 2);
    }
}
