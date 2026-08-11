<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\PaymentPurchasePolicy;
use App\Policies\PaymentPurchaseReturnsPolicy;
use App\Policies\PaymentSalePolicy;
use App\Policies\PaymentSaleReturnsPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentListPermissionPolicyTest extends TestCase
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

    public function test_show_and_create_payment_permissions_are_enforced_per_list(): void
    {
        $permissions = collect([
            'payment_sales_view',
            'payment_sales_add',
            'payment_purchases_view',
            'payment_purchases_add',
            'payment_sale_returns_view',
            'payment_sale_returns_add',
            'payment_returns_view',
            'payment_returns_add',
        ])->mapWithKeys(function ($name) {
            return [$name => Permission::create(['name' => $name])];
        });

        $salesViewer = $this->userWithPermissions('Sales Viewer', [
            $permissions['payment_sales_view']->id,
        ]);
        $purchaseCreator = $this->userWithPermissions('Purchase Payment Creator', [
            $permissions['payment_purchases_add']->id,
        ]);
        $saleReturnManager = $this->userWithPermissions('Sale Return Payment Manager', [
            $permissions['payment_sale_returns_view']->id,
            $permissions['payment_sale_returns_add']->id,
        ]);

        $this->assertTrue((new PaymentSalePolicy)->view($salesViewer));
        $this->assertFalse((new PaymentSalePolicy)->create($salesViewer));
        $this->assertFalse((new PaymentPurchasePolicy)->view($purchaseCreator));
        $this->assertTrue((new PaymentPurchasePolicy)->create($purchaseCreator));
        $this->assertTrue((new PaymentSaleReturnsPolicy)->view($saleReturnManager));
        $this->assertTrue((new PaymentSaleReturnsPolicy)->create($saleReturnManager));
        $this->assertFalse((new PaymentPurchaseReturnsPolicy)->view($saleReturnManager));
        $this->assertFalse((new PaymentPurchaseReturnsPolicy)->create($saleReturnManager));
    }

    private function userWithPermissions(string $roleName, array $permissionIds): User
    {
        $role = Role::create(['name' => $roleName]);
        $role->permissions()->attach($permissionIds);

        $user = User::create(['username' => str_replace(' ', '-', strtolower($roleName))]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
