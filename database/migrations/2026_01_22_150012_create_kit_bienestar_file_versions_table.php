<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tabla para versionar los archivos Excel de kits escolares almacenados en S3
     */
    public function up(): void
    {
        Schema::create('kit_bienestar_file_versions', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 255)->comment('Nombre original del archivo');
            $table->string('s3_path', 500)->comment('Ruta completa en S3');
            $table->boolean('is_active')->default(false)->comment('Indica si es la versión activa actual');
            $table->foreignId('uploaded_by_user_id')->nullable()->comment('Usuario que subió el archivo');
            $table->timestamps();
            
            // Índices
            $table->index('is_active');
            $table->index('created_at');
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kit_bienestar_file_versions');
    }
};
