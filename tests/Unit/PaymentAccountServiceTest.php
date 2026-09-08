<?php

namespace Tests\Unit;

use App\Models\PaymentMethod;
use App\Services\PaymentAccountService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentAccountServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('account_name');
            $table->string('account_num');
            $table->string('account_type');
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        DB::table('accounts')->insert([
            ['id' => 1, 'account_name' => 'Bank Alfalah', 'account_num' => 'PK001', 'account_type' => 'bank', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'account_name' => 'Shop Easypaisa', 'account_num' => '03001234567', 'account_type' => 'easypaisa', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('payment_methods')->insert([
            ['id' => 2, 'name' => 'Cash', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'name' => 'bank transfer', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 8, 'name' => 'EasyPaisa', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('accounts');
        parent::tearDown();
    }

    public function test_it_resolves_bank_and_easypaisa_accounts_by_type(): void
    {
        $service = app(PaymentAccountService::class);

        $this->assertSame(1, $service->resolve(PaymentMethod::findOrFail(6), 1)->id);
        $this->assertSame(2, $service->resolve(PaymentMethod::findOrFail(8), 2)->id);
        $this->assertNull($service->resolve(PaymentMethod::findOrFail(2), null));
    }

    public function test_it_rejects_an_account_of_the_wrong_type(): void
    {
        $this->expectException(ValidationException::class);

        app(PaymentAccountService::class)->resolve(PaymentMethod::findOrFail(8), 1);
    }
}
