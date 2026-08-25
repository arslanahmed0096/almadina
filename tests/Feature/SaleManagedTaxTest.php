<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Tax;
use App\Models\TaxPriceType;
use App\Services\Tax\TransactionTaxService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SaleManagedTaxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('warehouses', function (Blueprint $t) { $t->increments('id'); $t->string('name'); $t->softDeletes(); $t->timestamps(); });
        Schema::create('tax_price_types', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name'); $t->string('product_field'); $t->boolean('is_purchase'); $t->boolean('is_sale'); $t->unsignedSmallInteger('sort_order'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('taxes', function (Blueprint $t) { $t->increments('id'); $t->string('scope_key'); $t->unsignedInteger('company_id')->nullable(); $t->string('name'); $t->string('code'); $t->text('description')->nullable(); $t->string('calculation_type'); $t->decimal('rate', 20, 6); $t->string('behavior'); $t->date('effective_start_date')->nullable(); $t->date('effective_end_date')->nullable(); $t->unsignedSmallInteger('priority'); $t->boolean('is_compound'); $t->boolean('is_active'); $t->unsignedInteger('created_by')->nullable(); $t->unsignedInteger('updated_by')->nullable(); $t->timestamps(); $t->softDeletes(); });
        Schema::create('tax_transaction_types', function (Blueprint $t) { $t->unsignedInteger('tax_id'); $t->string('transaction_type'); });
        Schema::create('tax_price_type', function (Blueprint $t) { $t->unsignedInteger('tax_id'); $t->unsignedInteger('tax_price_type_id'); });
        Schema::create('tax_warehouse', function (Blueprint $t) { $t->unsignedInteger('tax_id'); $t->unsignedInteger('warehouse_id'); });
        Schema::create('sales', function (Blueprint $t) { $t->increments('id'); $t->boolean('is_pos')->default(false); $t->unsignedInteger('warehouse_id'); $t->decimal('TaxNet', 20, 6)->default(0); $t->decimal('tax_rate', 20, 6)->default(0); $t->decimal('discount', 20, 6)->default(0); $t->string('discount_Method')->default('2'); $t->decimal('discount_from_points', 20, 6)->default(0); $t->decimal('shipping', 20, 6)->default(0); $t->decimal('GrandTotal', 20, 6)->default(0); $t->timestamps(); });
        Schema::create('sale_details', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('sale_id'); $t->decimal('price', 20, 6); $t->decimal('TaxNet', 20, 6)->default(0); $t->decimal('discount', 20, 6)->default(0); $t->string('discount_method')->default('2'); $t->string('tax_method')->default('1'); $t->string('price_type')->default('retail'); $t->decimal('quantity', 20, 6); $t->decimal('total', 20, 6); $t->timestamps(); });
        Schema::create('transaction_tax_snapshots', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('transaction_type'); $t->unsignedInteger('transaction_id'); $t->unsignedInteger('transaction_line_id')->nullable(); $t->unsignedInteger('tax_id')->nullable(); $t->string('tax_name'); $t->string('tax_code'); $t->string('calculation_type'); $t->decimal('rate', 20, 6); $t->string('behavior'); $t->unsignedInteger('price_type_id')->nullable(); $t->string('price_type_code'); $t->string('price_type_name'); $t->decimal('quantity', 20, 6)->default(0); $t->decimal('taxable_base', 20, 6)->default(0); $t->decimal('tax_amount', 20, 6)->default(0); $t->unsignedSmallInteger('priority')->default(100); $t->boolean('is_compound')->default(false); $t->boolean('is_reversal')->default(false); $t->unsignedBigInteger('reversal_of_id')->nullable(); $t->timestamps(); });
    }

    public function test_sale_snapshot_updates_line_and_invoice_from_managed_gst(): void
    {
        \DB::table('warehouses')->insert(['id' => 1, 'name' => 'Main']);
        $price = TaxPriceType::create(['code' => 'price', 'name' => 'Sale Price', 'product_field' => 'price', 'is_purchase' => false, 'is_sale' => true, 'sort_order' => 1, 'is_active' => true]);
        $gst = Tax::create(['scope_key' => 'global', 'name' => 'General Sales Tax', 'code' => 'GST', 'calculation_type' => 'percentage', 'rate' => 18, 'behavior' => 'additive', 'priority' => 10, 'is_compound' => false, 'is_active' => true]);
        $gst->transactionTypes()->create(['transaction_type' => 'sale_invoice']);
        $gst->priceTypes()->attach($price->id);

        $sale = Sale::create(['warehouse_id' => 1, 'is_pos' => 0, 'discount' => 10, 'discount_Method' => '1', 'shipping' => 5, 'GrandTotal' => 0]);
        SaleDetail::create(['sale_id' => $sale->id, 'price' => 100, 'discount' => 10, 'discount_method' => '2', 'tax_method' => '1', 'price_type' => 'retail', 'quantity' => 2, 'total' => 0]);

        $snapshots = app(TransactionTaxService::class)->snapshotSale($sale, [['price_type' => 'retail']], null);

        $this->assertSame('180.000000', $snapshots->first()->taxable_base);
        $this->assertSame('32.400000', $snapshots->first()->tax_amount);
        $this->assertSame(212.4, SaleDetail::first()->fresh()->total);
        $this->assertSame(32.4, $sale->fresh()->TaxNet);
        $this->assertSame(199.4, $sale->fresh()->GrandTotal);
    }
}
