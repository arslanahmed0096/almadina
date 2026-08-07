<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\ClientPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientCreditLimitPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->string('password')->nullable();
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
            $table->integer('role_id');
            $table->integer('user_id');
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('permission_id');
            $table->integer('role_id');
        });
        Schema::create('permission_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('permission_id');
            $table->integer('user_id');
            $table->string('type')->default('allow');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_credit_limit_requires_the_dedicated_permission(): void
    {
        $generalEdit = Permission::create(['name' => 'Customers_edit']);
        $creditUpdate = Permission::create(['name' => 'customer_credit_limit_update']);

        $salesmanRole = Role::create(['name' => 'Salesman']);
        $salesmanRole->permissions()->attach($generalEdit->id);
        $salesman = User::create(['username' => 'salesman']);
        $salesman->roles()->attach($salesmanRole->id);

        $authorizedRole = Role::create(['name' => 'Credit Manager']);
        $authorizedRole->permissions()->attach($creditUpdate->id);
        $authorizedUser = User::create(['username' => 'credit-manager']);
        $authorizedUser->roles()->attach($authorizedRole->id);

        $deniedUser = User::create(['username' => 'denied-credit-manager']);
        $deniedUser->roles()->attach($authorizedRole->id);
        $deniedUser->permissionOverrides()->attach($creditUpdate->id, ['type' => 'deny']);

        $policy = new ClientPolicy;

        $this->assertFalse($policy->updateCreditLimit($salesman));
        $this->assertTrue($policy->updateCreditLimit($authorizedUser));
        $this->assertFalse($policy->updateCreditLimit($deniedUser));
    }
}
