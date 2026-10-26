<?php

namespace Tests\Unit;

use App\Models\StockTransferCompany;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\User;
use App\Services\StockTransferLedgerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockTransferLedgerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        DB::table('users')->insert(['id' => 1, 'username' => 'Finance User']);
        DB::table('payment_methods')->insert(['id' => 1, 'name' => 'Cash', 'is_active' => 1]);
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'Central', 'ownership_type' => 'internal']);
    }

    protected function tearDown(): void
    {
        foreach (['stock_transfer_ledger_entries', 'stock_transfer_payment_allocations', 'stock_transfer_payments', 'transfer_details', 'transfers', 'warehouses', 'stock_transfer_companies', 'payment_methods', 'accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_external_transfer_posts_once_and_partial_payment_is_allocated_once(): void
    {
        [$company, $transfer] = $this->externalTransfer(150);
        $service = app(StockTransferLedgerService::class);
        $user = User::findOrFail(1);

        $service->postTransfer($transfer, $user);
        $service->postTransfer($transfer->fresh(), $user);

        $this->assertSame(1, DB::table('stock_transfer_ledger_entries')->where('entry_type', 'stock_transfer')->count());
        $this->assertSame(150.0, (float) $service->companyBalance($company->id));

        $payment = $service->recordPayment([
            'stock_transfer_company_id' => $company->id,
            'payment_date' => now()->toDateString(),
            'amount' => 40,
            'payment_method_id' => 1,
            'auto_allocate' => true,
        ], $user);

        $this->assertSame(40.0, (float) $payment->allocations()->sum('amount'));
        $this->assertSame(110.0, (float) $transfer->fresh()->outstanding_balance);
        $this->assertSame('partially_paid', $transfer->fresh()->payment_status);
        $this->assertSame(110.0, (float) $service->companyBalance($company->id));
        $this->assertSame(2, DB::table('stock_transfer_ledger_entries')->where(function ($query) {
            $query->where('debit', '>', 0)->orWhere('credit', '>', 0);
        })->count());
    }

    public function test_unallocated_advance_is_applied_to_future_transfer_without_second_credit(): void
    {
        $company = $this->company();
        $user = User::findOrFail(1);
        $service = app(StockTransferLedgerService::class);
        $payment = $service->recordPayment([
            'stock_transfer_company_id' => $company->id,
            'payment_date' => now()->subDay()->toDateString(),
            'amount' => 100,
            'payment_method_id' => 1,
            'idempotency_key' => '91c1b731-bd5f-4db0-bc20-dfdac83d9f76',
            'auto_allocate' => true,
        ], $user);
        $duplicate = $service->recordPayment([
            'stock_transfer_company_id' => $company->id,
            'payment_date' => now()->subDay()->toDateString(),
            'amount' => 100,
            'payment_method_id' => 1,
            'idempotency_key' => '91c1b731-bd5f-4db0-bc20-dfdac83d9f76',
            'auto_allocate' => true,
        ], $user);
        $this->assertSame($payment->id, $duplicate->id);

        [, $transfer] = $this->externalTransfer(70, $company);
        $service->postTransfer($transfer, $user);

        $this->assertSame(70.0, (float) $payment->allocations()->sum('amount'));
        $this->assertSame(30.0, (float) $payment->amount - (float) $payment->allocations()->sum('amount'));
        $this->assertSame('paid', $transfer->fresh()->payment_status);
        $this->assertSame(70.0, (float) $transfer->fresh()->advance_applied);
        $this->assertSame(0.0, (float) $transfer->fresh()->outstanding_balance);
        $this->assertSame(-30.0, (float) $service->companyBalance($company->id));
        $this->assertSame(1, DB::table('stock_transfer_ledger_entries')->where('entry_type', 'payment_received')->count());
        $this->assertDatabaseHas('stock_transfer_ledger_entries', ['entry_type' => 'advance_adjustment', 'debit' => 0, 'credit' => 0]);
    }

    private function externalTransfer(float $amount, ?StockTransferCompany $company = null): array
    {
        $company ??= $this->company();
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'name' => 'External '.$company->id, 'ownership_type' => 'external', 'stock_transfer_company_id' => $company->id,
        ]);
        $transfer = Transfer::create([
            'Ref' => 'TR-'.$warehouseId, 'date' => now()->toDateString(), 'user_id' => 1,
            'from_warehouse_id' => 1, 'to_warehouse_id' => $warehouseId, 'items' => 1,
            'statut' => 'sent', 'workflow_status' => Transfer::WORKFLOW_IN_TRANSIT,
            'transfer_type' => 'direct_transfer', 'ownership_type' => 'external',
            'stock_transfer_company_id' => $company->id, 'GrandTotal' => $amount,
            'transfer_value' => $amount, 'payment_status' => 'unpaid',
        ]);
        TransferDetail::create([
            'transfer_id' => $transfer->id, 'product_id' => 1, 'quantity' => 1,
            'requested_quantity' => 1, 'approved_quantity' => 1, 'dispatched_quantity' => 1,
            'cost' => $amount, 'unit_transfer_price' => $amount, 'transfer_total' => $amount, 'total' => $amount,
        ]);
        return [$company, $transfer];
    }

    private function company(): StockTransferCompany
    {
        return StockTransferCompany::create(['name' => 'Partner '.uniqid(), 'opening_balance' => 0, 'is_active' => true]);
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) { $table->increments('id'); $table->string('username')->nullable(); $table->timestamps(); });
        Schema::create('stock_transfer_companies', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->string('code')->nullable(); $table->decimal('opening_balance', 20, 4)->default(0); $table->boolean('is_active')->default(true); $table->softDeletes(); $table->timestamps(); });
        Schema::create('warehouses', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->string('ownership_type')->default('internal'); $table->integer('stock_transfer_company_id')->nullable(); $table->softDeletes(); $table->timestamps(); });
        Schema::create('transfers', function (Blueprint $table) {
            $table->increments('id'); $table->string('Ref'); $table->date('date'); $table->integer('user_id'); $table->integer('from_warehouse_id'); $table->integer('to_warehouse_id');
            $table->decimal('items', 20, 4)->default(0); $table->string('statut'); $table->string('workflow_status')->nullable(); $table->string('transfer_type'); $table->string('ownership_type')->default('internal');
            $table->integer('stock_transfer_company_id')->nullable(); $table->decimal('GrandTotal', 20, 4)->default(0); $table->decimal('transfer_value', 20, 4)->default(0); $table->decimal('previous_outstanding', 20, 4)->default(0);
            $table->decimal('advance_applied', 20, 4)->default(0); $table->decimal('paid_amount', 20, 4)->default(0); $table->decimal('outstanding_balance', 20, 4)->default(0); $table->string('payment_status')->default('not_applicable');
            $table->timestamp('financial_posted_at')->nullable(); $table->text('notes')->nullable(); $table->softDeletes(); $table->timestamps();
        });
        Schema::create('transfer_details', function (Blueprint $table) {
            $table->increments('id'); $table->integer('transfer_id'); $table->integer('product_id'); $table->decimal('quantity', 20, 4); $table->decimal('requested_quantity', 20, 4)->nullable();
            $table->decimal('approved_quantity', 20, 4)->nullable(); $table->decimal('dispatched_quantity', 20, 4)->nullable(); $table->decimal('cost', 20, 4)->default(0); $table->decimal('unit_transfer_price', 20, 4)->nullable();
            $table->decimal('transfer_total', 20, 4)->nullable(); $table->decimal('total', 20, 4)->default(0); $table->timestamps();
        });
        Schema::create('payment_methods', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->boolean('is_active')->default(true); $table->softDeletes(); $table->timestamps(); });
        Schema::create('accounts', function (Blueprint $table) { $table->increments('id'); $table->string('account_name'); $table->string('account_type'); $table->decimal('balance', 20, 4)->default(0); $table->softDeletes(); $table->timestamps(); });
        Schema::create('stock_transfer_payments', function (Blueprint $table) { $table->increments('id'); $table->integer('stock_transfer_company_id'); $table->integer('warehouse_id')->nullable(); $table->date('payment_date'); $table->decimal('amount', 20, 4); $table->integer('payment_method_id')->nullable(); $table->integer('account_id')->nullable(); $table->string('reference')->unique(); $table->uuid('idempotency_key')->nullable()->unique(); $table->string('external_reference')->nullable(); $table->text('remarks')->nullable(); $table->integer('received_by'); $table->string('status'); $table->timestamp('cancelled_at')->nullable(); $table->integer('cancelled_by')->nullable(); $table->timestamps(); });
        Schema::create('stock_transfer_payment_allocations', function (Blueprint $table) { $table->increments('id'); $table->integer('stock_transfer_payment_id'); $table->integer('transfer_id'); $table->decimal('amount', 20, 4); $table->string('allocation_type'); $table->integer('allocated_by')->nullable(); $table->timestamps(); $table->unique(['stock_transfer_payment_id', 'transfer_id'], 'st_test_alloc_unique'); });
        Schema::create('stock_transfer_ledger_entries', function (Blueprint $table) { $table->increments('id'); $table->integer('stock_transfer_company_id'); $table->integer('warehouse_id')->nullable(); $table->integer('transfer_id')->nullable(); $table->integer('stock_transfer_payment_id')->nullable(); $table->integer('transfer_return_id')->nullable(); $table->integer('reversal_of_id')->nullable(); $table->date('entry_date'); $table->string('entry_type'); $table->string('reference'); $table->decimal('debit', 20, 4)->default(0); $table->decimal('credit', 20, 4)->default(0); $table->text('notes')->nullable(); $table->integer('posted_by')->nullable(); $table->string('idempotency_key')->unique(); $table->json('metadata')->nullable(); $table->timestamps(); });
    }
}
