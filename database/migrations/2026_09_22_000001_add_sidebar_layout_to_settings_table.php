<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSidebarLayoutToSettingsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('settings', 'sidebar_layout')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->string('sidebar_layout', 20)
                    ->default('vertical')
                    ->after('rtl');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('settings', 'sidebar_layout')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('sidebar_layout');
            });
        }
    }
}