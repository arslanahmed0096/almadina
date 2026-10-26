<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Services\ProductMarginPricingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseMarginAutoSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->json('pricing_margins')->nullable();
            $table->json('further_discounts')->nullable();
            $table->decimal('further_discounted_price', 15, 2)->nullable();
            $table->decimal('min_price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('fix_price', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->increments('id');
            $table->string('operator')->default('*');
            $table->decimal('operator_value', 15, 6)->default(1);
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('purchase_id');
            $table->unsignedInteger('purchase_unit_id')->nullable();
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 6);
            $table->decimal('cost', 15, 2);
            $table->decimal('total', 15, 2);
            $table->timestamps();
        });
    }

    public function test_new_purchase_cost_recalculates_fixed_and_percentage_margin_prices(): void
    {
        $product = Product::create([
            'name' => 'Automatically repriced product',
            'type' => 'is_single',
            'purchase_price' => 20000,
            'pricing_margins' => [
                ['type' => 'fixed', 'value' => 500],
                ['type' => 'fixed', 'value' => 700],
                ['type' => 'fixed', 'value' => 1000],
                ['type' => 'percentage', 'value' => 5],
            ],
        ]);
        $purchase = Purchase::create(['GrandTotal' => 25000]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost' => 21000,
            // Deliberately differs because a line total may include discount/GST.
            'total' => 25000,
        ]);

        (new ProductMarginPricingService)->syncPurchase($purchase);

        $product->refresh();
        $this->assertSame(21000.0, $product->purchase_price);
        $this->assertSame(21500.0, $product->min_price);
        $this->assertSame(21700.0, $product->wholesale_price);
        $this->assertSame(22000.0, $product->price);
        $this->assertSame(22050.0, $product->fix_price);
        $this->assertSame(1050.0, (float) $product->pricing_margins[3]['profit']);
    }

    public function test_purchase_cost_is_converted_from_purchase_unit_to_base_unit(): void
    {
        $product = Product::create([
            'name' => 'Carton product',
            'type' => 'is_single',
            'purchase_price' => 2000,
            'pricing_margins' => [['type' => 'fixed', 'value' => 100]],
        ]);
        $unitId = DB::table('units')->insertGetId([
            'operator' => '*',
            'operator_value' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchase = Purchase::create(['GrandTotal' => 42000]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'purchase_unit_id' => $unitId,
            'product_id' => $product->id,
            'quantity' => 2,
            'cost' => 21000,
            'total' => 42000,
        ]);

        (new ProductMarginPricingService)->syncPurchase($purchase);

        $product->refresh();
        $this->assertSame(2100.0, $product->purchase_price);
        $this->assertSame(2200.0, $product->min_price);
    }
}
