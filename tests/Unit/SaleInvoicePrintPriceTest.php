<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use Tests\TestCase;

class SaleInvoicePrintPriceTest extends TestCase
{
    public function test_fixed_print_price_replaces_the_printed_rate_and_total_without_discount(): void
    {
        $detail = new SaleDetail([
            'price' => 57500,
            'invoice_print_price' => 61000,
            'discount' => 0,
            'discount_method' => '2',
            'quantity' => 2,
            'total' => 115000,
        ]);

        $this->assertSame(61000.0, $detail->printableUnitPrice());
        $this->assertSame(0.0, $detail->printableDiscountNet());
        $this->assertSame(122000.0, $detail->printableLineTotal());
        $this->assertSame(57500.0, (float) $detail->price);
    }

    public function test_zero_snapshot_keeps_the_actual_sale_price(): void
    {
        $detail = new SaleDetail([
            'price' => 57500,
            'invoice_print_price' => 0,
            'discount' => 500,
            'discount_method' => '2',
        ]);

        $this->assertSame(57500.0, $detail->printableUnitPrice());
        $this->assertSame(500.0, $detail->printableDiscountNet());
    }

    public function test_legacy_sale_without_snapshot_can_use_the_product_fixed_price(): void
    {
        $product = new Product(['invoice_print_price' => 61000]);
        $detail = new SaleDetail([
            'price' => 57500,
            'invoice_print_price' => null,
            'discount' => 0,
            'discount_method' => '2',
        ]);
        $detail->setRelation('product', $product);

        $this->assertSame(61000.0, $detail->printableUnitPrice());
        $this->assertSame(0.0, $detail->printableDiscountNet());
    }

    public function test_product_fixed_price_is_snapshotted_when_the_sale_is_saved(): void
    {
        $product = new Product(['invoice_print_price' => 61000]);

        $this->assertSame(61000.0, SaleDetail::snapshotInvoicePrintPrice($product));
    }

    public function test_paid_invoice_prints_fixed_price_as_total_and_paid_amount(): void
    {
        $detail = new SaleDetail([
            'price' => 23193,
            'invoice_print_price' => 26000,
            'discount' => 0,
            'discount_method' => '2',
            'quantity' => 1,
            'total' => 23193,
        ]);
        $sale = new Sale([
            'GrandTotal' => 23193,
            'paid_amount' => 23193,
            'payment_statut' => 'paid',
            'TaxNet' => 0,
            'discount' => 0,
            'shipping' => 0,
        ]);
        $sale->setRelation('details', collect([$detail]));

        $summary = SaleDetail::printableSaleSummary($sale);

        $this->assertSame(26000.0, $summary['subtotal']);
        $this->assertSame(26000.0, $summary['grand_total']);
        $this->assertSame(26000.0, $summary['paid_amount']);
        $this->assertSame(0.0, $summary['discount']);
        $this->assertSame(0.0, $summary['tax']);
        $this->assertSame(0.0, $summary['due']);
    }
}
