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
        Schema::create('document_signing_email_trackings', function (Blueprint $table) {
            $table->id();
            $table->string('envelope_id', 100)->index(); // DocuSign envelope ID
            $table->string('document_number', 50)->index(); // Número de documento del afiliado
            $table->string('recipient_email', 255)->index();
            $table->string('recipient_name', 255);
            $table->string('provider', 50)->default('docusign')->index(); // 'docusign' o 'signnow'
            
            // Estados del correo
            $table->enum('email_status', [
                'pending',      // Pendiente de envío
                'sent',         // Enviado exitosamente
                'delivered',    // Entregado (recibido por el servidor)
                'opened',       // Abierto por el destinatario
                'failed',       // Falló el envío
                'bounced',      // Rebotado
            ])->default('pending')->index();
            
            // Timestamps de eventos
            $table->timestamp('sent_at')->nullable(); // Cuando se envió
            $table->timestamp('delivered_at')->nullable(); // Cuando se entregó
            $table->timestamp('opened_at')->nullable(); // Cuando se abrió
            $table->timestamp('signed_at')->nullable(); // Cuando se firmó (relacionado con DocusignConvenioFirmado)
            
            // Información adicional
            $table->text('error_message')->nullable(); // Mensaje de error si falló
            $table->integer('open_count')->default(0); // Número de veces que se abrió
            $table->json('metadata')->nullable(); // Información adicional (IP, user agent, etc.)
            
            // Para reenvíos
            $table->foreignId('parent_tracking_id')->nullable()->constrained('document_signing_email_trackings')->nullOnDelete();
            $table->integer('resend_count')->default(0); // Número de reenvíos
            
            $table->timestamps();
            
            // Índices adicionales (nombres acortados para evitar límite de MySQL de 64 caracteres)
            $table->index(['document_number', 'email_status'], 'doc_signing_trackings_doc_status_idx');
            $table->index(['envelope_id', 'email_status'], 'doc_signing_trackings_env_status_idx');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_signing_email_trackings');
    }
};
