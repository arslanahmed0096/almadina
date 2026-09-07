<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceAllowedIps;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

class SupplierOpeningBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnforceAllowedIps::class);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->unsignedInteger('role_id')->nullable();
            $table->boolean('statut')->default(true);
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('label')->nullable();
            $table->integer('status')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('role_id');
            $table->unsignedInteger('user_id');
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
        });
        Schema::create('permission_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('user_id');
            $table->string('type')->default('allow');
            $table->timestamps();
        });
        Schema::create('providers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('adresse')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('account_name');
            $table->string('account_num')->nullable();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->softDeletes();
        });
        Schema::create('product_variants', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->string('name');
            $table->string('code')->nullable();
        });
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('number');
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('warehouse_id');
            $table->string('status');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('purchase_order_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('unit_name')->nullable();
            $table->decimal('ordered_quantity', 15, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->string('discount_method')->default('fixed');
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_subtotal', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('gate_passes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('number');
            $table->string('supplier_gate_pass_number')->nullable();
            $table->unsignedInteger('purchase_order_id')->nullable();
            $table->string('receipt_type')->default('purchase_order');
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('warehouse_id');
            $table->dateTime('delivered_at');
            $table->string('bilty_number')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('gate_pass_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('gate_pass_id');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('unit_name')->nullable();
            $table->decimal('delivered_quantity', 15, 2);
            $table->decimal('accepted_quantity', 15, 2)->default(0);
            $table->decimal('rejected_quantity', 15, 2)->default(0);
            $table->decimal('short_quantity', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('number');
            $table->string('supplier_invoice_number');
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('purchase_order_id')->nullable();
            $table->unsignedInteger('gate_pass_id')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('tax_type');
            $table->string('status');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('freight_charges', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('supplier_invoice_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('supplier_invoice_id');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->string('discount_method')->default('fixed');
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_subtotal', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->date('date');
            $table->string('Ref');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('warehouse_id');
            $table->unsignedInteger('purchase_order_id')->nullable();
            $table->unsignedInteger('gate_pass_id')->nullable();
            $table->unsignedInteger('supplier_invoice_id')->nullable();
            $table->string('sales_tax_invoice_no')->nullable();
            $table->string('delivery_note_no')->nullable();
            $table->string('invoice_tax_type')->nullable();
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('TaxNet', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->string('statut');
            $table->string('payment_statut');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('purchase_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('purchase_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->decimal('cost', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->string('discount_method')->default('2');
            $table->decimal('TaxNet', 15, 2)->default(0);
            $table->decimal('sales_tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('payment_purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->date('date');
            $table->string('Ref');
            $table->unsignedInteger('purchase_id');
            $table->unsignedInteger('account_id')->nullable();
            $table->decimal('montant', 15, 2);
            $table->unsignedInteger('payment_method_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('provider_opening_balance_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->date('date');
            $table->string('Ref');
            $table->decimal('montant', 15, 2);
            $table->decimal('change', 15, 2)->default(0);
            $table->unsignedInteger('payment_method_id')->nullable();
            $table->unsignedInteger('account_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('provider_opening_balance_payments');
        Schema::dropIfExists('payment_purchases');
        Schema::dropIfExists('purchase_details');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('supplier_invoice_items');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('gate_pass_items');
        Schema::dropIfExists('gate_passes');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_supplier_opening_balance_requires_its_permission_and_saves_the_date(): void
    {
        $generalEdit = Permission::create(['name' => 'Suppliers_edit']);
        $openingBalancePermission = Permission::create(['name' => 'supplier_opening_balance']);

        $editorRole = Role::create(['name' => 'Supplier Editor']);
        $editorRole->permissions()->attach($generalEdit->id);
        $editor = User::create(['username' => 'supplier-editor', 'statut' => 1]);
        $editor->roles()->attach($editorRole->id);

        $managerRole = Role::create(['name' => 'Opening Balance Manager']);
        $managerRole->permissions()->attach($openingBalancePermission->id);
        $manager = User::create(['username' => 'opening-balance-manager', 'statut' => 1]);
        $manager->roles()->attach($managerRole->id);

        $provider = Provider::create([
            'name' => 'Test Supplier',
            'code' => 1,
            'opening_balance' => 0,
        ]);

        Passport::actingAs($editor);
        $this->postJson("/api/providers/{$provider->id}/opening-balance", [
            'opening_balance' => '125.50',
            'date' => '2026-09-05',
        ])->assertForbidden();

        Passport::actingAs($manager);
        $this->postJson("/api/providers/{$provider->id}/opening-balance", [
            'opening_balance' => '125.50',
            'date' => '2026-09-05',
        ])->assertOk()->assertJsonPath('opening_balance', 125.5)
            ->assertJsonPath('opening_balance_date', '2026-09-05');

        $this->assertDatabaseHas('providers', [
            'id' => $provider->id,
            'opening_balance' => 125.50,
            'opening_balance_date' => '2026-09-05',
        ]);
    }

    public function test_supplier_report_includes_opening_balance_history_and_all_supplier_purchases(): void
    {
        $reportPermission = Permission::create(['name' => 'Reports_suppliers']);
        $reportRole = Role::create(['name' => 'Supplier Reporter']);
        $reportRole->permissions()->attach($reportPermission->id);
        $reporter = User::create(['username' => 'reporter', 'statut' => 1]);
        $reporter->roles()->attach($reportRole->id);

        $provider = Provider::create([
            'name' => 'Statement Supplier',
            'code' => 20,
            'opening_balance' => 150,
            'opening_balance_date' => '2026-07-21',
        ]);
        Schema::getConnection()->table('warehouses')->insert(['id' => 1, 'name' => 'Main Branch']);
        Schema::getConnection()->table('payment_methods')->insert(['id' => 1, 'name' => 'Cash']);
        Schema::getConnection()->table('provider_opening_balance_payments')->insert([
            'provider_id' => $provider->id,
            'date' => '2026-08-01',
            'Ref' => 'SUP-OB-1',
            'montant' => 50,
            'payment_method_id' => 1,
        ]);
        Schema::getConnection()->table('purchases')->insert([
            [
                'date' => '2026-08-05', 'Ref' => 'PUR-1', 'user_id' => 999,
                'provider_id' => $provider->id, 'warehouse_id' => 1,
                'GrandTotal' => 100, 'paid_amount' => 40,
                'statut' => 'received', 'payment_statut' => 'partial',
            ],
            [
                'date' => '2026-08-06', 'Ref' => 'PUR-2', 'user_id' => 999,
                'provider_id' => $provider->id, 'warehouse_id' => 1,
                'GrandTotal' => 80, 'paid_amount' => 0,
                'statut' => 'ordered', 'payment_statut' => 'unpaid',
            ],
        ]);

        Passport::actingAs($reporter);
        $this->getJson("/api/report/provider/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('report.opening_balance_original', 200)
            ->assertJsonPath('report.opening_balance_paid', 50)
            ->assertJsonPath('report.opening_balance_remaining', 150)
            ->assertJsonPath('report.purchase_due', 60)
            ->assertJsonPath('report.total_due', 210)
            ->assertJsonPath('report.opening_balance_payments.0.Ref', 'SUP-OB-1');

        $this->getJson("/api/report/provider_purchases?page=1&limit=10&search=&id={$provider->id}")
            ->assertOk()
            ->assertJsonPath('totalRows', 2)
            ->assertJsonPath('purchases.0.Ref', 'PUR-2')
            ->assertJsonPath('purchases.1.Ref', 'PUR-1');
    }

    public function test_supplier_report_exposes_procurement_documents_items_gst_and_payment_channel(): void
    {
        $reportPermission = Permission::create(['name' => 'Reports_suppliers']);
        $reportRole = Role::create(['name' => 'Procurement Reporter']);
        $reportRole->permissions()->attach($reportPermission->id);
        $reporter = User::create(['username' => 'procurement-reporter', 'statut' => 1]);
        $reporter->roles()->attach($reportRole->id);

        $provider = Provider::create(['name' => 'GST Supplier', 'code' => 30, 'opening_balance' => 0]);
        $db = Schema::getConnection();
        $db->table('warehouses')->insert(['id' => 1, 'name' => 'Main Branch']);
        $db->table('accounts')->insert(['id' => 1, 'account_name' => 'Business Bank', 'account_num' => 'PK-001']);
        $db->table('payment_methods')->insert(['id' => 1, 'name' => 'Bank Transfer']);
        $db->table('products')->insert(['id' => 1, 'name' => 'LED Television', 'code' => 'TV-01']);
        $db->table('purchase_orders')->insert([
            'id' => 1, 'number' => 'PO-2026-000001', 'order_date' => '2026-09-01',
            'expected_delivery_date' => '2026-09-03', 'provider_id' => $provider->id,
            'warehouse_id' => 1, 'status' => 'fully_invoiced', 'subtotal' => 1000,
            'discount_total' => 50, 'tax_total' => 171, 'grand_total' => 1121,
        ]);
        $db->table('purchase_order_items')->insert([
            'id' => 1, 'purchase_order_id' => 1, 'product_id' => 1,
            'product_name' => 'LED Television', 'sku' => 'TV-01', 'unit_name' => 'Pc',
            'ordered_quantity' => 2, 'unit_price' => 500, 'discount' => 5,
            'discount_method' => 'percentage', 'tax_name' => 'GST', 'tax_rate' => 18,
            'tax_amount' => 171, 'line_subtotal' => 950, 'line_total' => 1121,
        ]);
        $db->table('gate_passes')->insert([
            'id' => 1, 'number' => 'GP-2026-000001', 'supplier_gate_pass_number' => 'SUP-GP-77',
            'purchase_order_id' => 1, 'receipt_type' => 'purchase_order', 'provider_id' => $provider->id,
            'warehouse_id' => 1, 'delivered_at' => '2026-09-03 10:00:00', 'status' => 'accepted',
        ]);
        $db->table('gate_pass_items')->insert([
            'id' => 1, 'gate_pass_id' => 1, 'product_name' => 'LED Television', 'sku' => 'TV-01',
            'unit_name' => 'Pc', 'delivered_quantity' => 2, 'accepted_quantity' => 2,
            'rejected_quantity' => 0, 'short_quantity' => 0,
        ]);
        $db->table('supplier_invoices')->insert([
            'id' => 1, 'number' => 'SINV-2026-000001', 'supplier_invoice_number' => 'GST-INV-900',
            'provider_id' => $provider->id, 'purchase_order_id' => 1, 'gate_pass_id' => 1,
            'invoice_date' => '2026-09-04', 'due_date' => '2026-10-04', 'tax_type' => 'gst',
            'status' => 'posted', 'subtotal' => 1000, 'discount_total' => 50,
            'tax_total' => 171, 'other_charges' => 0, 'freight_charges' => 0, 'grand_total' => 1121,
        ]);
        $db->table('supplier_invoice_items')->insert([
            'id' => 1, 'supplier_invoice_id' => 1, 'product_name' => 'LED Television', 'sku' => 'TV-01',
            'quantity' => 2, 'unit_cost' => 500, 'discount' => 5, 'discount_method' => 'percentage',
            'tax_name' => 'GST', 'tax_rate' => 18, 'tax_amount' => 171,
            'line_subtotal' => 950, 'line_total' => 1121,
        ]);
        $db->table('purchases')->insert([
            'id' => 1, 'date' => '2026-09-04', 'Ref' => 'PUR-2026-000001', 'user_id' => $reporter->id,
            'provider_id' => $provider->id, 'warehouse_id' => 1, 'purchase_order_id' => 1,
            'gate_pass_id' => 1, 'supplier_invoice_id' => 1, 'sales_tax_invoice_no' => 'GST-INV-900',
            'delivery_note_no' => 'SUP-GP-77', 'invoice_tax_type' => 'gst', 'GrandTotal' => 1121,
            'paid_amount' => 600, 'TaxNet' => 171, 'discount' => 50,
            'statut' => 'received', 'payment_statut' => 'partial',
        ]);
        $db->table('purchase_details')->insert([
            'purchase_id' => 1, 'product_id' => 1, 'quantity' => 2, 'cost' => 500,
            'discount' => 5, 'discount_method' => '1', 'TaxNet' => 18,
            'sales_tax' => 171, 'total' => 1121,
        ]);
        $db->table('payment_purchases')->insert([
            'date' => '2026-09-05', 'Ref' => 'PAY-PUR-1', 'purchase_id' => 1,
            'account_id' => 1, 'montant' => 600, 'payment_method_id' => 1,
            'notes' => 'Online bank transfer',
        ]);

        Passport::actingAs($reporter);
        $this->getJson("/api/report/provider_procurement?type=purchase_orders&page=1&limit=10&id={$provider->id}")
            ->assertOk()->assertJsonPath('totalRows', 1)
            ->assertJsonPath('rows.0.purchase_order_number', 'PO-2026-000001')
            ->assertJsonPath('rows.0.product_name', 'LED Television')
            ->assertJsonPath('rows.0.tax_name', 'GST');
        $this->getJson("/api/report/provider_procurement?type=gate_passes&page=1&limit=10&id={$provider->id}")
            ->assertOk()->assertJsonPath('rows.0.supplier_gate_pass_number', 'SUP-GP-77')
            ->assertJsonPath('rows.0.accepted_quantity', 2);
        $this->getJson("/api/report/provider_procurement?type=supplier_invoices&page=1&limit=10&id={$provider->id}")
            ->assertOk()->assertJsonPath('rows.0.supplier_invoice_number', 'GST-INV-900')
            ->assertJsonPath('rows.0.tax_type', 'gst')
            ->assertJsonPath('rows.0.purchase_reference', 'PUR-2026-000001');
        $this->getJson("/api/report/provider_purchases?page=1&limit=10&search=&id={$provider->id}")
            ->assertOk()->assertJsonPath('purchases.0.products_summary', 'LED Television')
            ->assertJsonPath('purchases.0.supplier_invoice_number', 'GST-INV-900');
        $this->getJson("/api/report/provider_payments?page=1&limit=10&search=&id={$provider->id}")
            ->assertOk()->assertJsonPath('payments.0.payment_method', 'Bank Transfer')
            ->assertJsonPath('payments.0.account_name', 'Business Bank')
            ->assertJsonPath('payments.0.account_num', 'PK-001')
            ->assertJsonPath('payments.0.supplier_invoice_number', 'GST-INV-900');
    }
}
