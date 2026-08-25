<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaxModuleMigrationTest extends TestCase
{
    public function test_tax_schema_and_default_records_migrate_on_a_clean_database(): void
    {
        Schema::create('settings', function (Blueprint $t) { $t->increments('id'); $t->decimal('default_tax', 20, 6)->default(0); });
        Schema::create('warehouses', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        Schema::create('roles', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        Schema::create('permissions', function (Blueprint $t) { $t->increments('id'); $t->string('name')->unique(); $t->string('label')->nullable(); $t->text('description')->nullable(); $t->timestamps(); $t->softDeletes(); });
        Schema::create('permission_role', function (Blueprint $t) { $t->unsignedInteger('permission_id'); $t->unsignedInteger('role_id'); $t->unique(['permission_id', 'role_id']); });
        Schema::create('permission_user', function (Blueprint $t) { $t->unsignedInteger('permission_id'); $t->unsignedInteger('user_id'); $t->string('type'); $t->timestamps(); });
        DB::table('settings')->insert(['default_tax' => '17.5']);
        DB::table('roles')->insert([['id' => 1, 'name' => 'Super Admin'], ['id' => 2, 'name' => 'Accountant'], ['id' => 3, 'name' => 'POS User']]);

        $schemaMigration = require database_path('migrations/2026_08_24_000001_create_tax_management_module.php');
        $schemaMigration->up();
        $permissionMigration = require database_path('migrations/2026_08_24_000002_add_tax_management_permissions.php');
        $permissionMigration->up();

        foreach (['taxes', 'tax_price_types', 'tax_transaction_types', 'tax_price_type', 'tax_warehouse', 'tax_defaults', 'transaction_tax_snapshots', 'tax_audits'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }
        $this->assertDatabaseHas('taxes', ['code' => 'GST', 'rate' => 17.5, 'behavior' => 'additive']);
        $this->assertDatabaseHas('taxes', ['code' => 'WHT', 'rate' => 0.5, 'behavior' => 'deductive']);
        $this->assertSame(7, DB::table('tax_price_types')->count());
        $this->assertSame(3, DB::table('tax_defaults')->count());
        $this->assertSame(8, DB::table('permissions')->where('name', 'like', 'taxes.%')->count());
        $applyId = DB::table('permissions')->where('name', 'taxes.apply')->value('id');
        $this->assertDatabaseHas('permission_role', ['permission_id' => $applyId, 'role_id' => 3]);
    }
}
