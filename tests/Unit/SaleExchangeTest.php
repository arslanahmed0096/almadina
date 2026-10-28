<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Models\SaleExchange;
use App\Models\SaleReturn;
use Tests\TestCase;

class SaleExchangeTest extends TestCase
{
    public function test_fully_paid_sale_makes_the_unrefunded_return_value_available_for_exchange(): void
    {
        $exchange = $this->exchange(originalPaid: 100, returnTotal: 100, refunded: 0);

        $this->assertSame(100.0, $exchange->availableCredit());
    }

    public function test_exchange_credit_cannot_exceed_what_customer_paid_on_original_sale(): void
    {
        $exchange = $this->exchange(originalPaid: 40, returnTotal: 100, refunded: 0);

        $this->assertSame(40.0, $exchange->availableCredit());
        $this->assertSame(70.0, $exchange->creditAfterPayment(30));
        $this->assertSame(100.0, $exchange->creditAfterPayment(60));
    }

    public function test_cash_refund_reduces_value_available_for_replacement_sale(): void
    {
        $exchange = $this->exchange(originalPaid: 100, returnTotal: 100, refunded: 25);

        $this->assertSame(75.0, $exchange->availableCredit());
        $this->assertSame(75.0, $exchange->returnValue());
    }

    private function exchange(float $originalPaid, float $returnTotal, float $refunded): SaleExchange
    {
        $sale = new Sale(['paid_amount' => $originalPaid]);
        $return = new SaleReturn(['GrandTotal' => $returnTotal, 'paid_amount' => $refunded]);
        $exchange = new SaleExchange;
        $exchange->setRelation('originalSale', $sale);
        $exchange->setRelation('saleReturn', $return);

        return $exchange;
    }
}
