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
        Schema::create('hospital_request_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('hospital_request_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->string('variant_label')->nullable(); // Snapshot: e.g., "M - Azul"
            $table->string('size')->nullable(); // Snapshot
            $table->string('color_id')->nullable(); // Snapshot
            $table->integer('quantity');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('hospital_request_id')
                ->references('id')
                ->on('hospital_requests')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('inventory_products')
                ->onDelete('restrict');

            $table->foreign('variant_id')
                ->references('id')
                ->on('inventory_variants')
                ->onDelete('restrict');

            $table->index(['hospital_request_id']);
            $table->index(['product_id']);
            $table->index(['variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hospital_request_items');
    }
};
