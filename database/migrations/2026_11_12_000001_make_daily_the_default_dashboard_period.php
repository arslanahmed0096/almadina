<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'default_dashboard_date_range')) {
            DB::table('settings')
                ->where(function ($query) {
                    $query->whereNull('default_dashboard_date_range')
                        ->orWhere('default_dashboard_date_range', 'week');
                })
                ->update(['default_dashboard_date_range' => 'today']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'default_dashboard_date_range')) {
            DB::table('settings')
                ->where('default_dashboard_date_range', 'today')
                ->update(['default_dashboard_date_range' => 'week']);
        }
    }
};
