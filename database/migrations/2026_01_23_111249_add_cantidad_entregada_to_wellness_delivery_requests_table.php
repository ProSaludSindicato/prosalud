<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Agrega el campo para registrar la cantidad de items entregados (ej: 2 kits escolares para 2 hijos)
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->integer('cantidad_entregada')
                ->nullable()
                ->after('estado')
                ->comment('Cantidad de items entregados (ej: 2 kits escolares para 2 hijos)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->dropColumn('cantidad_entregada');
        });
    }
};
