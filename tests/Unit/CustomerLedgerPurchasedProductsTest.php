<?php

namespace Tests\Unit;

use App\Http\Controllers\ClientController;
use App\Models\Sale;
use ReflectionMethod;
use Tests\TestCase;

class CustomerLedgerPurchasedProductsTest extends TestCase
{
    public function test_sale_ledger_item_contains_product_quantity_rate_and_amount(): void
    {
        $sale = new Sale;
        $sale->shipping_status = null;
        $sale->setRelation('details', collect([
            (object) [
                'id' => 10,
                'product' => (object) [
                    'name' => 'LED Television 43 Inch',
                    'code' => 'TV-43-001',
                ],
                'productVariant' => null,
                'quantity' => 2,
                'price' => 45000,
                'total' => 90000,
            ],
        ]));
        $sale->setRelation('shipments', collect());

        $method = new ReflectionMethod(ClientController::class, 'saleShipmentPresentation');
        $method->setAccessible(true);
        $presentation = $method->invoke(new ClientController, $sale);

        $this->assertCount(1, $presentation['items']);
        $item = $presentation['items'][0];
        $this->assertSame('LED Television 43 Inch', $item['product_name']);
        $this->assertSame('TV-43-001', $item['product_code']);
        $this->assertSame(2.0, $item['quantity']);
        $this->assertSame(45000.0, $item['rate']);
        $this->assertSame(90000.0, $item['line_amount']);
    }
}
