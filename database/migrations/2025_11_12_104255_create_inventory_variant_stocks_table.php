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
        Schema::create('inventory_variant_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('variant_id');
            $table->uuid('location_id');
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('min_stock')->default(0);
            $table->unsignedInteger('max_stock')->nullable();
            $table->timestamps();

            $table->foreign('variant_id')
                ->references('id')
                ->on('inventory_variants')
                ->onDelete('cascade');

            $table->foreign('location_id')
                ->references('id')
                ->on('inventory_locations')
                ->onDelete('cascade');

            $table->unique(['variant_id', 'location_id'], 'variant_location_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_variant_stocks');
    }
};
