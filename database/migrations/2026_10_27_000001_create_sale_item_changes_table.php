<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_item_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Sales, sale details, products, and users use signed INT keys in this project.
            // Detail/product IDs deliberately remain snapshots without foreign keys so the
            // audit survives a removed order line or a later soft-deleted product.
            $table->integer('sale_id');
            $table->integer('sale_detail_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('action', 40);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sale_id', 'created_at'], 'sale_item_changes_sale_created_idx');
            $table->index('sale_detail_id', 'sale_item_changes_detail_idx');
            $table->foreign('sale_id', 'sale_item_changes_sale_fk')
                ->references('id')->on('sales')->onUpdate('RESTRICT')->onDelete('RESTRICT');
            $table->foreign('user_id', 'sale_item_changes_user_fk')
                ->references('id')->on('users')->onUpdate('RESTRICT')->onDelete('SET NULL');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_changes');
    }
};
