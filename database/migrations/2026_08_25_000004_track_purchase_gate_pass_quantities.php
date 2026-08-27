<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_gate_pass_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('purchase_id');
            $table->unsignedBigInteger('gate_pass_item_id');
            $table->decimal('quantity', 20, 6);
            $table->timestamps();
            $table->unique(['purchase_id', 'gate_pass_item_id'], 'purchase_gp_item_unique');
            $table->index('gate_pass_item_id');
            $table->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
            $table->foreign('gate_pass_item_id')->references('id')->on('gate_pass_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_gate_pass_items');
    }
};
