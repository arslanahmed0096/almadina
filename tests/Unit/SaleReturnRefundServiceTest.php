<?php

namespace Tests\Unit;

use App\Models\SaleReturn;
use App\Services\SaleReturnRefundService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SaleReturnRefundServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('account_name');
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
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->date('date');
            $table->string('Ref');
            $table->decimal('GrandTotal', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_sale_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('sale_return_id');
            $table->unsignedInteger('account_id')->nullable();
            $table->unsignedInteger('payment_method_id');
            $table->unsignedInteger('user_id');
            $table->date('date');
            $table->string('Ref');
            $table->decimal('montant', 15, 2);
            $table->decimal('change', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('payment_sale_returns');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('accounts');

        parent::tearDown();
    }

    public function test_it_records_cash_bank_and_easypaisa_refunds_and_updates_accounts(): void
    {
        $now = now();
        DB::table('payment_methods')->insert([
            ['id' => 1, 'name' => 'Cash', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'bank transfer', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'EasyPaisa', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('accounts')->insert([
            ['id' => 1, 'account_name' => 'Cash Counter', 'balance' => 1000, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'account_name' => 'Bank', 'balance' => 2000, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'account_name' => 'EasyPaisa Wallet', 'balance' => 500, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('sale_returns')->insert([
            'id' => 1,
            'date' => '2026-09-01',
            'Ref' => 'RT_0001',
            'GrandTotal' => 175,
            'paid_amount' => 175,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $refunds = [
            'refund_cash_amount' => 100,
            'refund_bank_amount' => 50,
            'refund_easypaisa_amount' => 25,
            'refund_cash_account_id' => 1,
            'refund_bank_account_id' => 2,
            'refund_easypaisa_account_id' => 3,
        ];
        $service = app(SaleReturnRefundService::class);

        $this->assertSame(175.0, $service->total($refunds));
        $this->assertSame('paid', $service->paymentStatus(175, 175));
        $service->record(SaleReturn::findOrFail(1), $refunds, 9);

        $this->assertSame(3, DB::table('payment_sale_returns')->count());
        $this->assertDatabaseHas('payment_sale_returns', ['payment_method_id' => 1, 'montant' => 100]);
        $this->assertDatabaseHas('payment_sale_returns', ['payment_method_id' => 2, 'montant' => 50]);
        $this->assertDatabaseHas('payment_sale_returns', ['payment_method_id' => 3, 'montant' => 25]);
        $this->assertSame(900.0, (float) DB::table('accounts')->where('id', 1)->value('balance'));
        $this->assertSame(1950.0, (float) DB::table('accounts')->where('id', 2)->value('balance'));
        $this->assertSame(475.0, (float) DB::table('accounts')->where('id', 3)->value('balance'));
    }
}
