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
        Schema::create('sst_delivery_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('delivery_id')
                ->constrained('sst_delivery_records')
                ->cascadeOnDelete();

            $table->string('item_id');
            $table->string('item_name');
            $table->string('item_category');
            $table->string('unit')->nullable();
            $table->string('variant_color')->nullable();
            $table->string('variant_size')->nullable();
            $table->json('variant_payload')->nullable();
            $table->unsignedInteger('quantity');

            $table->timestamps();

            $table->index(['item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sst_delivery_items');
    }
};
