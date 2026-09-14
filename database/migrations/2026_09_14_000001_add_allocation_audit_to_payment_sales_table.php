<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_sales')) {
            return;
        }

        if (! Schema::hasColumn('payment_sales', 'allocation_reference')) {
            Schema::table('payment_sales', function (Blueprint $table) {
                $table->string('allocation_reference', 64)
                    ->nullable()
                    ->after('notes')
                    ->index('payment_sales_allocation_reference_index');
            });
        }

        if (! Schema::hasColumn('payment_sales', 'source_sale_id')) {
            Schema::table('payment_sales', function (Blueprint $table) {
                $table->integer('source_sale_id')
                    ->nullable()
                    ->after('allocation_reference')
                    ->index('payment_sales_source_sale_id_index');
            });
        }

        if (! Schema::hasColumn('payment_sales', 'source_sale_ref')) {
            Schema::table('payment_sales', function (Blueprint $table) {
                $table->string('source_sale_ref', 192)->nullable()->after('source_sale_id');
            });
        }

        if (! Schema::hasColumn('payment_sales', 'allocation_type')) {
            Schema::table('payment_sales', function (Blueprint $table) {
                $table->string('allocation_type', 20)->nullable()->after('source_sale_ref');
            });
        }

        if (! Schema::hasColumn('payment_sales', 'allocation_sequence')) {
            Schema::table('payment_sales', function (Blueprint $table) {
                $table->unsignedSmallInteger('allocation_sequence')->nullable()->after('allocation_type');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_sales')) {
            return;
        }

        try {
            Schema::table('payment_sales', function (Blueprint $table) {
                $table->dropIndex('payment_sales_allocation_reference_index');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('payment_sales', function (Blueprint $table) {
                $table->dropIndex('payment_sales_source_sale_id_index');
            });
        } catch (\Throwable $e) {
        }

        $columns = collect([
            'allocation_reference', 'source_sale_id', 'source_sale_ref',
            'allocation_type', 'allocation_sequence',
        ])->filter(fn ($column) => Schema::hasColumn('payment_sales', $column))->all();

        if ($columns) {
            Schema::table('payment_sales', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
