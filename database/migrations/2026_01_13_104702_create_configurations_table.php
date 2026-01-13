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
        Schema::create('configurations', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Clave única de la configuración');
            $table->text('value')->nullable()->comment('Valor de la configuración (puede ser JSON, string, etc.)');
            $table->string('type')->default('string')->comment('Tipo de dato: string, boolean, integer, json');
            $table->text('description')->nullable()->comment('Descripción de la configuración');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index('key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configurations');
    }
};
