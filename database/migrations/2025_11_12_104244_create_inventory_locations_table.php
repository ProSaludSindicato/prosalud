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
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('hospital_id')->nullable();
            $table->string('name');
            $table->string('type')->default('warehouse'); // warehouse, hospital, satellite
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('hospital_id')
                ->references('id')
                ->on('hospitals')
                ->onDelete('cascade');

            $table->index('type');
            $table->index('is_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_locations');
    }
};
