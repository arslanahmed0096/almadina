<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('allowed_ips_enabled')->default(false);
            $table->text('allowed_ips')->nullable();
            $table->json('allowed_ip_role_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'allowed_ips_enabled',
                'allowed_ips',
                'allowed_ip_role_ids',
            ]);
        });
    }
};
