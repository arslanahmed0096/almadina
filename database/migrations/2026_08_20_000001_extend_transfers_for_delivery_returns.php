<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->integer('driver_id')->nullable()->after('received_by')->index();
            $table->string('vehicle_details')->nullable()->after('driver_id');
            $table->text('dispatch_note')->nullable()->after('acknowledgement_note');
            $table->text('receiving_note')->nullable()->after('dispatch_note');
            $table->integer('cancelled_by')->nullable()->after('received_by')->index();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::table('transfer_details', function (Blueprint $table) {
            $table->decimal('accepted_quantity', 20, 6)->default(0)->after('received_quantity');
            $table->decimal('rejected_quantity', 20, 6)->default(0)->after('accepted_quantity');
            $table->string('rejection_reason_code', 80)->nullable()->after('rejected_quantity');
            $table->text('rejection_note')->nullable()->after('rejection_reason_code');
            $table->integer('rejected_by')->nullable()->after('rejection_note')->index();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->json('identifiers')->nullable()->after('requested_batches');
        });

        Schema::create('transfer_returns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('transfer_id')->unique();
            $table->string('reference', 64)->unique();
            $table->integer('from_warehouse_id')->index();
            $table->integer('to_warehouse_id')->index();
            $table->integer('driver_id')->nullable()->index();
            $table->string('vehicle_details')->nullable();
            $table->string('status', 50)->index();
            $table->text('note')->nullable();
            $table->integer('created_by')->index();
            $table->integer('dispatched_by')->nullable()->index();
            $table->integer('received_by')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps(6);
        });

        Schema::create('transfer_return_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transfer_return_id')->index();
            $table->integer('transfer_detail_id')->index();
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable()->index();
            $table->integer('purchase_unit_id')->nullable();
            $table->decimal('quantity', 20, 6);
            $table->string('reason_code', 80);
            $table->text('reason_note')->nullable();
            $table->string('received_condition', 50)->nullable();
            $table->json('identifiers')->nullable();
            $table->timestamps(6);
            $table->unique(['transfer_return_id', 'transfer_detail_id'], 'transfer_return_detail_unique');
        });

        Schema::create('transfer_stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('transfer_id')->index();
            $table->unsignedBigInteger('transfer_return_id')->nullable()->index();
            $table->integer('transfer_detail_id')->nullable()->index();
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable()->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->string('movement_type', 60)->index();
            $table->string('stock_state', 60)->index();
            $table->decimal('quantity', 20, 6);
            $table->string('reference', 64);
            $table->integer('performed_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_stock_movements');
        Schema::dropIfExists('transfer_return_details');
        Schema::dropIfExists('transfer_returns');

        Schema::table('transfer_details', function (Blueprint $table) {
            $table->dropColumn(['accepted_quantity', 'rejected_quantity', 'rejection_reason_code', 'rejection_note', 'rejected_by', 'rejected_at', 'identifiers']);
        });
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn(['driver_id', 'vehicle_details', 'dispatch_note', 'receiving_note', 'cancelled_by', 'cancelled_at']);
        });
    }
};
