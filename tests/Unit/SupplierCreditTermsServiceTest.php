<?php

namespace Tests\Unit;

use App\Models\Purchase;
use App\Services\SupplierCreditTermsService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierCreditTermsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('providers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('credit_terms_mode')->default('invoice');
            $table->unsignedTinyInteger('credit_days')->default(15);
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('category_provider', function (Blueprint $table) {
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('category_id');
            $table->unsignedTinyInteger('credit_days')->nullable();
            $table->timestamps();
        });
        Schema::create('purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('provider_id');
            $table->date('date');
            $table->decimal('GrandTotal', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->unsignedTinyInteger('payment_due_days')->nullable();
            $table->date('payment_due_date')->nullable();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('category_id')->nullable();
        });
        Schema::create('purchase_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('purchase_id');
            $table->unsignedInteger('product_id');
            $table->decimal('total', 15, 2);
        });
        Schema::create('purchase_category_credit_terms', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('purchase_id');
            $table->unsignedInteger('category_id')->nullable();
            $table->string('category_name');
            $table->unsignedTinyInteger('credit_days');
            $table->decimal('amount', 15, 2);
            $table->date('due_date');
            $table->timestamps();
        });

        DB::table('providers')->insert([
            'id' => 1, 'name' => 'Supplier', 'credit_terms_mode' => 'category', 'credit_days' => 15,
        ]);
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Short term', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Long term', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('category_provider')->insert([
            ['provider_id' => 1, 'category_id' => 1, 'credit_days' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['provider_id' => 1, 'category_id' => 2, 'credit_days' => 20, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('purchases')->insert([
            'id' => 1, 'provider_id' => 1, 'date' => '2026-01-01', 'GrandTotal' => 300, 'paid_amount' => 150,
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'category_id' => 1],
            ['id' => 2, 'category_id' => 2],
        ]);
        DB::table('purchase_details')->insert([
            ['purchase_id' => 1, 'product_id' => 1, 'total' => 100],
            ['purchase_id' => 1, 'product_id' => 2, 'total' => 200],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'purchase_category_credit_terms', 'purchase_details', 'products', 'purchases',
            'category_provider', 'categories', 'providers',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_it_snapshots_category_terms_and_applies_payments_to_the_earliest_due_category_first(): void
    {
        $purchase = Purchase::findOrFail(1);
        $service = app(SupplierCreditTermsService::class);
        $service->syncPurchase($purchase);

        $terms = $service->outstanding($purchase, Carbon::parse('2026-01-15'));

        $this->assertSame(['Long term'], $terms->pluck('category_name')->all());
        $this->assertSame(['2026-01-21'], $terms->pluck('due_date')->all());
        $this->assertSame([150.0], $terms->pluck('outstanding')->all());
        $this->assertSame(10, DB::table('purchase_category_credit_terms')->where('category_id', 1)->value('credit_days'));
        $this->assertSame(20, DB::table('purchase_category_credit_terms')->where('category_id', 2)->value('credit_days'));
    }
}
