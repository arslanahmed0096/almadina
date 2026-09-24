<?php

namespace Tests\Unit;

use App\Services\WarehouseStockGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehouseStockGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('units', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ShortName')->nullable();
            $table->string('operator')->default('*');
            $table->decimal('operator_value', 20, 6)->default(1);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type')->default('is_single');
            $table->integer('unit_sale_id')->nullable();
            $table->integer('unit_purchase_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
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

        DB::table('units')->insert([
            'id' => 1,
            'ShortName' => 'Pc',
            'operator' => '*',
            'operator_value' => 1,
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Stock Product', 'code' => 'STK-1', 'type' => 'is_single', 'unit_sale_id' => 1, 'unit_purchase_id' => 1],
            ['id' => 2, 'name' => 'Service Product', 'code' => 'SRV-1', 'type' => 'is_service', 'unit_sale_id' => null, 'unit_purchase_id' => null],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('product_warehouse');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');

        parent::tearDown();
    }

    public function test_sale_is_rejected_when_selected_warehouse_has_zero_stock(): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => 1,
            'product_variant_id' => null,
            'warehouse_id' => 1,
            'qte' => 0,
        ]);

        try {
            app(WarehouseStockGuard::class)->assertSaleAvailable(1, [[
                'product_id' => 1,
                'product_variant_id' => null,
                'sale_unit_id' => 1,
                'quantity' => 1,
            ]]);
            $this->fail('A sale with zero warehouse stock should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Sale not allowed', $exception->errors()['stock'][0]);
            $this->assertStringContainsString('available 0', $exception->errors()['stock'][0]);
        }
    }

    public function test_sale_is_rejected_when_combined_duplicate_lines_exceed_stock(): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => 1,
            'product_variant_id' => null,
            'warehouse_id' => 1,
            'qte' => 3,
        ]);

        $this->expectException(ValidationException::class);
        app(WarehouseStockGuard::class)->assertSaleAvailable(1, [
            ['product_id' => 1, 'sale_unit_id' => 1, 'quantity' => 2],
            ['product_id' => 1, 'sale_unit_id' => 1, 'quantity' => 2],
        ]);
    }

    public function test_transfer_is_rejected_when_source_warehouse_has_no_stock(): void
    {
        $this->expectException(ValidationException::class);
        app(WarehouseStockGuard::class)->assertTransferAvailable(1, [[
            'product_id' => 1,
            'purchase_unit_id' => 1,
            'quantity' => 1,
        ]]);
    }

    public function test_available_stock_and_service_lines_are_allowed(): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => 1,
            'product_variant_id' => null,
            'warehouse_id' => 1,
            'qte' => 2,
        ]);

        app(WarehouseStockGuard::class)->assertSaleAvailable(1, [
            ['product_id' => 1, 'sale_unit_id' => 1, 'quantity' => 2],
            ['product_id' => 2, 'quantity' => 100],
        ]);

        $this->assertTrue(true);
    }

    public function test_sale_is_rejected_when_selected_warehouse_row_is_missing(): void
    {
        $this->expectException(ValidationException::class);

        app(WarehouseStockGuard::class)->assertSaleAvailable(1, [[
            'product_id' => 1,
            'sale_unit_id' => 1,
            'quantity' => 1,
        ]]);
    }

    public function test_post_sale_invariant_rejects_negative_stock(): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => 1,
            'product_variant_id' => null,
            'warehouse_id' => 1,
            'qte' => -1,
        ]);

        $this->expectException(ValidationException::class);
        app(WarehouseStockGuard::class)->assertSaleStockNonNegative(1, [[
            'product_id' => 1,
            'quantity' => 1,
        ]]);
    }

    public function test_post_sale_invariant_rejects_a_missing_stock_row(): void
    {
        $this->expectException(ValidationException::class);

        app(WarehouseStockGuard::class)->assertSaleStockNonNegative(1, [[
            'product_id' => 1,
            'quantity' => 1,
        ]]);
    }

    public function test_post_sale_invariant_allows_exact_depletion_to_zero(): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => 1,
            'product_variant_id' => null,
            'warehouse_id' => 1,
            'qte' => 0,
        ]);

        app(WarehouseStockGuard::class)->assertSaleStockNonNegative(1, [[
            'product_id' => 1,
            'quantity' => 1,
        ]]);

        $this->assertTrue(true);
    }
}
