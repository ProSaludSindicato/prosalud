<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tipos de entrega administrables: nombre visible, activo y rango de fechas.
     * Las solicitudes creadas en una fecha usan el tipo activo para esa fecha.
     */
    public function up(): void
    {
        Schema::create('wellness_delivery_types', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200)->comment('Nombre del tipo tal como se muestra al usuario, ej: Detalle día de la mujer');
            $table->boolean('activo')->default(true)->comment('Si está activo, las solicitudes en el rango usan este tipo');
            $table->date('fecha_desde')->comment('Fecha desde la cual aplica este tipo');
            $table->date('fecha_hasta')->comment('Fecha hasta la cual aplica este tipo');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['activo', 'fecha_desde', 'fecha_hasta']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellness_delivery_types');
    }
};
