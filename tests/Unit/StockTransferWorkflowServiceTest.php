<?php

namespace Tests\Unit;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReturn;
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
        foreach (['damage_details', 'damages', 'transfer_stock_movements', 'transfer_return_details', 'transfer_returns', 'transfer_status_histories', 'transfer_details', 'transfers', 'product_warehouse', 'products', 'units', 'employees', 'designations', 'user_warehouse', 'warehouses', 'users'] as $table) {
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

    public function test_dispatch_partial_receipt_and_saleable_return_move_stock_exactly_once(): void
    {
        [$transfer, $detail, $sourceUser, $destinationUser] = $this->seedRequest(6, 10);
        DB::table('designations')->insert(['id' => 1, 'designation' => 'Driver']);
        DB::table('employees')->insert(['id' => 1, 'firstname' => 'Ali', 'lastname' => 'Driver', 'designation_id' => 1]);
        $transfer->update(['workflow_status' => Transfer::WORKFLOW_DRAFT, 'approval_status' => 'approved']);

        $service = app(StockTransferWorkflowService::class);
        $dispatched = $service->dispatchOutbound($transfer->fresh(), $sourceUser, 1, 'Outbound', 'LEA-123');
        $this->assertSame(Transfer::WORKFLOW_IN_TRANSIT, $dispatched->workflow_status);
        $this->assertSame(4.0, (float) DB::table('product_warehouse')->where('warehouse_id', 1)->value('qte'));
        $this->assertSame(1, DB::table('transfer_stock_movements')->where('movement_type', 'dispatch_out')->count());

        $received = $service->receiveDelivery($dispatched, $destinationUser, [[
            'detail_id' => $detail->id,
            'accepted_quantity' => 4,
            'rejected_quantity' => 2,
            'rejection_reason_code' => 'damaged_transport',
            'rejection_note' => 'Outer carton crushed.',
        ]], 'Counted at destination.', 'Return on next vehicle.');
        $this->assertSame(Transfer::WORKFLOW_RETURN_PENDING, $received->workflow_status);
        $this->assertSame(4.0, (float) DB::table('product_warehouse')->where('warehouse_id', 2)->value('qte'));
        $return = TransferReturn::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertSame(2.0, (float) $return->details()->value('quantity'));

        try {
            $service->receiveDelivery($received, $destinationUser, [[
                'detail_id' => $detail->id, 'accepted_quantity' => 4, 'rejected_quantity' => 2,
                'rejection_reason_code' => 'damaged_transport',
            ]]);
            $this->fail('A second receipt should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(3, DB::table('transfer_stock_movements')->count());
        }

        $return = $service->dispatchReturn($return, $destinationUser, 1, 'Returning rejected stock.');
        $completedReturn = $service->receiveReturn($return, $sourceUser, [[
            'return_detail_id' => $return->details()->value('id'),
            'condition' => 'saleable',
        ]], 'Checked and saleable.');
        $this->assertSame(TransferReturn::RECEIVED, $completedReturn->status);
        $this->assertSame(Transfer::WORKFLOW_COMPLETED, $transfer->fresh()->workflow_status);
        $this->assertSame(6.0, (float) DB::table('product_warehouse')->where('warehouse_id', 1)->value('qte'));
        $this->assertSame(5, DB::table('transfer_stock_movements')->count());
    }

    public function test_receipt_requires_exact_total_and_rejection_reason(): void
    {
        [$transfer, $detail, $sourceUser, $destinationUser] = $this->seedRequest(3, 10);
        DB::table('designations')->insert(['id' => 1, 'designation' => 'Driver']);
        DB::table('employees')->insert(['id' => 1, 'firstname' => 'Ali', 'lastname' => 'Driver', 'designation_id' => 1]);
        $transfer->update(['workflow_status' => Transfer::WORKFLOW_DRAFT, 'approval_status' => 'approved']);
        $dispatched = app(StockTransferWorkflowService::class)->dispatchOutbound($transfer->fresh(), $sourceUser, 1);

        $this->expectException(ValidationException::class);
        app(StockTransferWorkflowService::class)->receiveDelivery($dispatched, $destinationUser, [[
            'detail_id' => $detail->id, 'accepted_quantity' => 1, 'rejected_quantity' => 1,
        ]]);
    }

    public function test_draft_can_be_cancelled_without_moving_stock(): void
    {
        [$transfer, , $sourceUser] = $this->seedRequest(3, 10);
        $transfer->update(['workflow_status' => Transfer::WORKFLOW_DRAFT, 'approval_status' => 'approved']);

        $cancelled = app(StockTransferWorkflowService::class)->cancelTransfer($transfer->fresh(), $sourceUser, 'Request entered by mistake.');

        $this->assertSame(Transfer::WORKFLOW_CANCELLED, $cancelled->workflow_status);
        $this->assertSame(10.0, (float) DB::table('product_warehouse')->where('warehouse_id', 1)->value('qte'));
        $this->assertSame(0, DB::table('transfer_stock_movements')->count());
        $this->assertDatabaseHas('transfer_status_histories', ['transfer_id' => $transfer->id, 'action' => 'transfer_cancelled']);
    }

    public function test_damaged_return_is_recorded_without_becoming_saleable_stock(): void
    {
        [$transfer, $detail, $sourceUser, $destinationUser] = $this->seedRequest(2, 10);
        DB::table('designations')->insert(['id' => 1, 'designation' => 'Driver']);
        DB::table('employees')->insert(['id' => 1, 'firstname' => 'Ali', 'lastname' => 'Driver', 'designation_id' => 1]);
        $transfer->update(['workflow_status' => Transfer::WORKFLOW_DRAFT, 'approval_status' => 'approved']);
        $service = app(StockTransferWorkflowService::class);
        $dispatched = $service->dispatchOutbound($transfer->fresh(), $sourceUser, 1);
        $service->receiveDelivery($dispatched, $destinationUser, [[
            'detail_id' => $detail->id, 'accepted_quantity' => 0, 'rejected_quantity' => 2,
            'rejection_reason_code' => 'damaged_transport', 'rejection_note' => 'Both units damaged.',
        ]]);
        $return = $service->dispatchReturn(TransferReturn::where('transfer_id', $transfer->id)->firstOrFail(), $destinationUser, 1);
        $service->receiveReturn($return, $sourceUser, [[
            'return_detail_id' => $return->details()->value('id'), 'condition' => 'damaged',
        ]], 'Moved to damaged stock.');

        $this->assertSame(8.0, (float) DB::table('product_warehouse')->where('warehouse_id', 1)->value('qte'));
        $this->assertDatabaseHas('damages', ['warehouse_id' => 1, 'items' => 1]);
        $this->assertDatabaseHas('damage_details', ['product_id' => 1, 'quantity' => 2]);
        $this->assertDatabaseHas('transfer_stock_movements', ['movement_type' => 'return_receive', 'stock_state' => 'damaged']);
    }

    public function test_destination_request_becomes_pending_only_when_submitted(): void
    {
        [$transfer, , , $destinationUser] = $this->seedRequest(2, 10);
        $transfer->update([
            'workflow_status' => Transfer::WORKFLOW_DRAFT,
            'approval_status' => 'not_required',
            'transfer_type' => 'destination_request',
            'request_note' => 'Branch requires two units.',
        ]);

        $submitted = app(StockTransferWorkflowService::class)->submitDestinationRequest($transfer->fresh(), $destinationUser);

        $this->assertSame(Transfer::WORKFLOW_PENDING_APPROVAL, $submitted->workflow_status);
        $this->assertSame('pending', $submitted->approval_status);
        $this->assertDatabaseHas('transfer_status_histories', ['transfer_id' => $transfer->id, 'action' => 'request_submitted']);
        $this->assertSame(10.0, (float) DB::table('product_warehouse')->where('warehouse_id', 1)->value('qte'));
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
        Schema::create('designations', function (Blueprint $table) { $table->increments('id'); $table->string('designation'); $table->softDeletes(); $table->timestamps(); });
        Schema::create('employees', function (Blueprint $table) { $table->increments('id'); $table->string('firstname'); $table->string('lastname')->nullable(); $table->integer('designation_id'); $table->date('leaving_date')->nullable(); $table->softDeletes(); $table->timestamps(); });
        Schema::create('products', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->string('code'); $table->integer('unit_purchase_id')->nullable(); $table->boolean('is_batch_tracked')->default(false); $table->timestamps(); });
        Schema::create('product_warehouse', function (Blueprint $table) { $table->increments('id'); $table->integer('product_id'); $table->integer('product_variant_id')->nullable(); $table->integer('warehouse_id'); $table->decimal('qte', 20, 6); $table->boolean('manage_stock')->default(true); $table->softDeletes(); $table->timestamps(); });
        Schema::create('transfers', function (Blueprint $table) {
            $table->increments('id'); $table->integer('user_id'); $table->string('Ref'); $table->date('date'); $table->time('time')->nullable();
            $table->integer('from_warehouse_id'); $table->integer('to_warehouse_id'); $table->decimal('items', 20, 6); $table->decimal('tax_rate', 20, 6)->default(0);
            $table->decimal('TaxNet', 20, 6)->default(0); $table->decimal('discount', 20, 6)->default(0); $table->decimal('shipping', 20, 6)->default(0); $table->decimal('GrandTotal', 20, 6)->default(0);
            $table->string('statut'); $table->string('approval_status'); $table->string('workflow_status')->nullable(); $table->string('transfer_type')->default('direct_transfer'); $table->text('notes')->nullable(); $table->text('request_note')->nullable();
            $table->text('response_note')->nullable(); $table->text('acknowledgement_note')->nullable(); $table->integer('processed_by')->nullable(); $table->integer('acknowledged_by')->nullable();
            $table->integer('dispatched_by')->nullable(); $table->integer('received_by')->nullable(); $table->timestamp('requested_at')->nullable(); $table->timestamp('processed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable(); $table->timestamp('dispatched_at')->nullable(); $table->timestamp('received_at')->nullable(); $table->date('required_date')->nullable();
            $table->integer('driver_id')->nullable(); $table->string('vehicle_details')->nullable(); $table->text('dispatch_note')->nullable(); $table->text('receiving_note')->nullable();
            $table->integer('cancelled_by')->nullable(); $table->timestamp('cancelled_at')->nullable();
            $table->softDeletes(); $table->timestamps();
        });
        Schema::create('transfer_details', function (Blueprint $table) {
            $table->increments('id'); $table->integer('transfer_id'); $table->integer('product_id'); $table->integer('product_variant_id')->nullable(); $table->integer('purchase_unit_id')->nullable();
            $table->decimal('quantity', 20, 6); $table->decimal('requested_quantity', 20, 6)->nullable(); $table->decimal('approved_quantity', 20, 6)->default(0);
            $table->decimal('dispatched_quantity', 20, 6)->default(0); $table->decimal('received_quantity', 20, 6)->default(0); $table->string('decision_status')->nullable();
            $table->text('response_reason')->nullable(); $table->json('requested_batches')->nullable(); $table->decimal('cost', 20, 6)->default(0); $table->decimal('TaxNet', 20, 6)->default(0);
            $table->decimal('accepted_quantity', 20, 6)->default(0); $table->decimal('rejected_quantity', 20, 6)->default(0); $table->string('rejection_reason_code')->nullable();
            $table->text('rejection_note')->nullable(); $table->integer('rejected_by')->nullable(); $table->timestamp('rejected_at')->nullable(); $table->json('identifiers')->nullable();
            $table->decimal('discount', 20, 6)->default(0); $table->string('discount_method')->nullable(); $table->string('tax_method')->nullable(); $table->decimal('total', 20, 6)->default(0); $table->timestamps();
        });
        Schema::create('transfer_status_histories', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->integer('transfer_id'); $table->integer('performed_by')->nullable(); $table->integer('warehouse_id')->nullable();
            $table->string('previous_status')->nullable(); $table->string('new_status'); $table->string('action'); $table->text('note')->nullable(); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('transfer_returns', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->integer('transfer_id')->unique(); $table->string('reference')->unique(); $table->integer('from_warehouse_id'); $table->integer('to_warehouse_id');
            $table->integer('driver_id')->nullable(); $table->string('vehicle_details')->nullable(); $table->string('status'); $table->text('note')->nullable(); $table->integer('created_by');
            $table->integer('dispatched_by')->nullable(); $table->integer('received_by')->nullable(); $table->timestamp('dispatched_at')->nullable(); $table->timestamp('received_at')->nullable(); $table->timestamps();
        });
        Schema::create('transfer_return_details', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('transfer_return_id'); $table->integer('transfer_detail_id'); $table->integer('product_id'); $table->integer('product_variant_id')->nullable();
            $table->integer('purchase_unit_id')->nullable(); $table->decimal('quantity', 20, 6); $table->string('reason_code'); $table->text('reason_note')->nullable(); $table->string('received_condition')->nullable(); $table->json('identifiers')->nullable(); $table->timestamps();
        });
        Schema::create('transfer_stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->integer('transfer_id'); $table->unsignedBigInteger('transfer_return_id')->nullable(); $table->integer('transfer_detail_id')->nullable(); $table->integer('product_id');
            $table->integer('product_variant_id')->nullable(); $table->integer('warehouse_id')->nullable(); $table->string('movement_type'); $table->string('stock_state'); $table->decimal('quantity', 20, 6);
            $table->string('reference'); $table->integer('performed_by')->nullable(); $table->json('metadata')->nullable(); $table->string('idempotency_key')->unique(); $table->timestamps();
        });
        Schema::create('damages', function (Blueprint $table) { $table->increments('id'); $table->date('date'); $table->time('time')->nullable(); $table->string('Ref'); $table->integer('user_id'); $table->integer('warehouse_id'); $table->integer('items')->default(0); $table->text('notes')->nullable(); $table->softDeletes(); $table->timestamps(); });
        Schema::create('damage_details', function (Blueprint $table) { $table->increments('id'); $table->integer('damage_id'); $table->integer('product_id'); $table->integer('product_variant_id')->nullable(); $table->decimal('quantity', 20, 6); $table->timestamps(); });
    }
}
