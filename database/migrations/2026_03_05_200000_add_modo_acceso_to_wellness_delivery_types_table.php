<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * listado = con registro: se debe cargar Excel con lista de afiliados permitidos y validar al reclamar.
     * abierto = público: cualquier afiliado puede reclamar sin listado.
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_types', function (Blueprint $table) {
            $table->string('modo_acceso', 20)->default('listado')->after('activo')
                ->comment('listado: requiere Excel con lista de permitidos; abierto: cualquier afiliado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_types', function (Blueprint $table) {
            $table->dropColumn('modo_acceso');
        });
    }
};
