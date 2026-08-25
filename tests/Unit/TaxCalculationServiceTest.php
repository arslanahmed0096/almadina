<?php

namespace Tests\Unit;

use App\Services\Tax\TaxCalculationService;
use PHPUnit\Framework\TestCase;

class TaxCalculationServiceTest extends TestCase
{
    private TaxCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaxCalculationService;
    }

    public function test_percentage_additive_tax(): void
    {
        $rows = $this->service->calculateLine('100.00', '2', [$this->tax('GST', 'percentage', '18', 'additive')]);
        $this->assertSame('200.000000', $rows[0]['taxable_base']);
        $this->assertSame('36.000000', $rows[0]['tax_amount']);
    }

    public function test_fixed_tax_is_once_per_line(): void
    {
        $rows = $this->service->calculateLine('100', '9', [$this->tax('LEVY', 'fixed', '12.50', 'additive')]);
        $this->assertSame('12.500000', $rows[0]['tax_amount']);
    }

    public function test_inclusive_formula_extracts_tax(): void
    {
        $rows = $this->service->calculateLine('118', '1', [$this->tax('GST', 'percentage', '18', 'inclusive')]);
        $this->assertSame('18.000000', $rows[0]['tax_amount']);
    }

    public function test_deductive_tax_reduces_grand_total(): void
    {
        $rows = $this->service->calculateLine('1000', '1', [$this->tax('WHT', 'percentage', '4', 'deductive')]);
        $totals = $this->service->totals('1000', '20', '5', $rows);
        $this->assertSame('40.000000', $totals['deductive']);
        $this->assertSame('945.00', $totals['grand_total']);
    }

    public function test_non_compound_taxes_share_original_base_and_compound_tax_uses_prior_additive_only(): void
    {
        $rows = $this->service->calculateLine('100', '1', [
            $this->tax('A', 'percentage', '10', 'additive', 10),
            $this->tax('W', 'percentage', '5', 'deductive', 20),
            $this->tax('B', 'percentage', '10', 'additive', 30, true),
        ]);
        $this->assertSame('100.000000', $rows[0]['taxable_base']);
        $this->assertSame('100.000000', $rows[1]['taxable_base']);
        $this->assertSame('110.000000', $rows[2]['taxable_base']);
        $this->assertSame('11.000000', $rows[2]['tax_amount']);
    }

    public function test_decimal_arithmetic_rounds_currency_half_up(): void
    {
        $rows = $this->service->calculateLine('0.10', '3', [$this->tax('T', 'percentage', '7.25', 'additive')]);
        $this->assertSame('0.300000', $rows[0]['taxable_base']);
        $this->assertSame('0.021750', $rows[0]['tax_amount']);
    }

    private function tax(string $code, string $calculation, string $rate, string $behavior, int $priority = 10, bool $compound = false): object
    {
        return (object) ['id' => $priority, 'name' => $code, 'code' => $code, 'calculation_type' => $calculation, 'rate' => $rate, 'behavior' => $behavior, 'priority' => $priority, 'is_compound' => $compound];
    }
}
