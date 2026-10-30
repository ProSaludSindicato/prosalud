<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Metadatos del listado cargado para la campaña (modo entrega_listado).
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_types', function (Blueprint $table) {
            $table->string('listado_archivo_nombre', 255)->nullable()->after('modo_acceso');
            $table->timestamp('listado_cargado_at')->nullable()->after('listado_archivo_nombre');
            $table->foreignId('listado_cargado_por')->nullable()->after('listado_cargado_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('listado_cargado_por');
            $table->dropColumn(['listado_archivo_nombre', 'listado_cargado_at']);
        });
    }
};
