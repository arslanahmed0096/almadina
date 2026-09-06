<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceAllowedIps;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductStockCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnforceAllowedIps::class);
        $this->createSchema();
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

    public function test_stock_check_is_permission_protected_and_returns_each_branch(): void
    {
        $viewPermission = Permission::create(['name' => 'products_view']);
        $stockPermission = Permission::create(['name' => 'product_stock_check']);

        $viewerRole = Role::create(['name' => 'Product Viewer']);
        $viewerRole->permissions()->attach($viewPermission->id);
        $viewer = User::create([
            'username' => 'viewer',
            'statut' => 1,
            'is_all_warehouses' => 1,
        ]);
        $viewer->roles()->attach($viewerRole->id);

        $stockRole = Role::create(['name' => 'Stock Checker']);
        $stockRole->permissions()->attach($stockPermission->id);
        $stockChecker = User::create([
            'username' => 'stock-checker',
            'statut' => 1,
            'is_all_warehouses' => 1,
        ]);
        $stockChecker->roles()->attach($stockRole->id);

        DB::table('units')->insert(['id' => 1, 'ShortName' => 'Pc']);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'Branch A'],
            ['id' => 2, 'name' => 'Branch B'],
        ]);
        DB::table('products')->insert([
            'id' => 1,
            'name' => 'Test Product',
            'code' => 'TEST-1',
            'type' => 'is_single',
            'unit_id' => 1,
            'is_active' => 1,
        ]);
        DB::table('product_warehouse')->insert([
            ['product_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => null, 'qte' => 12.5],
            ['product_id' => 1, 'warehouse_id' => 2, 'product_variant_id' => null, 'qte' => 7],
        ]);

        Passport::actingAs($viewer);
        $this->getJson('/api/products/1/stock-check')->assertForbidden();

        Passport::actingAs($stockChecker);
        $this->getJson('/api/products/1/stock-check')
            ->assertOk()
            ->assertJsonPath('product.name', 'Test Product')
            ->assertJsonPath('product.unit', 'Pc')
            ->assertJsonPath('warehouse_stock.0.warehouse', 'Branch A')
            ->assertJsonPath('warehouse_stock.0.quantity', 12.5)
            ->assertJsonPath('warehouse_stock.1.warehouse', 'Branch B')
            ->assertJsonPath('warehouse_stock.1.quantity', 7);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->unsignedInteger('role_id')->nullable();
            $table->boolean('statut')->default(true);
            $table->boolean('is_all_warehouses')->default(false);
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
        Schema::create('units', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ShortName');
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code');
            $table->string('type');
            $table->unsignedInteger('unit_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_variants', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->string('name');
            $table->string('code');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_warehouse', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('warehouse_id');
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->decimal('qte', 15, 2)->default(0);
            $table->softDeletes();
        });
    }
}
