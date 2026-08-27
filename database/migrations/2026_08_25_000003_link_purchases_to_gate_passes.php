<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. A failed foreign-key statement can leave
        // the newly-created pivot table behind even though the migration was not
        // recorded. Only remove that partial table when it is still empty.
        if (Schema::hasTable('purchase_gate_pass')) {
            if (DB::table('purchase_gate_pass')->exists()) {
                throw new RuntimeException('The unrecorded purchase_gate_pass table contains data and cannot be rebuilt automatically.');
            }
            Schema::drop('purchase_gate_pass');
        }

        Schema::create('purchase_gate_pass', function (Blueprint $table) {
            // purchases.id is a legacy signed INT column in existing installations.
            $table->integer('purchase_id');
            $table->unsignedBigInteger('gate_pass_id');
            $table->timestamps();
            $table->primary(['purchase_id', 'gate_pass_id']);
            $table->index('gate_pass_id');
            $table->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
            $table->foreign('gate_pass_id')->references('id')->on('gate_passes')->restrictOnDelete();
        });

        DB::table('purchases')->whereNotNull('gate_pass_id')->orderBy('id')->chunkById(500, function ($purchases) {
            $now = now();
            $rows = $purchases->map(fn ($purchase) => [
                'purchase_id' => $purchase->id,
                'gate_pass_id' => $purchase->gate_pass_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            DB::table('purchase_gate_pass')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_gate_pass');
    }
};
