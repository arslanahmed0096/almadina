<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Tax;
use App\Models\TaxPriceType;
use App\Services\Tax\TransactionTaxService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseManagedTaxTest extends TestCase
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
        Schema::create('purchases', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('warehouse_id'); $t->decimal('TaxNet', 20, 6)->default(0); $t->decimal('withholding_tax', 20, 6)->default(0); $t->decimal('discount', 20, 6)->default(0); $t->decimal('shipping', 20, 6)->default(0); $t->decimal('GrandTotal', 20, 6)->default(0); $t->timestamps(); });
        Schema::create('purchase_details', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('purchase_id'); $t->decimal('cost', 20, 6); $t->decimal('company_rb_price', 20, 6); $t->decimal('mrp_price', 20, 6); $t->decimal('TaxNet', 20, 6)->default(0); $t->decimal('sales_tax', 20, 6)->default(0); $t->decimal('withholding_tax', 20, 6)->default(0); $t->decimal('discount', 20, 6)->default(0); $t->string('discount_method')->default('1'); $t->string('tax_method')->default('1'); $t->decimal('quantity', 20, 6); $t->decimal('total', 20, 6); $t->timestamps(); });
        Schema::create('transaction_tax_snapshots', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('transaction_type'); $t->unsignedInteger('transaction_id'); $t->unsignedInteger('transaction_line_id')->nullable(); $t->unsignedInteger('tax_id')->nullable(); $t->string('tax_name'); $t->string('tax_code'); $t->string('calculation_type'); $t->decimal('rate', 20, 6); $t->string('behavior'); $t->unsignedInteger('price_type_id')->nullable(); $t->string('price_type_code'); $t->string('price_type_name'); $t->decimal('quantity', 20, 6)->default(0); $t->decimal('taxable_base', 20, 6)->default(0); $t->decimal('tax_amount', 20, 6)->default(0); $t->unsignedSmallInteger('priority')->default(100); $t->boolean('is_compound')->default(false); $t->boolean('is_reversal')->default(false); $t->unsignedBigInteger('reversal_of_id')->nullable(); $t->timestamps(); });
    }

    public function test_purchase_uses_gross_rb_for_gst_and_net_rb_for_deductive_wht(): void
    {
        Schema::disableForeignKeyConstraints();
        \DB::table('warehouses')->insert(['id' => 1, 'name' => 'Main']);
        TaxPriceType::create(['code' => 'mrp_price', 'name' => 'MRP Price', 'product_field' => 'mrp_price', 'is_purchase' => true, 'is_sale' => true, 'sort_order' => 1, 'is_active' => true]);
        $rb = TaxPriceType::create(['code' => 'company_rb_price', 'name' => 'Company/RB Price', 'product_field' => 'company_rb_price', 'is_purchase' => true, 'is_sale' => false, 'sort_order' => 2, 'is_active' => true]);
        $cost = TaxPriceType::create(['code' => 'cost', 'name' => 'Cost Price', 'product_field' => 'cost', 'is_purchase' => true, 'is_sale' => false, 'sort_order' => 3, 'is_active' => true]);
        $gst = Tax::create($this->tax('GST', '18', 'additive', 10));
        $wht = Tax::create($this->tax('WHT', '4', 'deductive', 20));
        $gst->transactionTypes()->create(['transaction_type' => 'purchase']);
        $wht->transactionTypes()->create(['transaction_type' => 'purchase']);
        $gst->priceTypes()->attach($cost->id);
        $wht->priceTypes()->attach($rb->id);

        $purchase = Purchase::create(['warehouse_id' => 1, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 0]);
        PurchaseDetail::create(['purchase_id' => $purchase->id, 'cost' => 101, 'company_rb_price' => 101, 'mrp_price' => 120, 'discount' => 10, 'discount_method' => '1', 'quantity' => 2, 'total' => 0]);
        $snapshots = app(TransactionTaxService::class)->snapshotPurchase($purchase, [[]], null);

        $this->assertSame('202.000000', $snapshots->firstWhere('tax_code', 'GST')->taxable_base);
        $this->assertSame('38.000000', $snapshots->firstWhere('tax_code', 'GST')->tax_amount);
        $this->assertSame('182.000000', $snapshots->firstWhere('tax_code', 'WHT')->taxable_base);
        $this->assertSame('8.000000', $snapshots->firstWhere('tax_code', 'WHT')->tax_amount);
        $this->assertSame(38.0, $purchase->fresh()->TaxNet);
        $this->assertSame(8.0, $purchase->fresh()->withholding_tax);
        $this->assertSame(228.0, $purchase->fresh()->GrandTotal);
        $this->assertSame(228.0, PurchaseDetail::first()->total);
    }

    private function tax(string $code, string $rate, string $behavior, int $priority): array
    {
        return ['scope_key' => 'global', 'name' => $code, 'code' => $code, 'calculation_type' => 'percentage', 'rate' => $rate, 'behavior' => $behavior, 'priority' => $priority, 'is_compound' => false, 'is_active' => true];
    }
}
