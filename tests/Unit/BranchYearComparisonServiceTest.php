<?php

namespace Tests\Unit;

use App\Services\Reports\BranchYearComparisonService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchYearComparisonServiceTest extends TestCase
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
        Schema::create('sales', function (Blueprint $table) {
            $table->increments('id');
            $table->date('date');
            $table->unsignedInteger('warehouse_id');
            $table->decimal('GrandTotal', 15, 2);
            $table->string('statut');
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
            ['id' => 1, 'name' => 'Plaza', 'deleted_at' => null],
            ['id' => 2, 'name' => 'Bazar', 'deleted_at' => null],
            ['id' => 3, 'name' => 'No Sales Branch', 'deleted_at' => null],
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
        foreach (['store_settings', 'settings', 'currencies', 'sales', 'user_warehouse', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_it_compares_completed_annual_sales_for_every_accessible_branch(): void
    {
        Carbon::setTestNow('2026-09-08 12:00:00');
        DB::table('sales')->insert([
            ['date' => '2025-02-01', 'warehouse_id' => 1, 'GrandTotal' => 1000, 'statut' => 'completed', 'deleted_at' => null],
            ['date' => '2026-02-01', 'warehouse_id' => 1, 'GrandTotal' => 750, 'statut' => 'completed', 'deleted_at' => null],
            ['date' => '2025-03-01', 'warehouse_id' => 2, 'GrandTotal' => 500, 'statut' => 'completed', 'deleted_at' => null],
            ['date' => '2026-03-01', 'warehouse_id' => 2, 'GrandTotal' => 650, 'statut' => 'completed', 'deleted_at' => null],
            ['date' => '2026-03-02', 'warehouse_id' => 2, 'GrandTotal' => 9000, 'statut' => 'pending', 'deleted_at' => null],
        ]);

        $user = $this->user(1, true);
        $report = app(BranchYearComparisonService::class)->generate($user, 2025, 2026);

        $this->assertCount(3, $report['rows']);
        $this->assertSame('Bazar', $report['rows'][0]['branch']);
        $this->assertSame(500.0, $report['rows'][0]['baseline_total']);
        $this->assertSame(650.0, $report['rows'][0]['comparison_total']);
        $this->assertSame(150.0, $report['rows'][0]['difference']);
        $this->assertSame(30.0, $report['rows'][0]['change_percent']);
        $this->assertSame('Up', $report['rows'][0]['trend']);
        $this->assertSame(-250.0, $report['rows'][2]['difference']);
        $this->assertSame(-25.0, $report['rows'][2]['change_percent']);
        $this->assertSame('Down', $report['rows'][2]['trend']);
        $this->assertSame(1500.0, $report['totals']['baseline_total']);
        $this->assertSame(1400.0, $report['totals']['comparison_total']);
        $this->assertSame(-100.0, $report['totals']['difference']);
        $this->assertSame(-6.67, $report['totals']['change_percent']);
        $this->assertSame('PKR', $report['currency']);
        $this->assertSame('September 8, 2026', $report['comparison_through']);

        $februaryPlaza = $report['months'][1]['rows'][2];
        $this->assertSame('February', $report['months'][1]['month']);
        $this->assertSame('Plaza', $februaryPlaza['branch']);
        $this->assertSame(1000.0, $februaryPlaza['baseline_total']);
        $this->assertSame(750.0, $februaryPlaza['comparison_total']);
        $this->assertSame(-250.0, $februaryPlaza['difference']);
        $this->assertSame(-25.0, $februaryPlaza['change_percent']);
        $this->assertNull($report['months'][9]['rows'][2]['comparison_total']);
    }

    public function test_it_limits_the_report_to_assigned_branches(): void
    {
        DB::table('user_warehouse')->insert(['user_id' => 7, 'warehouse_id' => 2]);
        DB::table('sales')->insert([
            ['date' => '2025-01-01', 'warehouse_id' => 1, 'GrandTotal' => 1000, 'statut' => 'completed', 'deleted_at' => null],
            ['date' => '2025-01-01', 'warehouse_id' => 2, 'GrandTotal' => 500, 'statut' => 'completed', 'deleted_at' => null],
        ]);

        $report = app(BranchYearComparisonService::class)->generate($this->user(7, false), 2025, 2026);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('Bazar', $report['rows'][0]['branch']);
        $this->assertSame(500.0, $report['totals']['baseline_total']);
    }

    private function user(int $id, bool $allWarehouses): object
    {
        return new class($id, $allWarehouses)
        {
            public int $id;
            public int $is_all_warehouses;

            public function __construct(int $id, bool $allWarehouses)
            {
                $this->id = $id;
                $this->is_all_warehouses = $allWarehouses ? 1 : 0;
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };
    }
}
