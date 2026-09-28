<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceTaxBreakdownViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('categories');
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    public function test_it_always_renders_the_tax_breakdown_when_no_tax_was_applied(): void
    {
        $html = view('pdf.partials.tax_breakdown', [
            'taxes' => collect(),
            'symbol' => 'PKR',
        ])->render();

        $this->assertStringContainsString('Tax breakdown', $html);
        $this->assertStringContainsString('No managed tax applied to this invoice.', $html);
    }

    public function test_it_renders_saved_invoice_tax_details(): void
    {
        $tax = (object) [
            'tax_name' => 'General Sales Tax',
            'tax_code' => 'GST',
            'price_type_code' => 'price',
            'price_type_name' => 'Sale Price',
            'taxable_base' => 305000,
            'calculation_type' => 'percentage',
            'rate' => 0,
            'behavior' => 'additive',
            'tax_amount' => 0,
            'is_reversal' => false,
        ];

        $html = view('pdf.partials.tax_breakdown', [
            'taxes' => collect([$tax]),
            'symbol' => 'PKR',
        ])->render();

        $this->assertStringContainsString('General Sales Tax (GST)', $html);
        $this->assertStringContainsString('Sale Price', $html);
        $this->assertStringContainsString('PKR 305,000.00', $html);
        $this->assertStringNotContainsString('No managed tax applied', $html);
    }
}
