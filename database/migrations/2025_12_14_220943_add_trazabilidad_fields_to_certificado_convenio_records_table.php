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
        Schema::table('certificado_convenio_records', function (Blueprint $table) {
            // Tipo de certificado: basico, con_actividades, dirigido_afp, bancolombia, subsidio_vivienda, subsidio_desempleo, otros
            $table->string('tipo_certificado', 50)->nullable()->after('generated_at');
            
            // Indica si el certificado tiene valores de compensaciones
            $table->boolean('tiene_compensaciones')->default(false)->after('tipo_certificado');
            
            // Entidad a la que está dirigido el certificado
            $table->string('dirigido_a_entidad', 255)->nullable()->after('tiene_compensaciones');
            
            // Índices para mejorar consultas de métricas (nombres cortos para evitar límite de MySQL)
            $table->index('tipo_certificado', 'idx_tipo_certificado');
            $table->index('tiene_compensaciones', 'idx_tiene_compensaciones');
            $table->index(['tipo_certificado', 'tiene_compensaciones'], 'idx_tipo_compensaciones');
            $table->index(['tipo_certificado', 'generated_at'], 'idx_tipo_fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certificado_convenio_records', function (Blueprint $table) {
            $table->dropIndex('idx_tipo_fecha');
            $table->dropIndex('idx_tipo_compensaciones');
            $table->dropIndex('idx_tiene_compensaciones');
            $table->dropIndex('idx_tipo_certificado');
            
            $table->dropColumn([
                'tipo_certificado',
                'tiene_compensaciones',
                'dirigido_a_entidad',
            ]);
        });
    }
};
