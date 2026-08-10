<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Unit;
use App\Services\ProductStockOverviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductStockOverviewServiceTest extends TestCase
{
    private ProductStockOverviewService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->seedTransactions();
        $this->service = app(ProductStockOverviewService::class);
    }

    protected function tearDown(): void
    {
        foreach ([
            'sale_return_details', 'sale_returns', 'sale_details', 'sales',
            'purchase_return_details', 'purchase_returns', 'purchase_details', 'purchases',
            'product_warehouse', 'clients', 'units', 'warehouses',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_it_returns_complete_stock_and_customer_picture_in_base_units(): void
    {
        $overview = $this->service->build($this->product(), [1, 2]);

        $this->assertSame(22.0, $overview['totals']['purchased']);
        $this->assertSame(4.0, $overview['totals']['purchase_returned']);
        $this->assertSame(18.0, $overview['totals']['net_purchased']);
        $this->assertSame(10.0, $overview['totals']['in_stock']);
        $this->assertSame(12.0, $overview['totals']['sold']);
        $this->assertSame(2.0, $overview['totals']['sale_returned']);
        $this->assertSame(10.0, $overview['totals']['net_sold']);
        $this->assertSame(2, $overview['totals']['customers']);

        $this->assertSame('Branch A', $overview['warehouses'][0]['warehouse_name']);
        $this->assertSame(7.0, $overview['warehouses'][0]['quantity']);
        $this->assertSame('Branch B', $overview['warehouses'][1]['warehouse_name']);
        $this->assertSame(3.0, $overview['warehouses'][1]['quantity']);

        $this->assertSame('Customer One', $overview['customers'][0]['customer_name']);
        $this->assertSame(8.0, $overview['customers'][0]['sold_quantity']);
        $this->assertSame(1.0, $overview['customers'][0]['returned_quantity']);
        $this->assertSame(7.0, $overview['customers'][0]['net_quantity']);
        $this->assertSame(2, $overview['customers'][0]['sale_count']);
    }

    public function test_it_honours_the_record_owner_and_warehouse_scope(): void
    {
        $overview = $this->service->build($this->product(), [1, 2], 7);

        $this->assertSame(20.0, $overview['totals']['purchased']);
        $this->assertSame(4.0, $overview['totals']['purchase_returned']);
        $this->assertSame(10.0, $overview['totals']['sold']);
        $this->assertSame(1.0, $overview['totals']['sale_returned']);
        $this->assertSame(9.0, $overview['totals']['net_sold']);

        $singleWarehouse = $this->service->build($this->product(), [1]);
        $this->assertSame(7.0, $singleWarehouse['totals']['in_stock']);
        $this->assertCount(1, $singleWarehouse['warehouses']);
    }

    private function product(): Product
    {
        $product = new Product;
        $product->id = 100;
        $product->code = 'TEST-100';
        $product->name = 'Test Product';

        $unit = new Unit;
        $unit->ShortName = 'Pc';
        $product->setRelation('unit', $unit);

        return $product;
    }

    private function createTables(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('units', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ShortName');
            $table->string('operator')->nullable();
            $table->decimal('operator_value', 10, 4)->nullable();
        });
        Schema::create('clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('phone')->nullable();
        });
        Schema::create('product_warehouse', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id');
            $table->decimal('qte', 12, 4);
            $table->softDeletes();
        });

        foreach (['purchases', 'purchase_returns'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('warehouse_id');
                $table->string('statut');
                $table->softDeletes();
            });
        }
        foreach (['sales', 'sale_returns'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('client_id');
                $table->integer('warehouse_id');
                $table->date('date')->nullable();
                $table->string('statut');
                $table->softDeletes();
            });
        }

        $this->createDetailTable('purchase_details', 'purchase_id', 'purchase_unit_id');
        $this->createDetailTable('purchase_return_details', 'purchase_return_id', 'purchase_unit_id');
        $this->createDetailTable('sale_details', 'sale_id', 'sale_unit_id');
        $this->createDetailTable('sale_return_details', 'sale_return_id', 'sale_unit_id');
    }

    private function createDetailTable(string $tableName, string $headerKey, string $unitKey): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($headerKey, $unitKey) {
            $table->increments('id');
            $table->integer($headerKey);
            $table->integer($unitKey)->nullable();
            $table->integer('product_id');
            $table->decimal('quantity', 12, 4);
        });
    }

    private function seedTransactions(): void
    {
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'Branch A'],
            ['id' => 2, 'name' => 'Branch B'],
            ['id' => 3, 'name' => 'Hidden Branch'],
        ]);
        DB::table('units')->insert([
            ['id' => 1, 'ShortName' => 'Pc', 'operator' => '*', 'operator_value' => 1],
            ['id' => 2, 'ShortName' => 'Carton', 'operator' => '*', 'operator_value' => 2],
        ]);
        DB::table('clients')->insert([
            ['id' => 1, 'name' => 'Customer One', 'phone' => '111'],
            ['id' => 2, 'name' => 'Customer Two', 'phone' => '222'],
        ]);
        DB::table('product_warehouse')->insert([
            ['product_id' => 100, 'warehouse_id' => 1, 'qte' => 7],
            ['product_id' => 100, 'warehouse_id' => 2, 'qte' => 3],
            ['product_id' => 100, 'warehouse_id' => 3, 'qte' => 99],
        ]);

        DB::table('purchases')->insert([
            ['id' => 1, 'user_id' => 7, 'warehouse_id' => 1, 'statut' => 'received'],
            ['id' => 2, 'user_id' => 7, 'warehouse_id' => 1, 'statut' => 'pending'],
            ['id' => 3, 'user_id' => 8, 'warehouse_id' => 2, 'statut' => 'received'],
        ]);
        DB::table('purchase_details')->insert([
            ['purchase_id' => 1, 'purchase_unit_id' => 2, 'product_id' => 100, 'quantity' => 10],
            ['purchase_id' => 2, 'purchase_unit_id' => 1, 'product_id' => 100, 'quantity' => 50],
            ['purchase_id' => 3, 'purchase_unit_id' => 1, 'product_id' => 100, 'quantity' => 2],
        ]);
        DB::table('purchase_returns')->insert([
            ['id' => 1, 'user_id' => 7, 'warehouse_id' => 1, 'statut' => 'completed'],
        ]);
        DB::table('purchase_return_details')->insert([
            ['purchase_return_id' => 1, 'purchase_unit_id' => 2, 'product_id' => 100, 'quantity' => 2],
        ]);

        DB::table('sales')->insert([
            ['id' => 1, 'user_id' => 7, 'client_id' => 1, 'warehouse_id' => 1, 'date' => '2026-08-01', 'statut' => 'completed'],
            ['id' => 2, 'user_id' => 7, 'client_id' => 2, 'warehouse_id' => 2, 'date' => '2026-08-02', 'statut' => 'completed'],
            ['id' => 3, 'user_id' => 8, 'client_id' => 1, 'warehouse_id' => 2, 'date' => '2026-08-03', 'statut' => 'completed'],
            ['id' => 4, 'user_id' => 7, 'client_id' => 1, 'warehouse_id' => 1, 'date' => '2026-08-04', 'statut' => 'pending'],
        ]);
        DB::table('sale_details')->insert([
            ['sale_id' => 1, 'sale_unit_id' => 2, 'product_id' => 100, 'quantity' => 3],
            ['sale_id' => 2, 'sale_unit_id' => 1, 'product_id' => 100, 'quantity' => 4],
            ['sale_id' => 3, 'sale_unit_id' => 1, 'product_id' => 100, 'quantity' => 2],
            ['sale_id' => 4, 'sale_unit_id' => 1, 'product_id' => 100, 'quantity' => 100],
        ]);
        DB::table('sale_returns')->insert([
            ['id' => 1, 'user_id' => 7, 'client_id' => 1, 'warehouse_id' => 1, 'date' => '2026-08-05', 'statut' => 'received'],
            ['id' => 2, 'user_id' => 8, 'client_id' => 2, 'warehouse_id' => 2, 'date' => '2026-08-06', 'statut' => 'received'],
        ]);
        DB::table('sale_return_details')->insert([
            ['sale_return_id' => 1, 'sale_unit_id' => 1, 'product_id' => 100, 'quantity' => 1],
            ['sale_return_id' => 2, 'sale_unit_id' => 1, 'product_id' => 100, 'quantity' => 1],
        ]);
    }
}
