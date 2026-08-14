<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductHistoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class ProductHistoryRouteTest extends TestCase
{
    public function test_product_history_route_is_not_captured_by_product_show_route(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/products/123/history', 'GET'));

        $this->assertSame('api/products/{id}/history', $route->uri());
        $this->assertSame(
            'App\Http\Controllers\ProductHistoryController@index',
            $route->getActionName()
        );
    }

    public function test_history_sections_follow_existing_module_permissions(): void
    {
        $method = new ReflectionMethod(ProductHistoryController::class, 'allowedTypes');
        $types = $method->invoke(new ProductHistoryController, new Collection([
            'products_view',
            'Sales_view',
        ]), false);

        $this->assertContains('product_created', $types);
        $this->assertContains('sale', $types);
        $this->assertContains('shipment', $types);
        $this->assertNotContains('purchase', $types);
        $this->assertNotContains('pricing', $types);
    }

    public function test_pricing_history_uses_the_price_level_view_permission(): void
    {
        $method = new ReflectionMethod(ProductHistoryController::class, 'allowedTypes');

        $types = $method->invoke(new ProductHistoryController, new Collection([
            'products_view',
            'pricing_level_view',
        ]), false);

        $legacyTypes = $method->invoke(new ProductHistoryController, new Collection([
            'products_view',
            'pricing_level',
        ]), false);

        $this->assertContains('pricing', $types);
        $this->assertNotContains('pricing', $legacyTypes);
    }
}
