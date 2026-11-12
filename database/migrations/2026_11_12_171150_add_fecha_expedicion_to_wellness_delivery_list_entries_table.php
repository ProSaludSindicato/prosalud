<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fecha de expedición (dd/mm/aa) para el listado de campañas en modo "Inscripción con listado".
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_list_entries', function (Blueprint $table) {
            $table->string('fecha_expedicion', 10)->nullable()->after('edad')
                ->comment('dd/mm/aa; si está vacía la persona se valida solo con el documento (modo listado)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_list_entries', function (Blueprint $table) {
            $table->dropColumn('fecha_expedicion');
        });
    }
};
