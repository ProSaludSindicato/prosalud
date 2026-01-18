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
        Schema::create('convenio_email_tracking', function (Blueprint $table) {
            $table->id();
            $table->string('documento', 50)->index();
            $table->string('nombre_afiliado', 255);
            $table->string('email_afiliado', 255);
            $table->string('nombre_convenio', 100)->index(); // Ej: RIONEGRO, HLM-COOSALUD
            $table->string('nombre_archivo', 255);
            $table->string('ruta_archivo_pdf', 500);
            $table->timestamp('enviado_at')->nullable();
            $table->enum('estado', ['pendiente', 'enviado', 'fallido'])->default('pendiente')->index();
            $table->text('error_message')->nullable();
            $table->integer('intentos')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // Índices adicionales para búsquedas
            $table->index(['documento', 'nombre_convenio']);
            $table->index('enviado_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('convenio_email_tracking');
    }
};

