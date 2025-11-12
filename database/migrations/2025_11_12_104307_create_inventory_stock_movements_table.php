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
        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('variant_id');
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id')->nullable();
            $table->integer('quantity');
            $table->string('reason')->default('adjustment'); // entry, transfer, hospital_request, return, adjustment
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('moved_at')->useCurrent();
            $table->timestamps();

            $table->foreign('variant_id')
                ->references('id')
                ->on('inventory_variants')
                ->onDelete('cascade');

            $table->foreign('from_location_id')
                ->references('id')
                ->on('inventory_locations')
                ->nullOnDelete();

            $table->foreign('to_location_id')
                ->references('id')
                ->on('inventory_locations')
                ->nullOnDelete();

            $table->index('reason');
            $table->index('moved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_movements');
    }
};
