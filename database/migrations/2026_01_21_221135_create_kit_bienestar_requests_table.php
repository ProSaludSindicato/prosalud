<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tabla genérica para solicitudes de entregas de bienestar (kits escolares, desayunos, loncheras, etc.)
     */
    public function up(): void
    {
        Schema::create('wellness_delivery_requests', function (Blueprint $table) {
            $table->id();
            
            // Tipo de entrega (kit_escolar, desayuno, lonchera, etc.)
            $table->string('tipo_entrega', 50)->comment('Tipo de entrega: kit_escolar, desayuno, lonchera, etc.');
            
            // Información del afiliado
            $table->string('documento_afiliado', 50)->comment('Número de documento del afiliado');
            $table->string('nombre_afiliado', 200)->comment('Nombre completo del afiliado');
            $table->string('hospital', 100)->nullable()->comment('Hospital donde realiza actividades');
            $table->string('fecha_expedicion', 20)->comment('Fecha de expedición del documento (dd/mm/aa)');
            
            // Beneficiarios (JSON para soportar múltiples)
            $table->json('beneficiarios')->comment('Array de beneficiarios con nombre, parentesco y edad');
            
            // Firma
            $table->longText('firma')->comment('Firma del afiliado (puede ser base64 de imagen o texto)');
            
            // Trazabilidad
            $table->string('ip_address', 45)->nullable()->comment('IP desde donde se realizó la solicitud');
            $table->text('user_agent')->nullable()->comment('User agent del navegador');
            
            // Estado de la solicitud
            $table->enum('estado', ['pendiente', 'procesado', 'entregado', 'cancelado'])->default('pendiente')->comment('Estado de la solicitud');
            $table->text('observaciones')->nullable()->comment('Observaciones adicionales');
            
            // Timestamps
            $table->timestamps();
            
            // Índices
            $table->index('tipo_entrega');
            $table->index('documento_afiliado');
            $table->index('estado');
            $table->index('created_at');
            
            // Índice compuesto para búsquedas frecuentes
            $table->index(['tipo_entrega', 'estado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellness_delivery_requests');
    }
};
