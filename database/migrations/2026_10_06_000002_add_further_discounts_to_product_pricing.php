<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'product_variants'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'further_discounts')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->json('further_discounts')->nullable()->after('pricing_margins');
                });
            }
            if (! Schema::hasColumn($tableName, 'further_discounted_price')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->decimal('further_discounted_price', 15, 2)->nullable()->after('further_discounts');
                });
            }
        }

        if (! Schema::hasColumn('pricing_level_details', 'further_discounts')) {
            Schema::table('pricing_level_details', function (Blueprint $table) {
                $table->json('further_discounts')->nullable()->after('pricing_margins');
            });
        }
        if (! Schema::hasColumn('pricing_level_details', 'further_discounted_price')) {
            Schema::table('pricing_level_details', function (Blueprint $table) {
                $table->decimal('further_discounted_price', 15, 2)->nullable()->after('further_discounts');
            });
        }
        if (! Schema::hasColumn('pricing_level_details', 'previous_further_discounts')) {
            Schema::table('pricing_level_details', function (Blueprint $table) {
                $table->json('previous_further_discounts')->nullable()->after('previous_pricing_margins');
            });
        }
        if (! Schema::hasColumn('pricing_level_details', 'previous_further_discounted_price')) {
            Schema::table('pricing_level_details', function (Blueprint $table) {
                $table->decimal('previous_further_discounted_price', 15, 2)->nullable()->after('previous_further_discounts');
            });
        }
    }

    public function down(): void
    {
        foreach (['products', 'product_variants'] as $tableName) {
            $columns = collect(['further_discounts', 'further_discounted_price'])
                ->filter(fn ($column) => Schema::hasColumn($tableName, $column))
                ->all();
            if ($columns) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }

        $columns = collect([
            'further_discounts',
            'further_discounted_price',
            'previous_further_discounts',
            'previous_further_discounted_price',
        ])->filter(fn ($column) => Schema::hasColumn('pricing_level_details', $column))->all();
        if ($columns) {
            Schema::table('pricing_level_details', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
