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
        Schema::create('wellness_request_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wellness_request_id');
            $table->string('type', 100);
            $table->integer('quantity');
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('wellness_request_id')
                ->references('id')
                ->on('wellness_requests')
                ->onDelete('cascade');

            // Indexes
            $table->index('wellness_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellness_request_details');
    }
};
