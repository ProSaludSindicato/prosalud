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
        Schema::create('wellness_requests', function (Blueprint $table) {
            $table->id();
            $table->string('activity_name', 200);
            $table->string('activity_description', 300)->nullable();
            $table->string('cost_center'); // Bello, Rionegro, La Maria, Admon
            $table->json('locations')->nullable(); // Array of locations
            $table->date('proposed_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->integer('participant_count')->nullable();
            $table->boolean('requires_details')->default(false);
            $table->unsignedBigInteger('requester_id');
            $table->string('status')->default('pending'); // pending, in_progress, resolved, rejected
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('requester_id')->references('id')->on('users')->onDelete('restrict');

            // Indexes
            $table->index('requester_id');
            $table->index('status');
            $table->index('proposed_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellness_requests');
    }
};
