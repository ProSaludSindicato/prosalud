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
        Schema::create('hospital_request_timeline', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('hospital_request_id');
            $table->string('status');
            $table->timestamp('timestamp')->useCurrent();
            $table->text('description')->nullable();
            $table->string('actor')->nullable();
            $table->timestamps();

            $table->foreign('hospital_request_id')
                ->references('id')
                ->on('hospital_requests')
                ->onDelete('cascade');

            $table->index(['hospital_request_id']);
            $table->index(['timestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hospital_request_timeline');
    }
};
