<?php

namespace Tests\Unit;

use App\Http\Controllers\PricingLevelController;
use App\Models\PricingLevel;
use App\Models\PricingLevelDetail;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductMarginPricingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PricingLevelCostSyncTest extends TestCase
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
            $table->decimal('company_rb_price', 15, 2)->default(0);
            $table->decimal('mrp_price', 15, 2)->default(0);
            $table->decimal('fix_price', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->default(0);
            $table->decimal('min_price', 15, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->json('pricing_margins')->nullable();
            $table->decimal('company_rb_price', 15, 2)->default(0);
            $table->decimal('mrp_price', 15, 2)->default(0);
            $table->decimal('fix_price', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('wholesale', 15, 2)->default(0);
            $table->decimal('min_price', 15, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pricing_level_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pricing_level_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->json('pricing_margins')->nullable();
            $table->decimal('company_rb_price', 15, 2)->default(0);
            $table->decimal('mrp_price', 15, 2)->default(0);
            $table->decimal('fix_price', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->default(0);
            $table->decimal('min_price', 15, 2)->default(0);
            $table->decimal('previous_company_rb_price', 15, 2)->nullable();
            $table->decimal('previous_mrp_price', 15, 2)->nullable();
            $table->decimal('previous_cost', 15, 2)->nullable();
            $table->decimal('previous_purchase_price', 15, 2)->nullable();
            $table->json('previous_pricing_margins')->nullable();
            $table->decimal('previous_fix_price', 15, 2)->nullable();
            $table->decimal('previous_price', 15, 2)->nullable();
            $table->decimal('previous_wholesale_price', 15, 2)->nullable();
            $table->decimal('previous_min_price', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    public function test_changed_product_cost_replaces_existing_purchase_price_and_updates_margins(): void
    {
        $product = Product::create([
            'name' => 'Test product',
            'type' => 'single',
            'cost' => 100,
            'purchase_price' => 120,
            'pricing_margins' => [
                ['label' => 'Previous margin', 'type' => 'fixed', 'value' => 5, 'profit' => 5, 'calculated_price' => 125],
            ],
        ]);

        $this->savePricing($product->id, null, 150, false, [
            ['type' => 'fixed', 'value' => 10],
        ]);

        $product->refresh();
        $detail = PricingLevelDetail::firstOrFail();

        $this->assertSame(150.0, $product->cost);
        $this->assertSame(150.0, $product->purchase_price);
        $this->assertSame(160.0, $product->min_price);
        $this->assertSame(150.0, $detail->purchase_price);
        $this->assertSame(100.0, $detail->previous_cost);
        $this->assertSame(120.0, $detail->previous_purchase_price);
        $this->assertSame(0.0, $detail->previous_min_price);
        $this->assertSame('Previous margin', $detail->previous_pricing_margins[0]['label']);
    }

    public function test_changed_variant_cost_replaces_its_existing_purchase_price(): void
    {
        $product = Product::create(['name' => 'Variant product', 'type' => 'is_variant']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'cost' => 70,
            'purchase_price' => 90,
        ]);

        $this->savePricing($product->id, $variant->id, 80);

        $this->assertSame(80.0, $variant->fresh()->purchase_price);
        $this->assertSame(80.0, PricingLevelDetail::firstOrFail()->purchase_price);
    }

    public function test_unchanged_cost_preserves_existing_purchase_price_unless_explicitly_entered(): void
    {
        $product = Product::create([
            'name' => 'Unchanged product',
            'type' => 'single',
            'cost' => 100,
            'purchase_price' => 120,
            'pricing_margins' => [
                ['label' => 'Previous margin', 'type' => 'fixed', 'value' => 5, 'profit' => 5, 'calculated_price' => 125],
            ],
        ]);

        $this->savePricing($product->id, null, 100);
        $this->assertSame(120.0, $product->fresh()->purchase_price);

        $this->savePricing($product->id, null, 100, true);
        $this->assertSame(100.0, $product->fresh()->purchase_price);
    }

    public function test_pricing_level_displays_current_product_prices_after_purchase_price_changes(): void
    {
        $product = Product::create([
            'name' => 'Current product',
            'type' => 'is_single',
            'purchase_price' => 27000,
        ]);
        $margins = [
            ['type' => 'percentage', 'value' => 2],
            ['type' => 'percentage', 'value' => 5],
            ['type' => 'percentage', 'value' => 7],
        ];
        $service = new ProductMarginPricingService;
        $service->apply($product, $margins);
        $product->save();

        $snapshot = PricingLevelDetail::create([
            'pricing_level_id' => 1,
            'product_id' => $product->id,
            'purchase_price' => $product->purchase_price,
            'min_price' => $product->min_price,
            'wholesale_price' => $product->wholesale_price,
            'price' => $product->price,
            'pricing_margins' => $product->pricing_margins,
        ]);

        $product->purchase_price = 30000;
        $service->apply($product, $margins);
        $product->save();

        $method = new \ReflectionMethod(new PricingLevelController, 'currentPrices');
        $current = $method->invoke(new PricingLevelController, $product->fresh(), $snapshot->fresh());

        $this->assertSame(30000.0, $current['purchase_price']);
        $this->assertSame(30600.0, $current['min_price']);
        $this->assertSame(31500.0, $current['wholesale_price']);
        $this->assertSame(32100.0, $current['price']);
        $this->assertSame(600.0, (float) $current['pricing_margins'][0]['profit']);
        $this->assertSame(27000.0, $snapshot->fresh()->purchase_price);
        $this->assertSame(27540.0, $snapshot->fresh()->min_price);
    }

    public function test_pricing_level_uses_current_variant_wholesale_price(): void
    {
        $product = Product::create(['name' => 'Variant product', 'type' => 'is_variant']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'purchase_price' => 1000,
            'wholesale' => 1100,
        ]);
        $snapshot = PricingLevelDetail::create([
            'pricing_level_id' => 1,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'purchase_price' => 900,
            'wholesale_price' => 950,
        ]);

        $method = new \ReflectionMethod(new PricingLevelController, 'currentPrices');
        $current = $method->invoke(new PricingLevelController, $variant, $snapshot);

        $this->assertSame(1000.0, $current['purchase_price']);
        $this->assertSame(1100.0, $current['wholesale_price']);
        $this->assertSame(950.0, $snapshot->fresh()->wholesale_price);
    }

    private function savePricing(
        int $productId,
        ?int $variantId,
        float $cost,
        bool $costUpdated = false,
        array $margins = []
    ): void {
        $entry = new PricingLevel;
        $entry->id = 1;

        $controller = new PricingLevelController;
        $method = new \ReflectionMethod($controller, 'applyAndStoreDetails');
        $method->invoke($controller, $entry, [[
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'cost_updated' => $costUpdated,
            'company_rb_price' => 0,
            'mrp_price' => 0,
            'cost' => $cost,
            'fix_price' => 0,
            'price' => 0,
            'wholesale_price' => 0,
            'min_price' => 0,
            'pricing_margins' => $margins,
        ]]);
    }
}
