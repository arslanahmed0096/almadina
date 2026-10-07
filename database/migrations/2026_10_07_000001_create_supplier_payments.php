<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupplierPayments extends Migration
{
    public function up()
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference', 50)->unique();
            $table->integer('provider_id');
            $table->integer('user_id');
            $table->integer('payment_method_id');
            $table->integer('account_id')->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->date('transfer_date')->nullable();
            $table->string('cheque_number')->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('cheque_status', 20)->nullable();
            $table->date('clearance_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('posted');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->index(['provider_id', 'payment_date']);
        });

        Schema::table('payment_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_payment_id')->nullable()->after('purchase_id');
            $table->foreign('supplier_payment_id')->references('id')->on('supplier_payments')->restrictOnDelete();
            $table->index('supplier_payment_id');
        });
    }

    public function down()
    {
        Schema::table('payment_purchases', function (Blueprint $table) {
            $table->dropForeign(['supplier_payment_id']);
            $table->dropIndex(['supplier_payment_id']);
            $table->dropColumn('supplier_payment_id');
        });
        Schema::dropIfExists('supplier_payments');
    }
}
