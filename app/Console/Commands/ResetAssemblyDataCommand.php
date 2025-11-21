<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetAssemblyDataCommand extends Command
{
    protected $signature = 'assembly:reset
                            {--keep-questions : Mantiene preguntas y opciones}
                            {--keep-attendance : Mantiene el registro de asistencia}
                            {--force : Omite confirmaciones}
                            {--dry-run : Muestra lo que se eliminaría sin borrar}';

    protected $description = 'Limpia datos de la funcionalidad de votaciones de asamblea para pruebas o despliegues';

    public function handle(): int
    {
        $this->info('🧹 Limpieza de datos de votaciones de asamblea');
        $this->line('===========================================');

        $tables = [
            'assembly_votes' => 'Votos registrados',
            'question_options' => 'Opciones de preguntas',
            'assembly_questions' => 'Preguntas de asamblea',
            'quorum_config' => 'Configuración de quórum',
            'assembly_attendances' => 'Registro de asistencia de delegados',
        ];

        if ($this->option('keep-questions')) {
            unset($tables['question_options'], $tables['assembly_questions']);
        }

        if ($this->option('keep-attendance')) {
            unset($tables['assembly_attendances']);
        }

        if (empty($tables)) {
            $this->warn('No hay tablas seleccionadas para limpiar (revisa las opciones --keep-*)');

            return Command::SUCCESS;
        }

        $this->table(['Tabla', 'Descripción'], array_map(fn ($description, $table) => [$table, $description], $tables, array_keys($tables)));

        if ($this->option('dry-run')) {
            $this->warn('Modo simulación activo. No se borrará información.');

            return Command::SUCCESS;
        }

        if (!$this->option('force')) {
            if (!$this->confirm('¿Deseas continuar y eliminar permanentemente estos datos?')) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach (array_keys($tables) as $table) {
                DB::table($table)->truncate();
                $this->line("✅ Tabla {$table} limpiada.");
            }
        } catch (\Throwable $e) {
            $this->error('Error durante la limpieza: ' . $e->getMessage());

            return Command::FAILURE;
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        Log::warning('Assembly data reset executed', [
            'tables_cleared' => array_keys($tables),
            'user' => 'artisan_command',
            'timestamp' => now()->toISOString(),
        ]);

        $this->info('🚀 Datos de votaciones de asamblea limpiados correctamente.');

        return Command::SUCCESS;
    }
}

