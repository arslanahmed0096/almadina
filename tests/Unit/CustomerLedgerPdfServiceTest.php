<?php

namespace Tests\Unit;

use App\Services\CustomerLedgerPdfService;
use ReflectionMethod;
use Tests\TestCase;

class CustomerLedgerPdfServiceTest extends TestCase
{
    public function test_detail_template_defines_separate_net_amount_and_balance_columns(): void
    {
        $template = file_get_contents(resource_path('views/pdf/customer_ledger_detail.blade.php'));

        $this->assertStringContainsString('<th class="num">Net Amount</th>', $template);
        $this->assertStringContainsString('<th class="num">Balance</th>', $template);
        $this->assertStringContainsString('<td colspan="10" class="num">Previous Balance:</td>', $template);
        $this->assertStringContainsString("entry['allocations']", $template);
        $this->assertStringContainsString('Products Purchased', $template);
        $this->assertStringContainsString("line['code']", $template);
    }

    public function test_split_payment_is_one_receipt_with_invoice_allocations(): void
    {
        $payments = collect([
            (object) [
                'id' => 7007,
                'date' => '2026-10-25',
                'created_at' => '2026-10-25 10:00:00',
                'Ref' => 'INV/SL_7007',
                'montant' => 11000,
                'allocation_reference' => 'ALC-50K',
                'source_sale_ref' => 'SL_5805',
                'allocation_type' => 'previous',
                'allocation_sequence' => 1,
                'target_sale_ref' => 'SL_0831',
            ],
            (object) [
                'id' => 7008,
                'date' => '2026-10-25',
                'created_at' => '2026-10-25 10:00:01',
                'Ref' => 'INV/SL_7008',
                'montant' => 39000,
                'allocation_reference' => 'ALC-50K',
                'source_sale_ref' => 'SL_5805',
                'allocation_type' => 'current',
                'allocation_sequence' => 2,
                'target_sale_ref' => 'SL_5805',
            ],
        ]);

        $method = new ReflectionMethod(CustomerLedgerPdfService::class, 'salePaymentEntries');
        $method->setAccessible(true);
        $entries = $method->invoke(new CustomerLedgerPdfService, $payments);

        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame('ALC-50K', $entry['ref']);
        $this->assertSame(50000.0, $entry['credit']);
        $this->assertSame(0.0, $entry['debit']);
        $this->assertCount(2, $entry['allocations']);
        $this->assertSame('SL_0831', $entry['allocations'][0]['invoice_ref']);
        $this->assertSame(11000.0, $entry['allocations'][0]['amount']);
        $this->assertSame('SL_5805', $entry['allocations'][1]['invoice_ref']);
        $this->assertSame(39000.0, $entry['allocations'][1]['amount']);
    }
}
