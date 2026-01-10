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
        Schema::create('socio_demographic_surveys', function (Blueprint $table) {
            $table->string('id', 10)->primary();
            
            // Datos Básicos
            $table->string('correo');
            $table->string('tipo_documento', 5); // CC, TI, CE, PA, RC, PT
            $table->string('numero_documento');
            $table->string('hospital'); // Código interno del hospital
            $table->string('profesion');
            $table->string('rh', 3)->nullable(); // A+, A-, B+, B-, AB+, AB-, O+, O-
            $table->date('fecha_expedicion')->nullable();
            $table->string('lugar_nacimiento')->nullable();
            $table->string('departamento')->nullable();
            $table->string('celular')->nullable();
            $table->text('direccion')->nullable();
            $table->string('municipio')->nullable();
            $table->string('talla_calzado')->nullable();
            $table->string('talla_vestimenta', 10)->nullable(); // xs, s, m, l, xl, xxl, xxxl, 4xl, 5xl
            $table->string('pais_nacimiento')->default('colombia');
            
            // Contacto de Emergencia
            $table->string('nombre_contacto_emergencia')->nullable();
            $table->string('relacion_contacto_emergencia')->nullable();
            $table->string('telefono_contacto_emergencia')->nullable();
            
            // Información Sociodemográfica (JSON para datos complejos)
            $table->json('datos_sociodemograficos'); // contiene todos los campos sociodemográficos
            
            // Consumo
            $table->json('datos_consumo'); // consumo de licor y cigarrillo
            
            // Condiciones de Salud (JSON para datos complejos)
            $table->json('condiciones_salud');
            
            // Limitaciones Físicas
            $table->json('limitaciones_fisicas');
            
            // Recomendaciones Laborales
            $table->string('recomendacion_restriccion_laboral', 2); // si, no
            $table->text('detalle_recomendacion_laboral')->nullable();
            
            // Firma Digital
            $table->string('firma_path')->nullable(); // Ruta al archivo de firma almacenado
            $table->string('numero_documento_firma');
            
            // Metadata
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            
            // Índices para búsquedas rápidas
            $table->index('tipo_documento');
            $table->index('numero_documento');
            $table->index('hospital');
            $table->index('created_at');
            $table->index(['tipo_documento', 'numero_documento']); // Índice compuesto para búsquedas rápidas (sin unique para permitir múltiples encuestas)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('socio_demographic_surveys');
    }
};
