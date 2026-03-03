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
        Schema::create('vaccination_surveys', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_documento', 5);
            $table->string('numero_documento', 50);
            $table->date('fecha_nacimiento');
            $table->string('primer_nombre', 100)->nullable();
            $table->string('segundo_nombre', 150)->nullable();
            $table->string('primer_apellido', 100)->nullable();
            $table->string('segundo_apellido', 150)->nullable();
            $table->date('fecha_aplicacion_srp')->nullable();
            $table->date('fecha_aplicacion_sr')->nullable();
            $table->date('fecha_aplicacion_fiebre_amarilla')->nullable();
            $table->string('firma_path')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['tipo_documento', 'numero_documento'], 'vaccination_surveys_document_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vaccination_surveys');
    }
};
