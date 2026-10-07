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
}
