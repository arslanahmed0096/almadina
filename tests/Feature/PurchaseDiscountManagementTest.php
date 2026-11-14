<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchaseDiscountController;
use App\Http\Requests\SavePurchaseDiscountRequest;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseDiscountUsage;
use App\Models\User;
use Database\Seeders\PurchaseDiscountSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseDiscountManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('purchase_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 32);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('purchase_discount_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_discount_id');
            $table->unsignedInteger('purchase_id');
            $table->unsignedInteger('purchase_detail_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_default_discounts_are_idempotent_and_use_only_fixed_categories(): void
    {
        $seeder = new PurchaseDiscountSeeder;
        $seeder->run();
        $seeder->run();

        $this->assertSame(4, PurchaseDiscount::count());
        $this->assertEqualsCanonicalizing(
            PurchaseDiscount::CATEGORIES,
            PurchaseDiscount::query()->distinct()->pluck('category')->all()
        );
        $this->assertDatabaseHas('purchase_discounts', ['name' => 'Trade Discount', 'category' => 'on_invoice']);
        $this->assertDatabaseHas('purchase_discounts', ['name' => 'FSM Discount', 'category' => 'further_discount']);
    }

    public function test_create_validation_rejects_duplicate_name_within_same_category_but_allows_other_category(): void
    {
        PurchaseDiscount::create([
            'name' => 'Quantity Discount',
            'category' => 'further_discount',
            'status' => 'active',
        ]);

        $this->expectValidationError([
            'name' => 'Quantity Discount',
            'category' => 'further_discount',
            'description' => null,
            'status' => 'active',
        ], 'name');

        $request = $this->validatedRequest([
            'name' => 'Quantity Discount',
            'category' => 'on_invoice',
            'description' => null,
            'status' => 'active',
        ]);
        $response = (new PurchaseDiscountController)->store($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('purchase_discounts', [
            'name' => 'Quantity Discount',
            'category' => 'on_invoice',
        ]);
    }

    public function test_listing_supports_name_category_and_status_filters(): void
    {
        PurchaseDiscount::insert([
            ['name' => 'Trade Discount', 'category' => 'on_invoice', 'description' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Early Payment Discount', 'category' => 'on_invoice', 'description' => null, 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Quantity Discount', 'category' => 'further_discount', 'description' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $request = $this->request('GET', [
            'search' => 'Payment',
            'category' => 'on_invoice',
            'status' => 'inactive',
            'limit' => 10,
            'SortField' => 'name',
            'SortType' => 'asc',
        ]);
        $payload = (new PurchaseDiscountController)->index($request)->getData(true);

        $this->assertSame(1, $payload['totalRows']);
        $this->assertSame('Early Payment Discount', $payload['discounts'][0]['name']);
        $this->assertSame('On Invoice Discount', $payload['discounts'][0]['category_label']);
    }

    public function test_used_discount_cannot_be_deleted_but_unused_discount_is_soft_deleted(): void
    {
        $used = PurchaseDiscount::create(['name' => 'Used', 'category' => 'on_invoice', 'status' => 'active']);
        $unused = PurchaseDiscount::create(['name' => 'Unused', 'category' => 'further_discount', 'status' => 'active']);
        PurchaseDiscountUsage::create([
            'purchase_discount_id' => $used->id,
            'purchase_id' => 10,
            'purchase_detail_id' => 20,
        ]);

        $controller = new PurchaseDiscountController;
        $blocked = $controller->destroy($this->request('DELETE'), $used);
        $deleted = $controller->destroy($this->request('DELETE'), $unused);

        $this->assertSame(422, $blocked->getStatusCode());
        $this->assertStringContainsString('cannot be deleted', $blocked->getData(true)['message']);
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertSoftDeleted('purchase_discounts', ['id' => $unused->id]);
        $this->assertDatabaseHas('purchase_discounts', ['id' => $used->id, 'deleted_at' => null]);
    }

    private function validatedRequest(array $input): SavePurchaseDiscountRequest
    {
        $request = SavePurchaseDiscountRequest::create('/purchase-discounts', 'POST', $input);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(fn () => $this->superAdmin());
        $request->validateResolved();

        return $request;
    }

    private function expectValidationError(array $input, string $field): void
    {
        try {
            $this->validatedRequest($input);
            $this->fail("Expected validation to fail for {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function request(string $method, array $input = []): Request
    {
        $request = Request::create('/purchase-discounts', $method, $input);
        $request->setUserResolver(fn () => $this->superAdmin());

        return $request;
    }

    private function superAdmin(): User
    {
        $user = new User(['role_id' => 1, 'statut' => 1]);
        $user->id = 1;
        $user->exists = true;

        return $user;
    }
}
