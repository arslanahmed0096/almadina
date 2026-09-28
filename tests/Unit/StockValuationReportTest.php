<?php

namespace Tests\Unit;

use App\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class StockValuationReportTest extends TestCase
{
    public function test_sales_history_route_resolves_before_the_report_handler(): void
    {
        $route = app('router')->getRoutes()->match(
            Request::create('/api/report/stock_inventory_valuation/sales', 'GET')
        );

        $this->assertSame('api/report/stock_inventory_valuation/sales', $route->uri());
        $this->assertSame(
            'App\Http\Controllers\ReportController@stock_inventory_valuation_sales',
            $route->getActionName()
        );
    }

    public function test_fallback_valuation_bases_cover_every_product_price_category(): void
    {
        $method = new ReflectionMethod(ReportController::class, 'stockValuationPriceTypes');
        $types = $method->invoke(new ReportController);

        $this->assertSame([
            'company_rb_price',
            'mrp_price',
            'cost',
            'fix_price',
            'price',
            'wholesale_price',
            'min_price',
        ], $types->pluck('code')->all());
    }

    public function test_cost_valuation_prefers_latest_purchase_price_with_legacy_cost_fallback(): void
    {
        $method = new ReflectionMethod(ReportController::class, 'stockValuationPriceExpression');
        $expression = $method->invoke(new ReportController, 'cost');

        $this->assertStringContainsString('NULLIF(p.purchase_price, 0)', $expression);
        $this->assertStringContainsString('NULLIF(pv.purchase_price, 0)', $expression);
        $this->assertStringContainsString('p.cost', $expression);
        $this->assertStringContainsString('pv.cost', $expression);
    }
}
