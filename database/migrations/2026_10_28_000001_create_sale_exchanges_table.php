<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_exchanges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('original_sale_id');
            $table->integer('sale_return_id')->unique();
            $table->integer('replacement_sale_id')->nullable()->unique();
            $table->integer('client_id');
            $table->integer('warehouse_id');
            $table->integer('created_by')->nullable();
            $table->string('status', 30)->default('awaiting_replacement');
            $table->timestamps();

            $table->index(['original_sale_id', 'status']);
            $table->foreign('original_sale_id')->references('id')->on('sales')->restrictOnDelete();
            $table->foreign('sale_return_id')->references('id')->on('sale_returns')->restrictOnDelete();
            $table->foreign('replacement_sale_id')->references('id')->on('sales')->restrictOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_exchanges');
    }
};
