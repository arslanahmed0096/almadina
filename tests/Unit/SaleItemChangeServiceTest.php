<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\SaleDetail;
use App\Services\SaleItemChangeService;
use Tests\TestCase;

class SaleItemChangeServiceTest extends TestCase
{
    public function test_it_records_a_replacement_as_before_and_after_on_the_same_line(): void
    {
        $changes = app(SaleItemChangeService::class)->differences(
            collect([$this->detail(10, 1, 'Original product', 'OLD-1')]),
            collect([$this->detail(10, 2, 'Replacement product', 'NEW-2')])
        );

        $this->assertCount(1, $changes);
        $this->assertSame('product_replaced', $changes[0]['action']);
        $this->assertSame('Original product', $changes[0]['old_values']['name']);
        $this->assertSame('Replacement product', $changes[0]['new_values']['name']);
    }

    public function test_it_records_removed_and_added_order_lines(): void
    {
        $changes = app(SaleItemChangeService::class)->differences(
            collect([$this->detail(10, 1, 'Removed product', 'OLD-1')]),
            collect([$this->detail(11, 2, 'Added product', 'NEW-2')])
        );

        $this->assertSame(['product_removed', 'product_added'], array_column($changes, 'action'));
    }

    public function test_quantity_only_edits_do_not_create_product_change_history(): void
    {
        $before = $this->detail(10, 1, 'Same product', 'SKU-1', 1);
        $after = $this->detail(10, 1, 'Same product', 'SKU-1', 3);

        $this->assertSame([], app(SaleItemChangeService::class)->differences(collect([$before]), collect([$after])));
    }

    private function detail(int $id, int $productId, string $name, string $code, float $quantity = 1): SaleDetail
    {
        $product = new Product(['name' => $name, 'code' => $code]);
        $product->id = $productId;

        $detail = new SaleDetail([
            'product_id' => $productId,
            'product_variant_id' => null,
            'quantity' => $quantity,
        ]);
        $detail->id = $id;
        $detail->setRelation('product', $product);
        $detail->setRelation('productVariant', null);

        return $detail;
    }
}
