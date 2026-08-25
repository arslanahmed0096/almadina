<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use App\Models\TransactionTaxSnapshot;
use App\Services\Tax\TransactionTaxService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxReturnReversalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('sales', function (Blueprint $t) { $t->increments('id'); $t->boolean('is_pos')->default(false); $t->softDeletes(); $t->timestamps(); });
        Schema::create('sale_details', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('sale_id'); $t->unsignedInteger('product_id'); $t->unsignedInteger('product_variant_id')->nullable(); $t->decimal('quantity', 20, 6); $t->decimal('price', 20, 6)->default(0); $t->decimal('total', 20, 6)->default(0); $t->decimal('TaxNet', 20, 6)->default(0); $t->string('tax_method')->default('1'); $t->decimal('discount', 20, 6)->default(0); $t->string('discount_method')->default('1'); $t->string('price_type')->default('retail'); $t->timestamps(); });
        Schema::create('sale_returns', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('sale_id')->nullable(); $t->softDeletes(); $t->timestamps(); });
        Schema::create('sale_return_details', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('sale_return_id'); $t->unsignedInteger('product_id'); $t->unsignedInteger('product_variant_id')->nullable(); $t->decimal('quantity', 20, 6); $t->decimal('price', 20, 6)->default(0); $t->decimal('total', 20, 6)->default(0); $t->timestamps(); });
        Schema::create('transaction_tax_snapshots', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('transaction_type'); $t->unsignedInteger('transaction_id'); $t->unsignedInteger('transaction_line_id')->nullable(); $t->unsignedInteger('tax_id')->nullable(); $t->string('tax_name'); $t->string('tax_code'); $t->string('calculation_type'); $t->decimal('rate', 20, 6); $t->string('behavior'); $t->unsignedInteger('price_type_id')->nullable(); $t->string('price_type_code'); $t->string('price_type_name'); $t->decimal('quantity', 20, 6); $t->decimal('taxable_base', 20, 6); $t->decimal('tax_amount', 20, 6); $t->unsignedSmallInteger('priority'); $t->boolean('is_compound'); $t->boolean('is_reversal'); $t->unsignedBigInteger('reversal_of_id')->nullable(); $t->timestamps(); });
    }

    public function test_partial_and_full_returns_reverse_original_snapshot_proportionally(): void
    {
        $sale = Sale::create(['is_pos' => false]);
        $line = SaleDetail::create(['sale_id' => $sale->id, 'product_id' => 7, 'quantity' => 10, 'price' => 10, 'total' => 100]);
        $source = TransactionTaxSnapshot::create([
            'transaction_type' => 'sale_invoice', 'transaction_id' => $sale->id, 'transaction_line_id' => $line->id,
            'tax_name' => 'GST at sale time', 'tax_code' => 'GST', 'calculation_type' => 'percentage', 'rate' => 18,
            'behavior' => 'additive', 'price_type_code' => 'price', 'price_type_name' => 'Sale Price',
            'quantity' => 10, 'taxable_base' => 100, 'tax_amount' => 18, 'priority' => 10,
            'is_compound' => false, 'is_reversal' => false,
        ]);

        $first = SaleReturn::create(['sale_id' => $sale->id]);
        SaleReturnDetails::create(['sale_return_id' => $first->id, 'product_id' => 7, 'quantity' => 2]);
        $firstRows = app(TransactionTaxService::class)->reverseSaleReturn($first);
        $this->assertSame('3.600000', $firstRows->first()->tax_amount);
        $this->assertSame('20.000000', $firstRows->first()->taxable_base);
        $this->assertSame('18.000000', $firstRows->first()->rate);
        $this->assertSame($source->id, $firstRows->first()->reversal_of_id);

        $remaining = SaleReturn::create(['sale_id' => $sale->id]);
        SaleReturnDetails::create(['sale_return_id' => $remaining->id, 'product_id' => 7, 'quantity' => 8]);
        $remainingRows = app(TransactionTaxService::class)->reverseSaleReturn($remaining);
        $this->assertSame('14.400000', $remainingRows->first()->tax_amount);

        $tooMuch = SaleReturn::create(['sale_id' => $sale->id]);
        SaleReturnDetails::create(['sale_return_id' => $tooMuch->id, 'product_id' => 7, 'quantity' => 1]);
        $this->expectException(ValidationException::class);
        app(TransactionTaxService::class)->reverseSaleReturn($tooMuch);
    }
}
