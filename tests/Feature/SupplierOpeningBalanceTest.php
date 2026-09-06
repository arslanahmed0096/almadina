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
        Schema::create('purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->date('date');
            $table->string('Ref');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('warehouse_id');
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('statut');
            $table->string('payment_statut');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
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
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('purchases');
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
}
