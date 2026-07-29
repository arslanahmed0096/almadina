<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_levels', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id', true);
            $table->date('date');
            $table->time('time')->nullable();
            $table->integer('brand_id')->index();
            $table->integer('category_id')->index();
            $table->integer('user_id')->index();
            $table->integer('total_products')->default(0);
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('pricing_level_details', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id', true);
            $table->integer('pricing_level_id')->index();
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable()->index();
            $table->decimal('company_rb_price', 15, 2)->default(0);
            $table->decimal('mrp_price', 15, 2)->default(0);
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('fix_price', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->default(0);
            $table->decimal('min_price', 15, 2)->default(0);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_level_details');
        Schema::dropIfExists('pricing_levels');
    }
};
