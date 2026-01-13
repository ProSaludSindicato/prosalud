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
        Schema::table('socio_demographic_surveys', function (Blueprint $table) {
            $table->string('hospital')->nullable()->change();
            $table->string('profesion')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('socio_demographic_surveys', function (Blueprint $table) {
            // Nota: No podemos revertir completamente porque podríamos tener registros con null
            // Si es necesario revertir, primero se deberían actualizar los registros null
            $table->string('hospital')->nullable(false)->change();
            $table->string('profesion')->nullable(false)->change();
        });
    }
};
