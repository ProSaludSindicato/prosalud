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
        // Verificar que no haya configuraciones de quórum sin assembly_id
        // La creación de la asamblea inicial y asociación de datos se hace mediante comando
        $quorumConfigsWithoutAssembly = DB::table('quorum_config')
            ->whereNull('assembly_id')
            ->count();

        if ($quorumConfigsWithoutAssembly > 0) {
            throw new \RuntimeException(
                "No se puede hacer requerido 'assembly_id' en 'quorum_config' porque hay {$quorumConfigsWithoutAssembly} configuración(es) sin asociar. " .
                "Por favor, ejecuta primero el comando: php artisan assembly:create-initial"
            );
        }

        Schema::table('quorum_config', function (Blueprint $table) {
            // Make assembly_id required
            $table->string('assembly_id', 255)->nullable(false)->change();

            // Add foreign key if not exists
            if (!$this->foreignKeyExists('quorum_config', 'quorum_config_assembly_id_foreign')) {
                $table->foreign('assembly_id')
                    ->references('id')
                    ->on('assemblies')
                    ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quorum_config', function (Blueprint $table) {
            $table->string('assembly_id', 255)->nullable()->change();
        });
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $foreignKeys = DB::select(
            "SELECT CONSTRAINT_NAME 
             FROM information_schema.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = ? 
             AND TABLE_NAME = ? 
             AND CONSTRAINT_NAME = ?",
            [$database, $table, $foreignKey]
        );

        return !empty($foreignKeys);
    }
};
