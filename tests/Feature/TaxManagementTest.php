<?php

namespace Tests\Feature;

use App\Models\Tax;
use App\Models\TaxPriceType;
use App\Models\TransactionTaxSnapshot;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TaxManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        Passport::actingAs(User::create([
            'username' => 'admin', 'email' => 'admin@example.test', 'password' => bcrypt('secret'),
            'role_id' => 1, 'statut' => 1, 'is_all_warehouses' => 1,
        ]));
        TaxPriceType::create(['code' => 'price', 'name' => 'Sale Price', 'product_field' => 'price', 'is_sale' => true, 'sort_order' => 1, 'is_active' => true]);
    }

    public function test_authorized_user_can_create_list_update_and_toggle_tax(): void
    {
        $payload = $this->payload();
        $create = $this->postJson('/api/taxes', $payload)->assertCreated();
        $id = $create->json('tax.id');
        $this->assertDatabaseHas('taxes', ['id' => $id, 'code' => 'GST', 'scope_key' => 'global']);
        $this->assertDatabaseHas('tax_transaction_types', ['tax_id' => $id, 'transaction_type' => 'pos']);
        $this->assertDatabaseCount('tax_audits', 1);

        $this->getJson('/api/taxes?transaction_type=pos&status=active')->assertOk()->assertJsonPath('totalRows', 1);
        $this->putJson('/api/taxes/'.$id, array_merge($payload, ['rate' => '19.5']))->assertOk()->assertJsonPath('tax.rate', '19.500000');
        $this->patchJson('/api/taxes/'.$id.'/toggle')->assertOk()->assertJsonPath('tax.is_active', false);
    }

    public function test_validation_enforces_scoped_unique_code_dates_and_required_applicability(): void
    {
        $this->postJson('/api/taxes', $this->payload())->assertCreated();
        $this->postJson('/api/taxes', $this->payload())->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/taxes', array_merge($this->payload(), [
            'code' => 'BAD', 'rate' => -1, 'transaction_types' => [], 'price_type_ids' => [],
            'effective_start_date' => '2026-09-10', 'effective_end_date' => '2026-09-01',
        ]))->assertStatus(422)->assertJsonValidationErrors(['rate', 'transaction_types', 'price_type_ids', 'effective_end_date']);
    }

    public function test_used_tax_cannot_be_hard_deleted_but_unused_tax_can(): void
    {
        $id = $this->postJson('/api/taxes', $this->payload())->assertCreated()->json('tax.id');
        TransactionTaxSnapshot::create([
            'transaction_type' => 'pos', 'transaction_id' => 10, 'transaction_line_id' => 20,
            'tax_id' => $id, 'tax_name' => 'GST', 'tax_code' => 'GST', 'calculation_type' => 'percentage',
            'rate' => 18, 'behavior' => 'additive', 'price_type_id' => 1, 'price_type_code' => 'price',
            'price_type_name' => 'Sale Price', 'quantity' => 1, 'taxable_base' => 100, 'tax_amount' => 18,
            'priority' => 10, 'is_compound' => false, 'is_reversal' => false,
        ]);
        $this->deleteJson('/api/taxes/'.$id)->assertStatus(422);
        $this->assertNull(Tax::find($id)->deleted_at);

        $unused = $this->postJson('/api/taxes', array_merge($this->payload(), ['code' => 'LEVY']))->assertCreated()->json('tax.id');
        $this->deleteJson('/api/taxes/'.$unused)->assertOk();
        $this->assertSoftDeleted('taxes', ['id' => $unused]);
    }

    public function test_sale_preview_identifies_gst_price_base_rate_amount_and_total(): void
    {
        $this->postJson('/api/taxes', $this->payload())->assertCreated();
        \DB::table('warehouses')->insert(['id' => 1, 'name' => 'Main']);

        $this->postJson('/api/taxes/preview', [
            'transaction_type' => 'sale_invoice',
            'warehouse_id' => 1,
            'date' => '2026-08-24',
            'discount' => 10,
            'discount_method' => '1',
            'shipping' => 5,
            'lines' => [[
                'line_key' => 1,
                'price_type' => 'price',
                'unit_price' => 100,
                'quantity' => 2,
                'discount' => 10,
                'discount_method' => '2',
            ]],
        ])->assertOk()
            ->assertJsonPath('summary.0.tax_code', 'GST')
            ->assertJsonPath('summary.0.price_type_name', 'Sale Price')
            ->assertJsonPath('summary.0.taxable_base', '180.000000')
            ->assertJsonPath('summary.0.tax_amount', '32.400000')
            ->assertJsonPath('line_totals.1', '212.40')
            ->assertJsonPath('totals.grand_total', '199.40');
    }

    public function test_authorized_sale_preview_can_mark_a_line_as_not_gst(): void
    {
        $this->postJson('/api/taxes', $this->payload())->assertCreated();
        \DB::table('warehouses')->insert(['id' => 1, 'name' => 'Main']);

        $this->postJson('/api/taxes/preview', [
            'transaction_type' => 'sale_invoice',
            'warehouse_id' => 1,
            'date' => '2026-08-24',
            'lines' => [[
                'line_key' => 1,
                'price_type' => 'price',
                'unit_price' => 100,
                'quantity' => 2,
                'discount' => 10,
                'discount_method' => '2',
                'excluded_tax_codes' => ['GST'],
            ]],
        ])->assertOk()
            ->assertJsonPath('managed_available', true)
            ->assertJsonCount(0, 'summary')
            ->assertJsonPath('available_taxes.1.0.tax_code', 'GST')
            ->assertJsonPath('line_totals.1', '180.00')
            ->assertJsonPath('totals.grand_total', '180.00');
    }

    public function test_sale_creator_can_preview_automatic_taxes_without_separate_tax_apply_permission(): void
    {
        $this->postJson('/api/taxes', $this->payload())->assertCreated();
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'Main']);

        $roleId = DB::table('roles')->insertGetId(['name' => 'Sales User']);
        $salesAddId = DB::table('permissions')->insertGetId(['name' => 'Sales_add']);
        DB::table('permission_role')->insert([
            'permission_id' => $salesAddId,
            'role_id' => $roleId,
        ]);

        $salesUser = User::create([
            'username' => 'sales-user',
            'email' => 'sales-user@example.test',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
            'statut' => 1,
            'is_all_warehouses' => 1,
        ]);
        DB::table('role_user')->insert([
            'role_id' => $roleId,
            'user_id' => $salesUser->id,
        ]);
        Passport::actingAs($salesUser);

        $this->postJson('/api/taxes/preview', [
            'transaction_type' => 'sale_invoice',
            'warehouse_id' => 1,
            'date' => '2026-08-24',
            'lines' => [[
                'line_key' => 1,
                'price_type' => 'price',
                'unit_price' => 100,
                'quantity' => 1,
                'discount' => 0,
                'discount_method' => '2',
            ]],
        ])->assertOk()
            ->assertJsonPath('summary.0.tax_code', 'GST')
            ->assertJsonPath('totals.grand_total', '118.00');
    }

    private function payload(): array
    {
        return [
            'name' => 'General Sales Tax', 'code' => 'gst', 'description' => 'Managed GST',
            'calculation_type' => 'percentage', 'rate' => '18', 'behavior' => 'additive',
            'transaction_types' => ['sale_invoice', 'pos'], 'price_type_ids' => [1], 'warehouse_ids' => [],
            'effective_start_date' => null, 'effective_end_date' => null, 'priority' => 10,
            'is_compound' => false, 'is_active' => true,
        ];
    }

    private function schema(): void
    {
        Schema::create('users', function (Blueprint $t) { $t->increments('id'); $t->string('username'); $t->string('email'); $t->string('password'); $t->unsignedInteger('role_id')->default(1); $t->boolean('statut')->default(true); $t->boolean('is_all_warehouses')->default(true); $t->boolean('record_view')->nullable(); $t->timestamps(); });
        Schema::create('roles', function (Blueprint $t) { $t->increments('id'); $t->string('name'); $t->timestamps(); });
        Schema::create('role_user', function (Blueprint $t) { $t->unsignedInteger('role_id'); $t->unsignedInteger('user_id'); });
        Schema::create('permissions', function (Blueprint $t) { $t->increments('id'); $t->string('name'); $t->timestamps(); });
        Schema::create('permission_role', function (Blueprint $t) { $t->unsignedInteger('permission_id'); $t->unsignedInteger('role_id'); });
        Schema::create('permission_user', function (Blueprint $t) { $t->unsignedInteger('permission_id'); $t->unsignedInteger('user_id'); $t->string('type'); $t->timestamps(); });
        Schema::create('settings', function (Blueprint $t) { $t->increments('id'); $t->boolean('allowed_ips_enabled')->default(false); $t->text('allowed_ips')->nullable(); $t->text('allowed_ip_role_ids')->nullable(); $t->softDeletes(); $t->timestamps(); });
        Schema::create('warehouses', function (Blueprint $t) { $t->increments('id'); $t->string('name'); $t->softDeletes(); $t->timestamps(); });
        Schema::create('user_warehouse', function (Blueprint $t) { $t->unsignedInteger('user_id'); $t->unsignedInteger('warehouse_id'); });
        Schema::create('tax_price_types', function (Blueprint $t) { $t->increments('id'); $t->string('code')->unique(); $t->string('name'); $t->string('product_field'); $t->boolean('is_purchase')->default(false); $t->boolean('is_sale')->default(false); $t->unsignedSmallInteger('sort_order')->default(0); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('taxes', function (Blueprint $t) { $t->increments('id'); $t->string('scope_key')->default('global'); $t->unsignedInteger('company_id')->nullable(); $t->string('name'); $t->string('code'); $t->text('description')->nullable(); $t->string('calculation_type'); $t->decimal('rate', 20, 6); $t->string('behavior'); $t->date('effective_start_date')->nullable(); $t->date('effective_end_date')->nullable(); $t->unsignedSmallInteger('priority'); $t->boolean('is_compound'); $t->boolean('is_active'); $t->unsignedInteger('created_by')->nullable(); $t->unsignedInteger('updated_by')->nullable(); $t->timestamps(); $t->softDeletes(); $t->unique(['scope_key', 'code']); });
        Schema::create('tax_transaction_types', function (Blueprint $t) { $t->unsignedInteger('tax_id'); $t->string('transaction_type'); $t->primary(['tax_id', 'transaction_type']); });
        Schema::create('tax_price_type', function (Blueprint $t) { $t->unsignedInteger('tax_id'); $t->unsignedInteger('tax_price_type_id'); $t->primary(['tax_id', 'tax_price_type_id']); });
        Schema::create('tax_warehouse', function (Blueprint $t) { $t->unsignedInteger('tax_id'); $t->unsignedInteger('warehouse_id'); $t->primary(['tax_id', 'warehouse_id']); });
        Schema::create('tax_defaults', function (Blueprint $t) { $t->increments('id'); $t->string('scope_key'); $t->unsignedInteger('company_id')->nullable(); $t->unsignedInteger('warehouse_id')->nullable(); $t->string('transaction_type'); $t->unsignedInteger('tax_id'); $t->unsignedInteger('updated_by')->nullable(); $t->timestamps(); });
        Schema::create('tax_audits', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedInteger('tax_id')->nullable(); $t->unsignedInteger('user_id')->nullable(); $t->string('event'); $t->string('auditable_type')->nullable(); $t->unsignedBigInteger('auditable_id')->nullable(); $t->json('before')->nullable(); $t->json('after')->nullable(); $t->string('ip_address')->nullable(); $t->timestamps(); });
        Schema::create('transaction_tax_snapshots', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('transaction_type'); $t->unsignedInteger('transaction_id'); $t->unsignedInteger('transaction_line_id')->nullable(); $t->unsignedInteger('tax_id')->nullable(); $t->string('tax_name'); $t->string('tax_code'); $t->string('calculation_type'); $t->decimal('rate', 20, 6); $t->string('behavior'); $t->unsignedInteger('price_type_id')->nullable(); $t->string('price_type_code'); $t->string('price_type_name'); $t->decimal('quantity', 20, 6); $t->decimal('taxable_base', 20, 6); $t->decimal('tax_amount', 20, 6); $t->unsignedSmallInteger('priority'); $t->boolean('is_compound'); $t->boolean('is_reversal'); $t->unsignedBigInteger('reversal_of_id')->nullable(); $t->timestamps(); });
    }
}
