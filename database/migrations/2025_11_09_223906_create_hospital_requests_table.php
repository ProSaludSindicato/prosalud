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
        Schema::create('hospital_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('hospital_id');
            $table->string('hospital_name');
            $table->string('requested_by')->nullable();
            $table->string('status')->default('pending');
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->index(['hospital_id']);
            $table->index(['status']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hospital_requests');
    }
};
