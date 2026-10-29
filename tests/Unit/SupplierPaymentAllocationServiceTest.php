<?php

namespace Tests\Unit;

use App\Services\SupplierPaymentAllocationService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierPaymentAllocationServiceTest extends TestCase
{
    public function test_it_allocates_oldest_invoices_first_and_partially_covers_the_last_invoice(): void
    {
        $invoices = collect([
            (object) ['id' => 1, 'GrandTotal' => 1000000, 'paid_amount' => 0],
            (object) ['id' => 2, 'GrandTotal' => 2000000, 'paid_amount' => 500000],
            (object) ['id' => 3, 'GrandTotal' => 2000000, 'paid_amount' => 0],
        ]);

        $plan = app(SupplierPaymentAllocationService::class)->plan($invoices, 4000000);

        $this->assertSame([1, 2, 3], $plan->pluck('purchase')->pluck('id')->all());
        $this->assertSame([1000000.0, 1500000.0, 1500000.0], $plan->pluck('amount')->all());
    }

    public function test_it_rejects_payment_above_total_supplier_outstanding(): void
    {
        $this->expectException(ValidationException::class);

        app(SupplierPaymentAllocationService::class)->plan([
            (object) ['GrandTotal' => 1000, 'paid_amount' => 200],
        ], 801);
    }

    public function test_it_allocates_combined_payment_to_opening_balance_before_oldest_invoices(): void
    {
        $plan = app(SupplierPaymentAllocationService::class)->planCombined([
            (object) ['id' => 1, 'GrandTotal' => 1000, 'paid_amount' => 0],
        ], 650, 200);

        $this->assertSame(200.0, $plan['opening_balance_amount']);
        $this->assertSame([450.0], $plan['invoices']->pluck('amount')->all());
    }

    public function test_it_rejects_combined_payment_above_opening_and_invoice_balances(): void
    {
        $this->expectException(ValidationException::class);

        app(SupplierPaymentAllocationService::class)->planCombined([
            (object) ['GrandTotal' => 1000, 'paid_amount' => 200],
        ], 1001, 200);
    }
}
