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
        Schema::create('inventory_entry_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('entry_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();

            $table->string('product_name');
            $table->string('variant_label')->nullable();

            $table->unsignedInteger('quantity');
            $table->unsignedInteger('previous_stock')->default(0);
            $table->unsignedInteger('new_stock')->default(0);

            $table->timestamps();

            $table->foreign('entry_id')
                ->references('id')
                ->on('inventory_entries')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('inventory_products')
                ->onDelete('restrict');

            $table->foreign('variant_id')
                ->references('id')
                ->on('inventory_variants')
                ->onDelete('restrict');

            $table->index('entry_id');
            $table->index('product_id');
            $table->index('variant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_entry_items');
    }
};
