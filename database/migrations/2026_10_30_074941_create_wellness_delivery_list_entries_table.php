<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Listado de cupos por campaña: cada fila es un cupo (afiliado o beneficiario) con su cantidad a entregar.
     */
    public function up(): void
    {
        Schema::create('wellness_delivery_list_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wellness_delivery_type_id')->constrained('wellness_delivery_types')->cascadeOnDelete();
            $table->string('documento_afiliado', 50);
            $table->string('clave', 300)->comment('Identificador estable del cupo: documento|beneficiario normalizado');
            $table->string('nombre_afiliado', 200)->nullable();
            $table->string('hospital', 150)->nullable();
            $table->string('beneficiario', 200)->nullable();
            $table->string('parentesco', 50)->nullable();
            $table->string('edad', 10)->nullable();
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->timestamps();

            $table->index(['wellness_delivery_type_id', 'documento_afiliado'], 'wdle_type_documento_index');
            $table->unique(['wellness_delivery_type_id', 'clave'], 'wdle_type_clave_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellness_delivery_list_entries');
    }
};
