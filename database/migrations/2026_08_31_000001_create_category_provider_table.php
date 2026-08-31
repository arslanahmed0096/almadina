<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_provider', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('provider_id');
            $table->integer('category_id');
            $table->timestamps();

            $table->unique(['provider_id', 'category_id'], 'category_provider_provider_category_unique');
            $table->foreign('provider_id', 'category_provider_provider_id_foreign')
                ->references('id')
                ->on('providers')
                ->onUpdate('RESTRICT')
                ->onDelete('CASCADE');
            $table->foreign('category_id', 'category_provider_category_id_foreign')
                ->references('id')
                ->on('categories')
                ->onUpdate('RESTRICT')
                ->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_provider');
    }
};
