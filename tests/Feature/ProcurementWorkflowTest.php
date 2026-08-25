<?php

namespace Tests\Feature;

use App\Models\GatePass;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Provider;
use App\Models\Role;
use App\Models\SupplierInvoice;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Procurement\GatePassService;
use App\Services\Procurement\PurchaseOrderProgressService;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Procurement\SupplierInvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcurementWorkflowTest extends TestCase
{
    private User $user;

    private Provider $provider;

    private Warehouse $warehouse;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->legacySchema();
        (require database_path('migrations/2026_08_25_000001_create_procurement_workflow.php'))->up();
        (require database_path('migrations/2026_08_25_000002_support_direct_gate_passes.php'))->up();
        $this->user = User::create(['username' => 'admin', 'email' => 'admin@example.test', 'password' => bcrypt('secret'), 'role_id' => 1, 'statut' => 1, 'is_all_warehouses' => 1]);
        $this->provider = Provider::create(['name' => 'Nasgas', 'code' => 1, 'email' => '', 'phone' => '', 'country' => '', 'city' => '', 'adresse' => '', 'tax_status' => 'non_gst']);
        $this->warehouse = Warehouse::create(['name' => 'Main Warehouse']);
        $this->unit = Unit::create(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1, 'is_active' => 1]);
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (array_reverse(Schema::getTableListing()) as $table) {
            Schema::drop($table);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_complete_700_unit_partial_delivery_and_split_invoice_workflow_does_not_duplicate_stock(): void
    {
        $products = collect([
            ['Washing Machine Model A', 150], ['Dryer Model B', 200], ['Refrigerator Model C', 150], ['Other Products', 200],
        ])->map(fn ($row, $i) => Product::create(['name' => $row[0], 'code' => 'SKU-'.($i + 1), 'cost' => 100, 'unit_id' => $this->unit->id, 'unit_purchase_id' => $this->unit->id, 'is_variant' => 0, 'is_active' => 1])->setAttribute('order_qty', $row[1]));

        $po = app(PurchaseOrderService::class)->create([
            'order_date' => '2026-08-25', 'provider_id' => $this->provider->id, 'warehouse_id' => $this->warehouse->id,
            'items' => $products->map(fn ($p) => ['product_id' => $p->id, 'unit_id' => $this->unit->id, 'quantity' => $p->order_qty, 'unit_price' => 100])->all(),
        ], $this->user);
        $this->assertSame('draft', $po->status);
        app(PurchaseOrderService::class)->issue($po, $this->user);

        $gp1 = app(GatePassService::class)->create($po->fresh(), [
            'delivered_at' => '2026-08-25 10:00:00', 'supplier_gate_pass_number' => 'NG-GP-101', 'bilty_number' => 'BL-1001', 'vehicle_number' => 'ABC-123',
            'items' => [['purchase_order_item_id' => $po->items[0]->id, 'delivered_quantity' => 150, 'accepted_quantity' => 150, 'rejected_quantity' => 0]],
        ], $this->user);
        app(GatePassService::class)->confirm($gp1, $this->user);
        $this->assertSame(150.0, (float) product_warehouse::where('product_id', $products[0]->id)->value('qte'));
        $this->expectStockIdempotency($gp1);

        $invoice1 = $this->invoice($gp1, 'NG-INV-001', 90);
        $invoice2 = $this->invoice($gp1, 'NG-INV-002', 60);
        $purchase1 = app(SupplierInvoiceService::class)->createPurchase($invoice1, $this->user);
        $purchase2 = app(SupplierInvoiceService::class)->createPurchase($invoice2, $this->user);
        $this->assertTrue($purchase1->inventory_already_received && $purchase2->inventory_already_received);
        $this->assertSame(150.0, (float) product_warehouse::where('product_id', $products[0]->id)->value('qte'));

        $gp2 = app(GatePassService::class)->create($po->fresh(), [
            'delivered_at' => '2026-08-26 10:00:00', 'supplier_gate_pass_number' => 'NG-GP-102', 'bilty_number' => 'BL-1002', 'vehicle_number' => 'XYZ-456',
            'items' => [['purchase_order_item_id' => $po->items[1]->id, 'delivered_quantity' => 140, 'accepted_quantity' => 140, 'rejected_quantity' => 0]],
        ], $this->user);
        app(GatePassService::class)->confirm($gp2, $this->user);

        $progress = app(PurchaseOrderProgressService::class)->progress($po->fresh());
        $this->assertSame(700.0, $progress['totals']['ordered']);
        $this->assertSame(290.0, $progress['totals']['received']);
        $this->assertSame(410.0, $progress['totals']['remaining']);
        $this->assertSame(150.0, $progress['totals']['invoiced']);
        $this->assertSame(150.0, $progress['totals']['purchased']);
    }

    public function test_non_po_products_and_over_receipt_are_rejected_server_side(): void
    {
        [$po, $product] = $this->singleLineOrder(10);
        $other = Product::create(['name' => 'Not ordered', 'code' => 'NOPE', 'cost' => 1, 'unit_id' => $this->unit->id, 'unit_purchase_id' => $this->unit->id, 'is_active' => 1]);
        try {
            app(GatePassService::class)->create($po, ['delivered_at' => now(), 'items' => [['purchase_order_item_id' => 999999, 'product_id' => $other->id, 'delivered_quantity' => 1, 'accepted_quantity' => 1]]], $this->user);
            $this->fail('A non-PO item was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.purchase_order_item_id', $e->errors());
        }
        $gate = app(GatePassService::class)->create($po, ['delivered_at' => now(), 'items' => [['purchase_order_item_id' => $po->items[0]->id, 'delivered_quantity' => 11, 'accepted_quantity' => 11]]], $this->user);
        $this->expectException(ValidationException::class);
        app(GatePassService::class)->confirm($gate, $this->user);
    }

    public function test_direct_gate_pass_can_receive_stock_and_flow_to_supplier_invoice_without_purchase_order(): void
    {
        $product = Product::create([
            'name' => 'Unexpected Delivery', 'code' => 'DIRECT-1', 'cost' => 125,
            'unit_id' => $this->unit->id, 'unit_purchase_id' => $this->unit->id,
            'is_variant' => 0, 'is_active' => 1,
        ]);

        $gate = app(GatePassService::class)->create(null, [
            'provider_id' => $this->provider->id,
            'warehouse_id' => $this->warehouse->id,
            'delivered_at' => '2026-08-25 12:00:00',
            'supplier_gate_pass_number' => 'DIRECT-GP-1',
            'items' => [[
                'product_id' => $product->id,
                'unit_id' => $this->unit->id,
                'unit_cost' => 125,
                'delivered_quantity' => 4,
                'accepted_quantity' => 4,
                'rejected_quantity' => 0,
            ]],
        ], $this->user);

        $this->assertNull($gate->purchase_order_id);
        $this->assertSame('direct', $gate->receipt_type);
        app(GatePassService::class)->confirm($gate, $this->user);
        $this->assertSame(4.0, (float) product_warehouse::where('product_id', $product->id)->value('qte'));
        $this->expectStockIdempotency($gate);

        $invoice = app(SupplierInvoiceService::class)->create($gate->fresh('items'), [
            'supplier_invoice_number' => 'DIRECT-INV-1',
            'invoice_date' => '2026-08-25',
            'items' => [[
                'gate_pass_item_id' => $gate->items()->first()->id,
                'quantity' => 4,
                'unit_cost' => 125,
            ]],
        ], $this->user);
        $this->assertNull($invoice->purchase_order_id);

        $purchase = app(SupplierInvoiceService::class)->createPurchase($invoice, $this->user);
        $this->assertNull($purchase->purchase_order_id);
        $this->assertSame(4.0, (float) product_warehouse::where('product_id', $product->id)->value('qte'));
        $this->assertDatabaseHas('purchase_details', [
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);
    }

    public function test_invoice_cannot_exceed_accepted_quantity_or_create_duplicate_purchase(): void
    {
        [$po] = $this->singleLineOrder(10);
        $gate = app(GatePassService::class)->create($po, ['delivered_at' => now(), 'items' => [['purchase_order_item_id' => $po->items[0]->id, 'delivered_quantity' => 10, 'accepted_quantity' => 8, 'rejected_quantity' => 2]]], $this->user);
        app(GatePassService::class)->confirm($gate, $this->user);
        $invoice = $this->invoice($gate, 'SPLIT-1', 8);
        try {
            $this->invoice($gate, 'SPLIT-2', 1);
            $this->fail('Over-invoicing was accepted.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        app(SupplierInvoiceService::class)->createPurchase($invoice, $this->user);
        $this->expectException(ValidationException::class);
        app(SupplierInvoiceService::class)->createPurchase($invoice->fresh(), $this->user);
    }

    public function test_supplier_tax_default_is_snapshotted_and_non_gst_invoice_has_no_tax(): void
    {
        [$po] = $this->singleLineOrder(5);
        $gate = app(GatePassService::class)->create($po, ['delivered_at' => now(), 'items' => [['purchase_order_item_id' => $po->items[0]->id, 'delivered_quantity' => 5, 'accepted_quantity' => 5]]], $this->user);
        app(GatePassService::class)->confirm($gate, $this->user);
        $invoice = $this->invoice($gate, 'NON-GST-1', 5);
        $this->assertSame('non_gst', $invoice->tax_type);
        $this->assertSame(0.0, (float) $invoice->tax_total);
        $this->assertFalse($invoice->tax_type_overridden);
    }

    public function test_gst_supplier_default_uses_configured_tax_and_manual_non_gst_override_is_audited(): void
    {
        $this->provider->update(['tax_status' => 'gst', 'strn_number' => 'STRN-123', 'ntn_number' => 'NTN-456']);
        $gst = Tax::create(['scope_key' => 'global', 'name' => 'GST', 'code' => 'GST', 'calculation_type' => 'percentage', 'rate' => 18, 'behavior' => 'additive', 'priority' => 10, 'is_compound' => false, 'is_active' => true]);
        $gst->transactionTypes()->create(['transaction_type' => 'purchase']);
        [$po] = $this->singleLineOrder(10);
        $gate = app(GatePassService::class)->create($po, ['delivered_at' => now(), 'items' => [['purchase_order_item_id' => $po->items[0]->id, 'delivered_quantity' => 10, 'accepted_quantity' => 10]]], $this->user);
        app(GatePassService::class)->confirm($gate, $this->user);
        $gstInvoice = app(SupplierInvoiceService::class)->create($gate->fresh('items'), [
            'supplier_invoice_number' => 'GST-1', 'invoice_date' => '2026-08-25',
            'items' => [['gate_pass_item_id' => $gate->items()->first()->id, 'quantity' => 5, 'unit_cost' => 100, 'tax_id' => $gst->id]],
        ], $this->user);
        $this->assertSame('gst', $gstInvoice->tax_type);
        $this->assertSame(90.0, (float) $gstInvoice->tax_total);
        $purchase = app(SupplierInvoiceService::class)->createPurchase($gstInvoice, $this->user);
        $this->assertSame(90.0, (float) $purchase->TaxNet);
        $this->assertDatabaseHas('transaction_tax_snapshots', ['transaction_type' => 'purchase', 'transaction_id' => $purchase->id, 'tax_code' => 'GST']);

        $override = app(SupplierInvoiceService::class)->create($gate->fresh('items'), [
            'supplier_invoice_number' => 'NON-GST-OVERRIDE', 'invoice_date' => '2026-08-25', 'tax_type' => 'non_gst', 'tax_override_reason' => 'Supplier issued a commercial invoice.',
            'items' => [['gate_pass_item_id' => $gate->items()->first()->id, 'quantity' => 5, 'unit_cost' => 100, 'tax_id' => $gst->id]],
        ], $this->user);
        $this->assertTrue($override->tax_type_overridden);
        $this->assertSame(0.0, (float) $override->tax_total);
        $this->assertDatabaseHas('procurement_audits', ['auditable_id' => $override->id, 'action' => 'tax_type_overridden']);
    }

    public function test_controlled_cancellation_reverses_gate_stock_and_financial_purchase_without_deleting_history(): void
    {
        [$po, $product] = $this->singleLineOrder(10);
        $gate = app(GatePassService::class)->create($po, ['delivered_at' => now(), 'items' => [['purchase_order_item_id' => $po->items[0]->id, 'delivered_quantity' => 5, 'accepted_quantity' => 5]]], $this->user);
        app(GatePassService::class)->confirm($gate, $this->user);
        $this->assertSame(5.0, (float) product_warehouse::where('product_id', $product->id)->value('qte'));
        app(GatePassService::class)->cancel($gate->fresh(), $this->user, 'Receipt entered against the wrong vehicle.');
        $this->assertSame(0.0, (float) product_warehouse::where('product_id', $product->id)->value('qte'));
        $this->assertDatabaseHas('procurement_stock_movements', ['gate_pass_id' => $gate->id, 'reversal_reason' => 'Receipt entered against the wrong vehicle.']);

        $gate2 = app(GatePassService::class)->create($po->fresh(), ['delivered_at' => now(), 'items' => [['purchase_order_item_id' => $po->items[0]->id, 'delivered_quantity' => 5, 'accepted_quantity' => 5]]], $this->user);
        app(GatePassService::class)->confirm($gate2, $this->user);
        $invoice = $this->invoice($gate2, 'CANCEL-ME', 5);
        $purchase = app(SupplierInvoiceService::class)->createPurchase($invoice, $this->user);
        app(SupplierInvoiceService::class)->cancel($invoice->fresh(), $this->user, 'Supplier replaced the invoice.');
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'posting_status' => 'cancelled']);
        $this->assertDatabaseHas('purchase_details', ['purchase_id' => $purchase->id, 'quantity' => 5]);
        $this->assertSame(5.0, (float) product_warehouse::where('product_id', $product->id)->value('qte'));
    }

    public function test_purchase_order_pdf_is_generated_from_snapshotted_lines(): void
    {
        [$po] = $this->singleLineOrder(10);
        $data = ['order' => $po->fresh(['provider', 'warehouse', 'items']), 'setting' => null];
        $compiled = app('blade.compiler')->compileString(file_get_contents(resource_path('views/pdf/purchase_order.blade.php')));
        extract($data);
        $__env = app('view');
        ob_start();
        eval('?>'.$compiled);
        $html = ob_get_clean();
        $this->assertStringContainsString($po->number, $html);
        $output = Pdf::loadHTML($html)->output();
        $this->assertStringStartsWith('%PDF-', $output);
    }

    private function singleLineOrder(float $quantity): array
    {
        $product = Product::create(['name' => 'Washing Machine', 'code' => 'WM-A', 'cost' => 100, 'unit_id' => $this->unit->id, 'unit_purchase_id' => $this->unit->id, 'is_active' => 1]);
        $po = app(PurchaseOrderService::class)->create(['order_date' => '2026-08-25', 'provider_id' => $this->provider->id, 'warehouse_id' => $this->warehouse->id, 'items' => [['product_id' => $product->id, 'unit_id' => $this->unit->id, 'quantity' => $quantity, 'unit_price' => 100]]], $this->user);
        app(PurchaseOrderService::class)->issue($po, $this->user);

        return [$po->fresh('items'), $product];
    }

    private function invoice(GatePass $gate, string $number, float $quantity): SupplierInvoice
    {
        return app(SupplierInvoiceService::class)->create($gate->fresh('items'), [
            'supplier_invoice_number' => $number, 'invoice_date' => '2026-08-25',
            'items' => [['gate_pass_item_id' => $gate->items()->first()->id, 'quantity' => $quantity, 'unit_cost' => 100]],
        ], $this->user);
    }

    private function expectStockIdempotency(GatePass $gate): void
    {
        try {
            app(GatePassService::class)->confirm($gate->fresh(), $this->user);
            $this->fail('Duplicate confirmation succeeded.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function legacySchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('label')->nullable();
            $t->boolean('status')->default(1);
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->unique();
            $t->string('label')->nullable();
            $t->text('description')->nullable();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('permission_id');
            $t->unsignedInteger('role_id');
            $t->unique(['permission_id', 'role_id']);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('username');
            $t->string('email');
            $t->string('password');
            $t->integer('role_id')->nullable();
            $t->boolean('statut')->default(1);
            $t->boolean('is_all_warehouses')->default(false);
            $t->boolean('record_view')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->unsignedInteger('user_id');
            $t->unsignedInteger('role_id');
        });
        Schema::create('permission_user', function (Blueprint $t) {
            $t->unsignedInteger('user_id');
            $t->unsignedInteger('permission_id');
            $t->string('type');
            $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('mobile')->nullable();
            $t->string('country')->nullable();
            $t->string('city')->nullable();
            $t->string('email')->nullable();
            $t->string('zip')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('user_warehouse', function (Blueprint $t) {
            $t->unsignedInteger('user_id');
            $t->unsignedInteger('warehouse_id');
        });
        Schema::create('providers', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->integer('code');
            $t->string('email');
            $t->string('phone');
            $t->string('country');
            $t->string('city');
            $t->string('adresse');
            $t->string('tax_number')->nullable();
            $t->string('account_title')->nullable();
            $t->decimal('opening_balance', 20, 6)->default(0);
            $t->decimal('credit_limit', 20, 6)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('units', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('ShortName');
            $t->integer('base_unit')->nullable();
            $t->string('operator')->nullable();
            $t->decimal('operator_value', 20, 6)->default(1);
            $t->boolean('is_active')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('code');
            $t->decimal('cost', 20, 6)->default(0);
            $t->integer('unit_id')->nullable();
            $t->integer('unit_purchase_id')->nullable();
            $t->boolean('is_variant')->default(0);
            $t->boolean('is_active')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_variants', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->string('name');
            $t->string('code')->nullable();
            $t->decimal('cost', 20, 6)->default(0);
            $t->timestamps();
        });
        Schema::create('product_warehouse', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('warehouse_id');
            $t->decimal('qte', 20, 6)->default(0);
            $t->boolean('manage_stock')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('tax_price_types', function (Blueprint $t) {
            $t->increments('id');
            $t->string('code')->unique();
            $t->string('name');
            $t->string('product_field');
            $t->boolean('is_purchase')->default(0);
            $t->boolean('is_sale')->default(0);
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
        Schema::create('taxes', function (Blueprint $t) {
            $t->increments('id');
            $t->string('scope_key')->default('global');
            $t->integer('company_id')->nullable();
            $t->string('name');
            $t->string('code');
            $t->string('calculation_type')->default('percentage');
            $t->decimal('rate', 20, 6)->default(0);
            $t->string('behavior')->default('additive');
            $t->date('effective_start_date')->nullable();
            $t->date('effective_end_date')->nullable();
            $t->integer('priority')->default(10);
            $t->boolean('is_compound')->default(0);
            $t->boolean('is_active')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('tax_transaction_types', function (Blueprint $t) {
            $t->integer('tax_id');
            $t->string('transaction_type');
        });
        Schema::create('tax_price_type', function (Blueprint $t) {
            $t->integer('tax_id');
            $t->integer('tax_price_type_id');
        });
        Schema::create('tax_warehouse', function (Blueprint $t) {
            $t->integer('tax_id');
            $t->integer('warehouse_id');
        });
        Schema::create('transaction_tax_snapshots', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('transaction_type');
            $t->integer('transaction_id');
            $t->integer('transaction_line_id')->nullable();
            $t->integer('tax_id')->nullable();
            $t->string('tax_name');
            $t->string('tax_code');
            $t->string('calculation_type');
            $t->decimal('rate', 20, 6);
            $t->string('behavior');
            $t->integer('price_type_id')->nullable();
            $t->string('price_type_code');
            $t->string('price_type_name');
            $t->decimal('quantity', 20, 6);
            $t->decimal('taxable_base', 20, 6);
            $t->decimal('tax_amount', 20, 6);
            $t->integer('priority')->default(10);
            $t->boolean('is_compound')->default(0);
            $t->boolean('is_reversal')->default(0);
            $t->integer('reversal_of_id')->nullable();
            $t->timestamps();
        });
        Schema::create('purchases', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('user_id');
            $t->string('Ref');
            $t->string('sales_tax_invoice_no')->nullable();
            $t->string('delivery_note_no')->nullable();
            $t->date('date');
            $t->time('time')->nullable();
            $t->integer('provider_id');
            $t->integer('warehouse_id');
            $t->decimal('tax_rate', 20, 6)->default(0);
            $t->decimal('TaxNet', 20, 6)->default(0);
            $t->decimal('withholding_tax', 20, 6)->default(0);
            $t->decimal('discount', 20, 6)->default(0);
            $t->decimal('shipping', 20, 6)->default(0);
            $t->decimal('GrandTotal', 20, 6);
            $t->decimal('paid_amount', 20, 6)->default(0);
            $t->string('statut');
            $t->string('payment_statut');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('purchase_details', function (Blueprint $t) {
            $t->increments('id');
            $t->decimal('cost', 20, 6);
            $t->decimal('company_rb_price', 20, 6)->default(0);
            $t->decimal('mrp_price', 20, 6)->default(0);
            $t->decimal('TaxNet', 20, 6)->default(0);
            $t->decimal('sales_tax', 20, 6)->default(0);
            $t->decimal('withholding_tax', 20, 6)->default(0);
            $t->string('tax_method')->default('1');
            $t->decimal('discount', 20, 6)->default(0);
            $t->string('discount_method')->default('1');
            $t->integer('purchase_id');
            $t->integer('purchase_unit_id')->nullable();
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('total', 20, 6);
            $t->decimal('quantity', 20, 6);
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('notifiable_type');
            $t->integer('notifiable_id');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->increments('id');
            $t->string('CompanyName')->nullable();
            $t->string('logo')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Role::create(['id' => 1, 'name' => 'Super Admin', 'label' => 'Super Admin', 'status' => 1]);
    }
}
