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
        Schema::create('request_type_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('request_type')->index();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Índice compuesto para búsquedas eficientes
            $table->index(['request_type', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_type_assignments');
    }
};
