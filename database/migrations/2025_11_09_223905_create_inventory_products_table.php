<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('category_id');
            $table->uuid('subcategory_id')->nullable();
            $table->text('description')->nullable();
            $table->string('variant_mode')->default('simple');
            $table->timestamps();

            $table->foreign('category_id')
                ->references('id')
                ->on('inventory_categories')
                ->onDelete('restrict');

            $table->foreign('subcategory_id')
                ->references('id')
                ->on('inventory_subcategories')
                ->onDelete('restrict');

            $table->index(['name']);
            $table->index(['category_id']);
            $table->index(['subcategory_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_products');
    }
};
