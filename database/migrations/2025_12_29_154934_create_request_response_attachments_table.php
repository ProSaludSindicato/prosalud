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
        Schema::create('request_response_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_response_id')->constrained('request_responses')->onDelete('cascade');
            $table->string('path'); // Ruta del archivo en storage
            $table->string('original_name'); // Nombre original del archivo para referencia
            $table->datetime('created_at')->useCurrent();

            $table->index('request_response_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_response_attachments');
    }
};
