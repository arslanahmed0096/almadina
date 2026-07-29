<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'username')) {
                $table->string('username')->nullable()->after('lastname');
            }

            if (! Schema::hasColumn('clients', 'company_name')) {
                $table->string('company_name')->nullable()->after('username');
            }
        });

        // The old customer form labelled `name` as Username. Preserve that
        // information in the new independent username field for existing rows.
        if (Schema::hasColumn('clients', 'username')) {
            DB::table('clients')
                ->whereNull('username')
                ->update(['username' => DB::raw('name')]);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'company_name')) {
                $table->dropColumn('company_name');
            }

            if (Schema::hasColumn('clients', 'username')) {
                $table->dropColumn('username');
            }
        });
    }
};
