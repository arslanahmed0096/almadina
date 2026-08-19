<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('workflow_status', 50)->nullable()->after('approval_status')->index();
            $table->date('required_date')->nullable()->after('date');
            $table->text('request_note')->nullable()->after('notes');
            $table->text('response_note')->nullable()->after('request_note');
            $table->text('acknowledgement_note')->nullable()->after('response_note');
            $table->integer('processed_by')->nullable()->after('user_id')->index();
            $table->integer('acknowledged_by')->nullable()->after('processed_by')->index();
            $table->integer('dispatched_by')->nullable()->after('acknowledged_by')->index();
            $table->integer('received_by')->nullable()->after('dispatched_by')->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
        });

        Schema::table('transfer_details', function (Blueprint $table) {
            $table->decimal('requested_quantity', 20, 6)->nullable()->after('quantity');
            $table->decimal('approved_quantity', 20, 6)->default(0)->after('requested_quantity');
            $table->decimal('dispatched_quantity', 20, 6)->default(0)->after('approved_quantity');
            $table->decimal('received_quantity', 20, 6)->default(0)->after('dispatched_quantity');
            $table->string('decision_status', 50)->nullable()->after('received_quantity');
            $table->text('response_reason')->nullable()->after('decision_status');
            $table->json('requested_batches')->nullable()->after('response_reason');
        });

        if (Schema::hasTable('transfer_detail_batches') && ! Schema::hasColumn('transfer_detail_batches', 'received_qty')) {
            Schema::table('transfer_detail_batches', function (Blueprint $table) {
                $table->decimal('received_qty', 20, 6)->default(0)->after('qty');
            });
        }

        Schema::create('transfer_status_histories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('transfer_id')->index();
            $table->integer('performed_by')->nullable()->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->string('previous_status', 50)->nullable();
            $table->string('new_status', 50);
            $table->string('action', 80);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
        });

        DB::table('transfer_details')->whereNull('requested_quantity')->update([
            'requested_quantity' => DB::raw('quantity'),
        ]);

        DB::table('transfer_details')
            ->whereIn('transfer_id', function ($query) {
                $query->select('id')->from('transfers')->where('approval_status', 'approved');
            })
            ->update([
                'approved_quantity' => DB::raw('quantity'),
                'decision_status' => 'approved',
            ]);

        DB::table('transfers')->whereNull('workflow_status')->orderBy('id')->chunkById(200, function ($transfers) {
            foreach ($transfers as $transfer) {
                $status = match (true) {
                    $transfer->approval_status === 'rejected' => 'declined',
                    $transfer->approval_status === 'pending' => 'pending_approval',
                    $transfer->statut === 'completed' => 'completed',
                    $transfer->statut === 'sent' => 'dispatched',
                    default => 'acknowledged',
                };

                DB::table('transfers')->where('id', $transfer->id)->update([
                    'workflow_status' => $status,
                    'request_note' => $transfer->notes,
                    'requested_at' => $transfer->created_at,
                    'processed_at' => $transfer->approval_status === 'approved' ? $transfer->updated_at : null,
                    'dispatched_at' => in_array($status, ['dispatched', 'completed'], true) ? $transfer->updated_at : null,
                    'received_at' => $status === 'completed' ? $transfer->updated_at : null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_status_histories');

        if (Schema::hasTable('transfer_detail_batches') && Schema::hasColumn('transfer_detail_batches', 'received_qty')) {
            Schema::table('transfer_detail_batches', function (Blueprint $table) {
                $table->dropColumn('received_qty');
            });
        }

        Schema::table('transfer_details', function (Blueprint $table) {
            $table->dropColumn([
                'requested_quantity', 'approved_quantity', 'dispatched_quantity', 'received_quantity',
                'decision_status', 'response_reason', 'requested_batches',
            ]);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn([
                'workflow_status', 'required_date', 'request_note', 'response_note', 'acknowledgement_note',
                'processed_by', 'acknowledged_by', 'dispatched_by', 'received_by', 'requested_at',
                'processed_at', 'acknowledged_at', 'dispatched_at', 'received_at',
            ]);
        });
    }
};
