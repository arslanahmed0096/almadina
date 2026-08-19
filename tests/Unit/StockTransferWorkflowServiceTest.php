<?php

namespace Tests\Unit;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\User;
use App\Services\StockTransferWorkflowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockTransferWorkflowServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach (['transfer_status_histories', 'transfer_details', 'transfers', 'product_warehouse', 'products', 'units', 'user_warehouse', 'warehouses', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_partial_approval_reserves_without_moving_stock_then_dispatch_and_receipt_move_once(): void
    {
        [$transfer, $detail, $approver, $branchUser] = $this->seedRequest(6, 10);
        $service = app(StockTransferWorkflowService::class);

        $processed = $service->process($transfer, $approver, [[
            'detail_id' => $detail->id,
            'approved_quantity' => 4,
            'response_reason' => 'Only four units are transferable.',
        ]], 'Partial stock is available.');

        $this->assertSame('partially_approved', $processed->approval_status);
        $this->assertSame(Transfer::WORKFLOW_PENDING_ACKNOWLEDGEMENT, $processed->workflow_status);
        $this->assertSame(10.0, (float) DB::table('product_warehouse')->where('warehouse_id', 1)->value('qte'));
        $this->assertNull(DB::table('product_warehouse')->where('warehouse_id', 2)->first());

        $service->acknowledge($processed, $branchUser, 'Branch accepts four units.');
        $dispatched = $service->dispatch($processed->fresh(), $approver, 'Dispatched four units.');
        $this->assertSame(6.0, (float) DB::table('product_warehouse')->where('warehouse_id', 1)->value('qte'));
        $this->assertNull(DB::table('product_warehouse')->where('warehouse_id', 2)->first());

        $partiallyReceived = $service->receive($dispatched, $branchUser, [[
            'detail_id' => $detail->id,
            'received_quantity' => 3,
        ]], 'Three units received.');
        $this->assertSame(Transfer::WORKFLOW_PARTIALLY_RECEIVED, $partiallyReceived->workflow_status);
        $this->assertSame(3.0, (float) DB::table('product_warehouse')->where('warehouse_id', 2)->value('qte'));

        $completed = $service->receive($partiallyReceived, $branchUser, [[
            'detail_id' => $detail->id,
            'received_quantity' => 4,
        ]], 'Final unit received.');
        $this->assertSame(Transfer::WORKFLOW_COMPLETED, $completed->workflow_status);
        $this->assertSame(4.0, (float) DB::table('product_warehouse')->where('warehouse_id', 2)->value('qte'));
        $this->assertSame(6, DB::table('transfer_status_histories')->count());

        $this->expectException(ValidationException::class);
        $service->dispatch($completed, $approver, null);
    }

    public function test_approval_cannot_exceed_transferable_stock(): void
    {
        [$transfer, $detail, $approver] = $this->seedRequest(6, 3);

        $this->expectException(ValidationException::class);
        app(StockTransferWorkflowService::class)->process($transfer, $approver, [[
            'detail_id' => $detail->id,
            'approved_quantity' => 4,
            'response_reason' => 'Attempt above stock.',
        ]], 'Review complete.');
    }

    public function test_partial_or_declined_item_requires_a_reason(): void
    {
        [$transfer, $detail, $approver] = $this->seedRequest(6, 10);

        $this->expectException(ValidationException::class);
        app(StockTransferWorkflowService::class)->process($transfer, $approver, [[
            'detail_id' => $detail->id,
            'approved_quantity' => 2,
            'response_reason' => '',
        ]], 'Review complete.');
    }

    private function seedRequest(float $requested, float $stock): array
    {
        DB::table('users')->insert([
            ['id' => 1, 'username' => 'Central Approver', 'role_id' => 1, 'is_all_warehouses' => 1],
            ['id' => 2, 'username' => 'Branch Manager', 'role_id' => 1, 'is_all_warehouses' => 0],
        ]);
        DB::table('warehouses')->insert([['id' => 1, 'name' => 'Central'], ['id' => 2, 'name' => 'Branch']]);
        DB::table('user_warehouse')->insert(['user_id' => 2, 'warehouse_id' => 2]);
        DB::table('units')->insert(['id' => 1, 'ShortName' => 'Pc', 'operator' => '*', 'operator_value' => 1]);
        DB::table('products')->insert(['id' => 1, 'name' => 'Test Product', 'code' => 'TP-1', 'is_batch_tracked' => 0]);
        DB::table('product_warehouse')->insert(['product_id' => 1, 'product_variant_id' => null, 'warehouse_id' => 1, 'qte' => $stock, 'manage_stock' => 1]);

        $transfer = Transfer::create([
            'date' => now()->toDateString(), 'time' => now()->format('H:i:s'), 'Ref' => 'TR_TEST', 'user_id' => 2,
            'from_warehouse_id' => 1, 'to_warehouse_id' => 2, 'items' => 1, 'statut' => 'pending',
            'approval_status' => 'pending', 'workflow_status' => Transfer::WORKFLOW_PENDING_APPROVAL,
            'GrandTotal' => 0, 'discount' => 0, 'shipping' => 0, 'TaxNet' => 0, 'tax_rate' => 0,
        ]);
        $detail = TransferDetail::create([
            'transfer_id' => $transfer->id, 'product_id' => 1, 'product_variant_id' => null,
            'purchase_unit_id' => 1, 'quantity' => $requested, 'requested_quantity' => $requested,
            'approved_quantity' => 0, 'dispatched_quantity' => 0, 'received_quantity' => 0,
            'cost' => 0, 'TaxNet' => 0, 'discount' => 0, 'total' => 0,
        ]);

        return [$transfer, $detail, User::findOrFail(1), User::findOrFail(2)];
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id'); $table->string('username')->nullable(); $table->integer('role_id')->nullable();
            $table->boolean('is_all_warehouses')->default(false); $table->boolean('record_view')->nullable(); $table->softDeletes(); $table->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->softDeletes(); $table->timestamps(); });
        Schema::create('user_warehouse', function (Blueprint $table) { $table->increments('id'); $table->integer('user_id'); $table->integer('warehouse_id'); $table->timestamps(); });
        Schema::create('units', function (Blueprint $table) { $table->increments('id'); $table->string('ShortName'); $table->string('operator'); $table->decimal('operator_value', 20, 6); $table->timestamps(); });
        Schema::create('products', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->string('code'); $table->boolean('is_batch_tracked')->default(false); $table->timestamps(); });
        Schema::create('product_warehouse', function (Blueprint $table) { $table->increments('id'); $table->integer('product_id'); $table->integer('product_variant_id')->nullable(); $table->integer('warehouse_id'); $table->decimal('qte', 20, 6); $table->boolean('manage_stock')->default(true); $table->softDeletes(); $table->timestamps(); });
        Schema::create('transfers', function (Blueprint $table) {
            $table->increments('id'); $table->integer('user_id'); $table->string('Ref'); $table->date('date'); $table->time('time')->nullable();
            $table->integer('from_warehouse_id'); $table->integer('to_warehouse_id'); $table->decimal('items', 20, 6); $table->decimal('tax_rate', 20, 6)->default(0);
            $table->decimal('TaxNet', 20, 6)->default(0); $table->decimal('discount', 20, 6)->default(0); $table->decimal('shipping', 20, 6)->default(0); $table->decimal('GrandTotal', 20, 6)->default(0);
            $table->string('statut'); $table->string('approval_status'); $table->string('workflow_status')->nullable(); $table->text('notes')->nullable(); $table->text('request_note')->nullable();
            $table->text('response_note')->nullable(); $table->text('acknowledgement_note')->nullable(); $table->integer('processed_by')->nullable(); $table->integer('acknowledged_by')->nullable();
            $table->integer('dispatched_by')->nullable(); $table->integer('received_by')->nullable(); $table->timestamp('requested_at')->nullable(); $table->timestamp('processed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable(); $table->timestamp('dispatched_at')->nullable(); $table->timestamp('received_at')->nullable(); $table->date('required_date')->nullable();
            $table->softDeletes(); $table->timestamps();
        });
        Schema::create('transfer_details', function (Blueprint $table) {
            $table->increments('id'); $table->integer('transfer_id'); $table->integer('product_id'); $table->integer('product_variant_id')->nullable(); $table->integer('purchase_unit_id')->nullable();
            $table->decimal('quantity', 20, 6); $table->decimal('requested_quantity', 20, 6)->nullable(); $table->decimal('approved_quantity', 20, 6)->default(0);
            $table->decimal('dispatched_quantity', 20, 6)->default(0); $table->decimal('received_quantity', 20, 6)->default(0); $table->string('decision_status')->nullable();
            $table->text('response_reason')->nullable(); $table->json('requested_batches')->nullable(); $table->decimal('cost', 20, 6)->default(0); $table->decimal('TaxNet', 20, 6)->default(0);
            $table->decimal('discount', 20, 6)->default(0); $table->string('discount_method')->nullable(); $table->string('tax_method')->nullable(); $table->decimal('total', 20, 6)->default(0); $table->timestamps();
        });
        Schema::create('transfer_status_histories', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->integer('transfer_id'); $table->integer('performed_by')->nullable(); $table->integer('warehouse_id')->nullable();
            $table->string('previous_status')->nullable(); $table->string('new_status'); $table->string('action'); $table->text('note')->nullable(); $table->json('metadata')->nullable(); $table->timestamps();
        });
    }
}
