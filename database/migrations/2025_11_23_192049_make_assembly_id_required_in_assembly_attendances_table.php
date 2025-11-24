<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Verificar que no haya asistencias sin assembly_id
        // La creación de la asamblea inicial y asociación de datos se hace mediante comando
        $attendancesWithoutAssembly = DB::table('assembly_attendances')
            ->whereNull('assembly_id')
            ->count();

        if ($attendancesWithoutAssembly > 0) {
            throw new \RuntimeException(
                "No se puede hacer requerido 'assembly_id' en 'assembly_attendances' porque hay {$attendancesWithoutAssembly} asistencia(s) sin asociar. " .
                "Por favor, ejecuta primero el comando: php artisan assembly:create-initial"
            );
        }

        Schema::table('assembly_attendances', function (Blueprint $table) {
            // Make assembly_id required
            $table->string('assembly_id', 255)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assembly_attendances', function (Blueprint $table) {
            $table->string('assembly_id', 255)->nullable()->change();
        });
    }
};
