<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('sales_tax_invoice_no', 100)->nullable()->after('Ref');
            $table->string('delivery_note_no', 100)->nullable()->after('sales_tax_invoice_no');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['sales_tax_invoice_no', 'delivery_note_no']);
        });
    }
};
