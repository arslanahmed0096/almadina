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
            $table->string('transfer_type', 40)->default('direct_transfer')->after('workflow_status')->index();
        });

        DB::table('transfers')
            ->where('workflow_status', 'draft')
            ->update(['approval_status' => 'not_required']);
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('transfer_type');
        });
    }
};
