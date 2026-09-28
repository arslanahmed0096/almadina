<?php

namespace Tests\Unit;

use App\Http\Controllers\PurchasesController;
use App\Models\Unit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class PurchaseStockPostingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_warehouse', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id');
            $table->decimal('qte', 20, 6)->default(0);
            $table->boolean('manage_stock')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('product_warehouse');

        parent::tearDown();
    }

    public function test_received_purchase_creates_and_then_increments_missing_warehouse_stock(): void
    {
        $unit = new Unit(['operator' => '*', 'operator_value' => 1]);
        $method = new ReflectionMethod(PurchasesController::class, 'increasePurchaseStock');
        $controller = new PurchasesController;
        $detail = ['product_id' => 101, 'product_variant_id' => null];

        $method->invoke($controller, 7, $detail, $unit, 5);
        $method->invoke($controller, 7, $detail, $unit, 2);

        $this->assertSame(1, DB::table('product_warehouse')->count());
        $this->assertEquals(7.0, (float) DB::table('product_warehouse')->value('qte'));
    }

    public function test_variant_and_non_variant_stock_rows_are_incremented_separately(): void
    {
        $unit = new Unit(['operator' => '*', 'operator_value' => 1]);
        $method = new ReflectionMethod(PurchasesController::class, 'increasePurchaseStock');
        $controller = new PurchasesController;

        $method->invoke($controller, 7, ['product_id' => 101, 'product_variant_id' => null], $unit, 3);
        $method->invoke($controller, 7, ['product_id' => 101, 'product_variant_id' => 55], $unit, 4);

        $this->assertEquals(
            3.0,
            (float) DB::table('product_warehouse')->whereNull('product_variant_id')->value('qte')
        );
        $this->assertEquals(
            4.0,
            (float) DB::table('product_warehouse')->where('product_variant_id', 55)->value('qte')
        );
    }

    public function test_received_purchase_rejects_a_missing_purchase_unit_instead_of_skipping_stock(): void
    {
        $method = new ReflectionMethod(PurchasesController::class, 'increasePurchaseStock');

        $this->expectException(ValidationException::class);
        $method->invoke(
            new PurchasesController,
            7,
            ['product_id' => 101, 'product_variant_id' => null],
            null,
            5
        );
    }
}
