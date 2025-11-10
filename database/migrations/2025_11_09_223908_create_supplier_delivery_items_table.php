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
        Schema::create('supplier_delivery_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_delivery_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->integer('quantity');
            $table->integer('received')->default(0);
            $table->timestamps();

            $table->foreign('supplier_delivery_id')
                ->references('id')
                ->on('supplier_deliveries')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('inventory_products')
                ->onDelete('restrict');

            $table->foreign('variant_id')
                ->references('id')
                ->on('inventory_variants')
                ->onDelete('restrict');

            $table->index(['supplier_delivery_id']);
            $table->index(['product_id']);
            $table->index(['variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_delivery_items');
    }
};
