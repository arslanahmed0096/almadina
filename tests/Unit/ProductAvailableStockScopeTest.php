<?php

namespace Tests\Unit;

use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductAvailableStockScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('product_warehouse', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id');
            $table->decimal('qte', 12, 4);
            $table->softDeletes();
        });

        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Positive stock'],
            ['id' => 2, 'name' => 'Zero stock'],
            ['id' => 3, 'name' => 'Summed positive stock'],
            ['id' => 4, 'name' => 'Warehouse-dependent stock'],
            ['id' => 5, 'name' => 'Deleted stock row'],
        ]);
        DB::table('product_warehouse')->insert([
            ['product_id' => 1, 'warehouse_id' => 1, 'qte' => 5, 'deleted_at' => null],
            ['product_id' => 2, 'warehouse_id' => 1, 'qte' => 0, 'deleted_at' => null],
            ['product_id' => 3, 'warehouse_id' => 1, 'qte' => -2, 'deleted_at' => null],
            ['product_id' => 3, 'warehouse_id' => 1, 'qte' => 3, 'deleted_at' => null],
            ['product_id' => 4, 'warehouse_id' => 1, 'qte' => 2, 'deleted_at' => null],
            ['product_id' => 4, 'warehouse_id' => 2, 'qte' => -3, 'deleted_at' => null],
            ['product_id' => 5, 'warehouse_id' => 1, 'qte' => 10, 'deleted_at' => '2026-08-10 00:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('product_warehouse');
        Schema::dropIfExists('products');

        parent::tearDown();
    }

    public function test_it_returns_only_products_with_positive_summed_stock(): void
    {
        $ids = Product::query()
            ->withAvailableStock([1, 2])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 3], $ids);
    }

    public function test_it_applies_the_selected_warehouse_scope(): void
    {
        $ids = Product::query()
            ->withAvailableStock([1])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 3, 4], $ids);
    }

    public function test_it_returns_no_products_without_accessible_warehouses(): void
    {
        $this->assertSame([], Product::query()->withAvailableStock([])->pluck('id')->all());
    }
}
