<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('purchase_source', 20)->default('direct')->after('posting_status')->index();
        });

        DB::table('purchases')
            ->where(function ($query) {
                $query->whereNotNull('gate_pass_id')
                    ->orWhere('inventory_already_received', true);
            })
            ->update(['purchase_source' => 'gate_pass']);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['purchase_source']);
            $table->dropColumn('purchase_source');
        });
    }
};
