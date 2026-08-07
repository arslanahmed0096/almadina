<?php

namespace Tests\Feature;

use App\Http\Controllers\ShipmentController;
use App\Models\Sale;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\ShipmentEligibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ShipmentWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $commission = Mockery::mock(CommissionService::class);
        $commission->shouldReceive('calculateForSale')->zeroOrMoreTimes()->andReturn([]);
        $this->app->instance(CommissionService::class, $commission);

        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_details');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('products');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_already_shipped_item_cannot_be_shipped_again(): void
    {
        [$sale, $detailIds] = $this->seedSale([6000, 5000], 11000, 11000, 10000);
        $service = app(ShipmentEligibilityService::class);

        $service->shipSelectedItems($sale, [$detailIds[0]], [], 1);

        try {
            $service->shipSelectedItems($sale->fresh(), [$detailIds[0]], ['eligible' => true], 1);
            $this->fail('A duplicate shipment should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already been shipped', $e->getMessage());
        }

        $this->assertSame(1, ShipmentItem::count());
        $this->assertSame('ordered', $sale->fresh()->statut);
    }

    public function test_partial_then_final_shipment_updates_sale_status_from_ordered_to_completed(): void
    {
        [$sale, $detailIds] = $this->seedSale([6000, 5000], 11000, 11000, 10000);
        $service = app(ShipmentEligibilityService::class);

        $first = $service->shipSelectedItems($sale, [$detailIds[0]], [], 1);
        $this->assertFalse($first['all_shipped']);
        $this->assertSame('ordered', $sale->fresh()->statut);

        $second = $service->shipSelectedItems($sale->fresh(), [$detailIds[1]], [], 1);
        $this->assertTrue($second['all_shipped']);
        $this->assertSame('completed', $sale->fresh()->statut);
        $this->assertSame('shipped', $sale->fresh()->shipping_status);
        $this->assertSame(2, ShipmentItem::count());
    }

    public function test_combined_credit_failure_rolls_back_every_shipment_change(): void
    {
        [$sale, $detailIds] = $this->seedSale([6000, 5000], 11000, 0, 10000);
        $service = app(ShipmentEligibilityService::class);

        try {
            $service->shipSelectedItems($sale, $detailIds, ['eligible' => true], 1);
            $this->fail('Combined credit above the limit should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('exceed', $e->getMessage());
        }

        $this->assertSame(0, DB::table('shipments')->count());
        $this->assertSame(0, ShipmentItem::count());
        $this->assertSame('ordered', $sale->fresh()->statut);
        $this->assertNull($sale->fresh()->shipping_status);
    }

    public function test_existing_credit_reservation_reduces_credit_for_remaining_items(): void
    {
        [$sale, $detailIds] = $this->seedSale([6000, 5000], 11000, 0, 10000);
        $service = app(ShipmentEligibilityService::class);

        $service->shipSelectedItems($sale, [$detailIds[0]], [], 1);
        $view = $service->forSale($sale->fresh());

        $this->assertSame(4000.0, $view['credit']['available_credit']);
        $this->assertCount(1, $view['items']);
        $this->assertFalse($view['items'][0]['eligible']);
        $this->assertSame(1000.0, $view['items'][0]['additional_required']);
    }

    public function test_completed_sale_does_not_count_its_own_receivable_twice(): void
    {
        [$sale] = $this->seedSale([6000], 6000, 0, 6000);
        DB::table('sales')->where('id', $sale->id)->update(['statut' => 'completed']);

        $view = app(ShipmentEligibilityService::class)->forSale($sale->fresh());

        $this->assertSame(6000.0, $view['credit']['available_credit']);
        $this->assertTrue($view['items'][0]['eligible']);
    }

    public function test_zero_credit_limit_is_not_treated_as_unlimited(): void
    {
        [$sale] = $this->seedSale([1000], 1000, 0, 0);

        $view = app(ShipmentEligibilityService::class)->forSale($sale);

        $this->assertFalse($view['credit']['unlimited']);
        $this->assertSame(0.0, $view['credit']['available_credit']);
        $this->assertFalse($view['items'][0]['eligible']);
    }

    public function test_unique_item_marker_prevents_concurrent_duplicate_insert(): void
    {
        [$sale, $detailIds] = $this->seedSale([6000, 5000], 11000, 11000, 10000);
        app(ShipmentEligibilityService::class)->shipSelectedItems($sale, [$detailIds[0]], [], 1);
        $existing = ShipmentItem::firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('shipment_items')->insert([
            'shipment_id' => $existing->shipment_id,
            'sale_detail_id' => $existing->sale_detail_id,
            'shipped_by' => 1,
            'item_total' => 6000,
            'paid_amount' => 6000,
            'outstanding_amount' => 0,
            'credit_amount' => 0,
            'shipped_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unauthorized_user_is_rejected_before_shipment_data_is_read(): void
    {
        $controller = new class extends ShipmentController
        {
            public function authorizeForUser($user, $ability, $arguments = [])
            {
                throw new AuthorizationException('Forbidden');
            }
        };
        $request = Request::create('/api/shipments/1', 'GET');
        $request->setUserResolver(fn () => new User);

        $this->expectException(AuthorizationException::class);
        $controller->show($request, 1, app(ShipmentEligibilityService::class));
    }

    private function seedSale(array $totals, float $grandTotal, float $paid, float $creditLimit): array
    {
        DB::table('users')->insert(['id' => 1]);
        DB::table('clients')->insert([
            'id' => 1,
            'name' => 'Test Customer',
            'opening_balance' => 0,
            'credit_limit' => $creditLimit,
            'points' => 0,
            'is_royalty_eligible' => 0,
            'deleted_at' => null,
        ]);
        DB::table('products')->insert([
            'id' => 1,
            'name' => 'Service Item',
            'code' => 'SRV-1',
            'type' => 'is_service',
            'unit_sale_id' => null,
            'points' => 0,
            'is_batch_tracked' => 0,
            'is_active' => 1,
            'deleted_at' => null,
        ]);
        DB::table('sales')->insert([
            'id' => 1,
            'Ref' => 'SL_1',
            'client_id' => 1,
            'warehouse_id' => 1,
            'user_id' => 1,
            'GrandTotal' => $grandTotal,
            'paid_amount' => $paid,
            'statut' => 'ordered',
            'payment_statut' => $paid > 0 ? 'partial' : 'unpaid',
            'shipping_status' => null,
            'earned_points' => 0,
            'sales_agent_id' => null,
            'quickbooks_realm_id' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detailIds = [];
        foreach ($totals as $index => $total) {
            $detailIds[] = DB::table('sale_details')->insertGetId([
                'sale_id' => 1,
                'product_id' => 1,
                'product_variant_id' => null,
                'sale_unit_id' => null,
                'quantity' => 1,
                'total' => $total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [Sale::findOrFail(1), $detailIds];
    }

    private function createTables(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->increments('id'));
        Schema::create('clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('points', 15, 2)->default(0);
            $table->boolean('is_royalty_eligible')->default(false);
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type');
            $table->unsignedInteger('unit_sale_id')->nullable();
            $table->decimal('points', 15, 2)->default(0);
            $table->boolean('is_batch_tracked')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('sales', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Ref');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('warehouse_id');
            $table->unsignedInteger('user_id');
            $table->decimal('GrandTotal', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('statut');
            $table->string('payment_statut');
            $table->string('shipping_status')->nullable();
            $table->decimal('earned_points', 15, 2)->default(0);
            $table->unsignedInteger('sales_agent_id')->nullable();
            $table->string('quickbooks_realm_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('sale_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('sale_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->unsignedInteger('sale_unit_id')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->decimal('total', 15, 2);
            $table->timestamps();
        });
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('sale_id')->nullable();
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->softDeletes();
        });
        Schema::create('shipments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->timestamp('date')->useCurrent();
            $table->string('Ref');
            $table->unsignedInteger('sale_id');
            $table->string('delivered_to')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('status');
            $table->text('shipping_details')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('shipment_id');
            $table->unsignedInteger('sale_detail_id')->unique();
            $table->unsignedInteger('shipped_by');
            $table->decimal('item_total', 15, 2);
            $table->decimal('paid_amount', 15, 2);
            $table->decimal('outstanding_amount', 15, 2);
            $table->decimal('credit_amount', 15, 2);
            $table->timestamp('shipped_at');
            $table->timestamps();
        });
    }
}
