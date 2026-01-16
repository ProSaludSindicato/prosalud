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
        Schema::create('docusign_convenios_firmados', function (Blueprint $table) {
            $table->id();
            $table->string('envelope_id', 100)->unique()->comment('DocuSign envelope ID');
            $table->string('document_number', 50)->comment('Número de documento del afiliado');
            $table->string('recipient_email', 255)->comment('Email del firmante');
            $table->string('recipient_name', 255)->nullable()->comment('Nombre del firmante');
            $table->string('recipient_id', 50)->comment('ID del recipiente en DocuSign');
            $table->timestamp('recipient_completed_at')->nullable()->comment('Fecha/hora cuando el recipiente completó la firma');
            $table->timestamp('envelope_completed_at')->nullable()->comment('Fecha/hora cuando el envelope fue completado');
            $table->string('storage_path')->nullable()->comment('Ruta del PDF firmado en storage');
            $table->string('storage_disk', 50)->default('prosalud-private')->comment('Disco de storage usado');
            $table->enum('status', ['pending', 'recipient_completed', 'completed', 'error'])->default('pending');
            $table->text('error_message')->nullable()->comment('Mensaje de error si hubo algún problema');
            $table->json('metadata')->nullable()->comment('Metadatos adicionales del webhook');
            $table->timestamps();

            // Índices para consultas futuras
            $table->index('document_number');
            $table->index('status');
            $table->index('envelope_completed_at');
            $table->index(['document_number', 'status']);
            $table->index(['status', 'envelope_completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('docusign_convenios_firmados');
    }
};
