<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->string('size')->nullable();
            $table->string('color_id')->nullable();
            $table->integer('stock')->default(0);
            $table->integer('min_stock')->default(0);
            $table->integer('max_stock')->nullable();
            $table->string('sku')->unique();
            $table->timestamps();

            $table->foreign('product_id')
                ->references('id')
                ->on('inventory_products')
                ->onDelete('cascade');

            $table->foreign('color_id')
                ->references('id')
                ->on('inventory_colors')
                ->onDelete('restrict');

            $table->index(['product_id']);
            $table->index(['sku']);
            $table->index(['stock']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_variants');
    }
};
