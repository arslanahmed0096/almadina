<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->recoverInterruptedFirstRun();

        Schema::table('providers', function (Blueprint $table) {
            $table->string('tax_status', 20)->default('non_gst')->after('tax_number');
            $table->string('strn_number', 80)->nullable()->after('tax_status');
            $table->string('ntn_number', 80)->nullable()->after('strn_number');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('number', 40)->unique();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable()->index();
            $table->integer('provider_id')->index();
            $table->integer('warehouse_id')->index();
            $table->integer('created_by')->index();
            $table->string('status', 40)->default('draft')->index();
            $table->text('supplier_contact_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->longText('terms')->nullable();
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('discount_total', 20, 6)->default(0);
            $table->decimal('tax_total', 20, 6)->default(0);
            $table->decimal('grand_total', 20, 6)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->integer('issued_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps(6);
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable()->index();
            $table->integer('unit_id')->nullable()->index();
            $table->string('product_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->string('sku', 192)->nullable();
            $table->string('unit_name', 100)->nullable();
            $table->decimal('ordered_quantity', 20, 6);
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('discount', 20, 6)->default(0);
            $table->string('discount_method', 20)->default('fixed');
            $table->unsignedInteger('tax_id')->nullable()->index();
            $table->string('tax_name', 120)->nullable();
            $table->decimal('tax_rate', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('line_subtotal', 20, 6);
            $table->decimal('line_total', 20, 6);
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('unit_id')->references('id')->on('units')->restrictOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
            $table->index(['purchase_order_id', 'product_id', 'product_variant_id'], 'po_items_product_idx');
        });

        Schema::create('gate_passes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('number', 40)->unique();
            $table->string('supplier_gate_pass_number', 100)->nullable()->index();
            $table->unsignedBigInteger('purchase_order_id');
            $table->integer('provider_id')->index();
            $table->integer('warehouse_id')->index();
            $table->dateTime('delivered_at')->index();
            $table->string('bilty_number', 100)->nullable()->index();
            $table->string('vehicle_number', 80)->nullable()->index();
            $table->string('driver_name', 150)->nullable();
            $table->string('driver_phone', 80)->nullable();
            $table->integer('received_by')->index();
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->integer('confirmed_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('cancelled_by')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps(6);
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('gate_pass_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('gate_pass_id');
            $table->unsignedBigInteger('purchase_order_item_id');
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable()->index();
            $table->decimal('delivered_quantity', 20, 6);
            $table->decimal('accepted_quantity', 20, 6)->default(0);
            $table->decimal('rejected_quantity', 20, 6)->default(0);
            $table->decimal('short_quantity', 20, 6)->default(0);
            $table->boolean('over_delivery_approved')->default(false);
            $table->integer('over_delivery_approved_by')->nullable();
            $table->text('over_delivery_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unique(['gate_pass_id', 'purchase_order_item_id'], 'gate_pass_po_item_unique');
            $table->foreign('gate_pass_id')->references('id')->on('gate_passes')->cascadeOnDelete();
            $table->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('over_delivery_approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('number', 40)->unique();
            $table->string('supplier_invoice_number', 120);
            $table->integer('provider_id')->index();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('gate_pass_id')->nullable();
            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->string('tax_type', 20)->default('non_gst')->index();
            $table->string('supplier_strn_number', 80)->nullable();
            $table->string('supplier_ntn_number', 80)->nullable();
            $table->boolean('tax_type_overridden')->default(false);
            $table->integer('tax_type_overridden_by')->nullable();
            $table->timestamp('tax_type_overridden_at')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('discount_total', 20, 6)->default(0);
            $table->decimal('tax_total', 20, 6)->default(0);
            $table->decimal('other_charges', 20, 6)->default(0);
            $table->decimal('freight_charges', 20, 6)->default(0);
            $table->decimal('grand_total', 20, 6)->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->integer('created_by')->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps(6);
            $table->unique(['provider_id', 'supplier_invoice_number'], 'supplier_invoice_provider_unique');
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $table->foreign('gate_pass_id')->references('id')->on('gate_passes')->restrictOnDelete();
            $table->foreign('tax_type_overridden_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('supplier_invoice_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_invoice_id');
            $table->unsignedBigInteger('gate_pass_item_id');
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable()->index();
            $table->string('product_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->string('sku', 192)->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6);
            $table->decimal('discount', 20, 6)->default(0);
            $table->string('discount_method', 20)->default('fixed');
            $table->unsignedInteger('tax_id')->nullable()->index();
            $table->string('tax_name', 120)->nullable();
            $table->decimal('tax_rate', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->json('tax_snapshot')->nullable();
            $table->decimal('line_subtotal', 20, 6);
            $table->decimal('line_total', 20, 6);
            $table->timestamps(6);
            $table->unique(['supplier_invoice_id', 'gate_pass_item_id'], 'supplier_invoice_gp_item_unique');
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices')->cascadeOnDelete();
            $table->foreign('gate_pass_item_id')->references('id')->on('gate_pass_items')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
        });

        Schema::create('procurement_stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('gate_pass_id');
            $table->unsignedBigInteger('gate_pass_item_id')->unique();
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable()->index();
            $table->integer('warehouse_id')->index();
            $table->decimal('quantity', 20, 6);
            $table->string('reference', 80);
            $table->integer('performed_by');
            $table->json('metadata')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->integer('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps(6);
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $table->foreign('gate_pass_id')->references('id')->on('gate_passes')->restrictOnDelete();
            $table->foreign('gate_pass_item_id')->references('id')->on('gate_pass_items')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('performed_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('procurement_audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('auditable_type', 80);
            $table->unsignedBigInteger('auditable_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('action', 80);
            $table->string('reference', 120)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['purchase_order_id', 'created_at']);
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->after('delivery_note_no');
            $table->unsignedBigInteger('gate_pass_id')->nullable()->after('purchase_order_id');
            $table->unsignedBigInteger('supplier_invoice_id')->nullable()->unique()->after('gate_pass_id');
            $table->string('invoice_tax_type', 20)->nullable()->after('supplier_invoice_id');
            $table->boolean('inventory_already_received')->default(false)->after('invoice_tax_type');
            $table->string('posting_status', 20)->default('posted')->after('inventory_already_received');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $table->foreign('gate_pass_id')->references('id')->on('gate_passes')->restrictOnDelete();
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices')->restrictOnDelete();
        });

        $permissions = [
            'purchase_orders_view' => 'View Purchase Orders', 'purchase_orders_create' => 'Create Purchase Orders',
            'purchase_orders_edit_draft' => 'Edit Draft Purchase Orders', 'purchase_orders_issue' => 'Issue Purchase Orders',
            'purchase_orders_cancel' => 'Cancel Purchase Orders', 'purchase_orders_pdf' => 'Download Purchase Order PDF',
            'gate_passes_view' => 'View Gate Passes', 'gate_passes_create' => 'Create Gate Passes',
            'gate_passes_confirm' => 'Confirm Gate Passes', 'gate_passes_reject' => 'Reject Gate Passes',
            'gate_passes_upload' => 'Upload Gate Pass Attachments', 'supplier_invoices_view' => 'View Supplier Invoices',
            'supplier_invoices_create' => 'Create Supplier Invoices', 'supplier_invoices_edit_draft' => 'Edit Draft Supplier Invoices',
            'supplier_invoices_post' => 'Post Supplier Invoices', 'purchases_from_supplier_invoice' => 'Create Purchase from Supplier Invoice',
            'purchases_post' => 'Post Purchases', 'supplier_tax_override' => 'Override Supplier GST Default',
            'procurement_over_delivery_approve' => 'Approve Purchase Over-delivery', 'procurement_reports_view' => 'View Procurement Reports',
        ];
        foreach ($permissions as $name => $label) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $label, 'description' => $label]);
        }
        $rolePermissions = [
            'Super Admin' => array_keys($permissions),
            'Admin' => array_keys($permissions),
            'Accountant' => ['purchase_orders_view', 'gate_passes_view', 'supplier_invoices_view', 'supplier_invoices_create', 'supplier_invoices_edit_draft', 'supplier_invoices_post', 'purchases_from_supplier_invoice', 'purchases_post', 'supplier_tax_override', 'procurement_reports_view'],
            'Branch Manager' => ['purchase_orders_view', 'gate_passes_view', 'gate_passes_create', 'gate_passes_confirm', 'gate_passes_reject', 'gate_passes_upload', 'supplier_invoices_view', 'procurement_reports_view'],
        ];
        foreach ($rolePermissions as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($names as $name) {
                $permissionId = DB::table('permissions')->where('name', $name)->value('id');
                DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropForeign(['gate_pass_id']);
            $table->dropForeign(['supplier_invoice_id']);
            $table->dropColumn(['purchase_order_id', 'gate_pass_id', 'supplier_invoice_id', 'invoice_tax_type', 'inventory_already_received', 'posting_status']);
        });
        Schema::dropIfExists('procurement_audits');
        Schema::dropIfExists('procurement_stock_movements');
        Schema::dropIfExists('supplier_invoice_items');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('gate_pass_items');
        Schema::dropIfExists('gate_passes');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn(['tax_status', 'strn_number', 'ntn_number']));
    }

    /**
     * MySQL commits DDL statements individually. If the original migration is
     * interrupted while creating purchase_order_items, the provider columns and
     * the first two empty tables remain even though the migration stays pending.
     */
    private function recoverInterruptedFirstRun(): void
    {
        $hasKnownPartialTables = Schema::hasTable('purchase_orders')
            && Schema::hasTable('purchase_order_items')
            && ! Schema::hasTable('gate_passes');

        if (! $hasKnownPartialTables) {
            return;
        }

        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');

        $providerColumns = collect(['tax_status', 'strn_number', 'ntn_number'])
            ->filter(fn (string $column): bool => Schema::hasColumn('providers', $column))
            ->values()
            ->all();

        if ($providerColumns !== []) {
            Schema::table('providers', fn (Blueprint $table) => $table->dropColumn($providerColumns));
        }
    }
};
