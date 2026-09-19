<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductMarginPricingService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductMarginPricingServiceTest extends TestCase
{
    public function test_margin_ladder_uses_purchase_price_and_named_tiers(): void
    {
        $product = new Product(['purchase_price' => 20000, 'price' => 25000]);
        (new ProductMarginPricingService)->apply($product, [
            ['type' => 'percentage', 'value' => 2],
            ['type' => 'percentage', 'value' => 5],
            ['type' => 'fixed', 'value' => 3000],
            ['type' => 'fixed', 'value' => 4000],
        ]);

        $this->assertSame(20400.0, (float) $product->min_price);
        $this->assertSame(21000.0, (float) $product->wholesale_price);
        $this->assertSame(23000.0, (float) $product->price);
        $this->assertSame(24000.0, (float) $product->fix_price);
        $this->assertCount(4, $product->pricing_margins);
        $this->assertSame(400.0, (float) $product->pricing_margins[0]['profit']);
        $this->assertSame(20400.0, (float) $product->pricing_margins[0]['calculated_price']);
    }

    public function test_variant_uses_its_wholesale_column(): void
    {
        $variant = new ProductVariant(['purchase_price' => 1000]);
        (new ProductMarginPricingService)->apply($variant, [
            ['type' => 'fixed', 'value' => 10],
            ['type' => 'fixed', 'value' => 20],
            ['type' => 'fixed', 'value' => 30],
            ['type' => 'fixed', 'value' => 40],
        ]);

        $this->assertSame(1010.0, (float) $variant->min_price);
        $this->assertSame(1020.0, (float) $variant->wholesale);
        $this->assertSame(1030.0, (float) $variant->price);
        $this->assertSame(1040.0, (float) $variant->fix_price);
    }

    public function test_equal_or_lower_next_margin_is_rejected(): void
    {
        $product = new Product(['purchase_price' => 1000]);
        $this->expectException(ValidationException::class);
        (new ProductMarginPricingService)->apply($product, [
            ['type' => 'percentage', 'value' => 5],
            ['type' => 'fixed', 'value' => 50],
        ]);
    }

    public function test_margin_values_are_rounded_and_additional_rows_are_stored(): void
    {
        $product = new Product(['purchase_price' => 53212]);
        (new ProductMarginPricingService)->apply($product, [
            ['type' => 'percentage', 'value' => 2],
            ['type' => 'percentage', 'value' => 5],
            ['type' => 'percentage', 'value' => 7],
            ['type' => 'percentage', 'value' => 9],
            ['type' => 'percentage', 'value' => 11, 'label' => 'VIP Price'],
        ]);

        $this->assertSame(1064.0, (float) $product->pricing_margins[0]['profit']);
        $this->assertSame(54276.0, (float) $product->pricing_margins[0]['calculated_price']);
        $this->assertSame(59065.0, (float) $product->pricing_margins[4]['calculated_price']);
        $this->assertSame('VIP Price', $product->pricing_margins[4]['label']);
        $this->assertCount(5, $product->pricing_margins);
    }
}
