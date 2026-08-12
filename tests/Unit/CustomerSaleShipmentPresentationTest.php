<?php

namespace Tests\Unit;

use App\Http\Controllers\ClientController;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class CustomerSaleShipmentPresentationTest extends TestCase
{
    public function test_partial_shipment_identifies_each_purchased_item_and_accurate_progress(): void
    {
        $sale = new Sale(['shipping_status' => 'ordered']);
        $details = collect([
            $this->detail(101, 'First product', 'P-001'),
            $this->detail(102, 'Second product', 'P-002'),
            $this->detail(103, 'Third product', 'P-003'),
        ]);

        $shippedItem = new ShipmentItem([
            'sale_detail_id' => 103,
            'shipped_at' => Carbon::parse('2026-08-12 10:30:00'),
        ]);
        $shipment = new Shipment([
            'Ref' => 'SM_1200',
            'status' => 'ordered',
            'date' => '2026-08-12',
        ]);
        $shipment->id = 50;
        $shipment->setRelation('items', collect([$shippedItem]));

        $sale->setRelation('details', $details);
        $sale->setRelation('shipments', collect([$shipment]));

        $method = new ReflectionMethod(ClientController::class, 'saleShipmentPresentation');
        $result = $method->invoke(new ClientController, $sale);

        $this->assertSame('partially_shipped', $result['status']);
        $this->assertSame(1, $result['shipped_count']);
        $this->assertSame(3, $result['total_count']);
        $this->assertSame(['not_shipped', 'not_shipped', 'shipped'], array_column($result['items'], 'shipment_status'));
        $this->assertSame(['First product', 'Second product', 'Third product'], array_column($result['items'], 'product_name'));
    }

    private function detail(int $id, string $name, string $code): SaleDetail
    {
        $product = new Product(['name' => $name, 'code' => $code]);
        $detail = new SaleDetail(['quantity' => 1]);
        $detail->id = $id;
        $detail->setRelation('product', $product);
        $detail->setRelation('productVariant', null);

        return $detail;
    }
}
