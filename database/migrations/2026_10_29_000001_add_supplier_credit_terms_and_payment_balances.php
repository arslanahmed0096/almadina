<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('credit_terms_mode', 20)->default('invoice');
            $table->unsignedTinyInteger('credit_days')->default(15);
        });

        Schema::table('category_provider', function (Blueprint $table) {
            $table->unsignedTinyInteger('credit_days')->nullable();
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->decimal('opening_balance_amount', 15, 2)->default(0);
        });

        Schema::table('provider_opening_balance_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_payment_id')->nullable()->unique();
            $table->foreign('supplier_payment_id')
                ->references('id')
                ->on('supplier_payments')
                ->nullOnDelete();
        });

        Schema::create('purchase_category_credit_terms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('purchase_id');
            $table->integer('category_id')->nullable();
            $table->string('category_name', 191);
            $table->unsignedTinyInteger('credit_days');
            $table->decimal('amount', 15, 2);
            $table->date('due_date');
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
            $table->index(['purchase_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_category_credit_terms');

        Schema::table('provider_opening_balance_payments', function (Blueprint $table) {
            $table->dropForeign(['supplier_payment_id']);
            $table->dropUnique(['supplier_payment_id']);
            $table->dropColumn('supplier_payment_id');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn('opening_balance_amount');
        });

        Schema::table('category_provider', function (Blueprint $table) {
            $table->dropColumn('credit_days');
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['credit_terms_mode', 'credit_days']);
        });
    }
};
