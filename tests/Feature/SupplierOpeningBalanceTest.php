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
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
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
}
