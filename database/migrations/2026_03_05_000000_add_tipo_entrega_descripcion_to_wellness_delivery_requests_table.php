<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Permite almacenar una descripción legible del producto cuando el tipo de entrega
     * es personalizado (ej: "Detalle Día de la Mujer", "Detalle Día del Médico").
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->string('tipo_entrega_descripcion', 100)->nullable()->after('tipo_entrega');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->dropColumn('tipo_entrega_descripcion');
        });
    }
};
