<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Services\ShipmentEligibilityService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ShipmentEligibilityServiceTest extends TestCase
{
    private ShipmentEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ShipmentEligibilityService::class);
    }

    public function test_fully_paid_item_is_eligible_without_credit(): void
    {
        $allocation = $this->allocations([10000], 10000, 10000)[1];
        $eligibility = $this->service->evaluateItemEligibility($allocation['outstanding_amount'], 0);

        $this->assertSame(10000.0, $allocation['paid_amount']);
        $this->assertSame(0.0, $allocation['outstanding_amount']);
        $this->assertTrue($eligibility['eligible']);
        $this->assertSame('paid', $eligibility['eligibility_type']);
    }

    public function test_unpaid_item_is_eligible_when_credit_covers_outstanding(): void
    {
        $eligibility = $this->service->evaluateItemEligibility(6000, 10000);

        $this->assertTrue($eligibility['eligible']);
        $this->assertSame('credit', $eligibility['eligibility_type']);
    }

    public function test_unpaid_item_is_ineligible_when_credit_is_insufficient(): void
    {
        $eligibility = $this->service->evaluateItemEligibility(6000, 5000);

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame(1000.0, $eligibility['additional_required']);
    }

    public function test_unpaid_item_is_ineligible_when_customer_credit_limit_is_zero(): void
    {
        $eligibility = $this->service->evaluateItemEligibility(100, 0);

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('insufficient_credit', $eligibility['eligibility_type']);
        $this->assertSame(100.0, $eligibility['additional_required']);
    }

    public function test_lower_priced_alternative_item_remains_eligible(): void
    {
        $expensive = $this->service->evaluateItemEligibility(6000, 5000);
        $alternative = $this->service->evaluateItemEligibility(5000, 5000);

        $this->assertFalse($expensive['eligible']);
        $this->assertTrue($alternative['eligible']);
    }

    public function test_fully_paid_item_does_not_consume_credit(): void
    {
        $this->assertTrue($this->service->selectionFitsAvailableCredit([0, 6000], 6000));
    }

    public function test_multiple_credit_items_fit_when_combined_outstanding_is_within_limit(): void
    {
        $this->assertTrue($this->service->selectionFitsAvailableCredit([6000, 4000], 10000));
    }

    public function test_multiple_credit_items_are_rejected_when_combined_outstanding_exceeds_limit(): void
    {
        $this->assertFalse($this->service->selectionFitsAvailableCredit([6000, 5000], 10000));
    }

    public function test_sale_level_payment_is_allocated_fifo_by_detail_id(): void
    {
        $allocations = $this->allocations([6000, 5000], 11000, 7000);

        $this->assertSame(6000.0, $allocations[1]['paid_amount']);
        $this->assertSame(0.0, $allocations[1]['outstanding_amount']);
        $this->assertSame(1000.0, $allocations[2]['paid_amount']);
        $this->assertSame(4000.0, $allocations[2]['outstanding_amount']);
    }

    public function test_sale_level_payment_is_allocated_to_selected_priority_item_first(): void
    {
        $sale = $this->sale([33000, 88000, 15500], 136500, 135000);
        $allocations = $this->service->allocateSalePayments($sale, [3]);

        $this->assertSame(15500.0, $allocations[3]['paid_amount']);
        $this->assertSame(0.0, $allocations[3]['outstanding_amount']);
        $this->assertSame(33000.0, $allocations[1]['paid_amount']);
        $this->assertSame(86500.0, $allocations[2]['paid_amount']);
        $this->assertSame(1500.0, $allocations[2]['outstanding_amount']);
    }

    public function test_sale_level_adjustments_are_reconciled_across_item_totals(): void
    {
        $allocations = $this->allocations([6000, 4000], 9000, 9000);

        $this->assertSame(5400.0, $allocations[1]['item_total']);
        $this->assertSame(3600.0, $allocations[2]['item_total']);
        $this->assertSame(9000.0, array_sum(array_column($allocations, 'item_total')));
        $this->assertSame(0.0, array_sum(array_column($allocations, 'outstanding_amount')));
    }

    public function test_sale_remains_ordered_while_any_item_is_unshipped(): void
    {
        $this->assertSame('ordered', $this->service->determineSaleShipmentStatus(1, 3));
    }

    public function test_sale_becomes_completed_only_after_every_item_is_shipped(): void
    {
        $this->assertSame('completed', $this->service->determineSaleShipmentStatus(3, 3));
        $this->assertSame('ordered', $this->service->determineSaleShipmentStatus(0, 0));
    }

    public function test_ordered_sales_use_the_same_item_eligibility_rules(): void
    {
        $sale = $this->sale([8000], 8000, 3000, 'ordered');
        $allocation = $this->service->allocateSalePayments($sale)[1];
        $eligibility = $this->service->evaluateItemEligibility($allocation['outstanding_amount'], 5000);

        $this->assertTrue($eligibility['eligible']);
        $this->assertSame(5000.0, $allocation['outstanding_amount']);
    }

    private function allocations(array $totals, float $grandTotal, float $paidAmount): array
    {
        return $this->service->allocateSalePayments($this->sale($totals, $grandTotal, $paidAmount));
    }

    private function sale(array $totals, float $grandTotal, float $paidAmount, string $status = 'completed'): Sale
    {
        $sale = new Sale;
        $sale->id = 10;
        $sale->GrandTotal = $grandTotal;
        $sale->paid_amount = $paidAmount;
        $sale->statut = $status;

        $details = new Collection;
        foreach ($totals as $index => $total) {
            $detail = new SaleDetail;
            $detail->id = $index + 1;
            $detail->sale_id = 10;
            $detail->quantity = 1;
            $detail->total = $total;
            $details->push($detail);
        }
        $sale->setRelation('details', $details);

        return $sale;
    }
}
