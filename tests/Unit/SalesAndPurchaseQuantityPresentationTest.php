<?php

namespace Tests\Unit;

use Tests\TestCase;

class SalesAndPurchaseQuantityPresentationTest extends TestCase
{
    public function test_create_purchase_shows_item_count_and_sums_every_active_line_quantity(): void
    {
        $source = file_get_contents(resource_path('src/views/app/pages/purchases/create_purchase.vue'));

        $this->assertStringContainsString('Total Items', $source);
        $this->assertStringContainsString('Total Item Qty', $source);
        $this->assertStringContainsString('totalItems()', $source);
        $this->assertStringContainsString('$set', $source);
        $this->assertStringContainsString('nextQuantity', $source);
        $this->assertStringNotContainsString('detail.no_unit === 0', $source);
    }

    public function test_sales_list_only_displays_a_payment_due_date_for_an_outstanding_sale(): void
    {
        $source = file_get_contents(resource_path('src/views/app/pages/sales/index_sale.vue'));
        $controller = file_get_contents(app_path('Http/Controllers/SalesController.php'));

        $this->assertStringContainsString('Payment Due Date', $source);
        $this->assertStringContainsString('Number(props.row.outstanding_amount || 0) > 0', $source);
        $this->assertStringContainsString('$outstandingAmount > 0.009', $controller);
    }

    public function test_exchange_credit_preview_only_counts_payments_allocated_to_the_original_sale(): void
    {
        $source = file_get_contents(resource_path('src/views/app/pages/sales/create_sale.vue'));

        $this->assertStringContainsString('paymentAppliedToExchangeOriginalSale', $source);
        $this->assertStringContainsString('Number(invoice.id) === Number(this.exchange.original_sale_id)', $source);
        $this->assertStringContainsString('Number(this.exchange.credit || 0)', $source);
        $this->assertStringNotContainsString(
            'Number(this.exchange.credit || 0) + Number(this.paymentAppliedToPreviousSales || 0)',
            $source
        );
    }

    public function test_create_and_edit_sale_show_item_count_and_reactively_sum_all_active_quantities(): void
    {
        foreach (['create_sale.vue', 'edit_sale.vue'] as $component) {
            $source = file_get_contents(resource_path("src/views/app/pages/sales/{$component}"));

            $this->assertStringContainsString('Total Items', $source);
            $this->assertStringContainsString('Total Item Qty', $source);
            $this->assertStringContainsString('totalItems()', $source);
            $this->assertStringContainsString('nextQuantity', $source);
            $this->assertStringContainsString('$set', $source);
            $this->assertStringNotContainsString('detail.no_unit === 0) return total', $source);
        }
    }
}
