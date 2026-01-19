<?php

namespace App\Console\Commands;

// ============================================================================
// CÓDIGO TEMPORAL PARA PRUEBAS - ELIMINAR DESPUÉS DE VERIFICAR FUNCIONAMIENTO
// ============================================================================
// Este comando elimina los registros de prueba de la tabla convenio_email_tracking
// que fueron creados durante las pruebas (email: juanpapabon@gmail.com)
// ============================================================================

use App\Models\ConvenioEmailTracking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanTestConvenioEmailTrackingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:clean-convenio-email-tracking
                            {--force : Skip confirmation prompt}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'TEMPORAL: Clean test data from convenio_email_tracking table. DELETE AFTER TESTING.';

    /**
     * Test email used in test commands
     */
    private const TEST_EMAIL = 'juanpapabon@gmail.com';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('⚠️  CÓDIGO TEMPORAL PARA PRUEBAS - ELIMINAR DESPUÉS DE VERIFICAR');
        $this->line('');
        $this->info('🧹 Limpieza de Datos de Prueba - Convenio Email Tracking');
        $this->line('==========================================================');
        $this->line('');

        // Find all test records
        $testRecords = ConvenioEmailTracking::where('email_afiliado', self::TEST_EMAIL)->get();

        if ($testRecords->isEmpty()) {
            $this->info('✅ No se encontraron registros de prueba para eliminar.');
            $this->line('   Email buscado: ' . self::TEST_EMAIL);
            return 0;
        }

        $totalRecords = $testRecords->count();
        $this->info("📊 Registros de prueba encontrados: {$totalRecords}");
        $this->line('');

        // Show summary by status
        $byStatus = $testRecords->groupBy('estado')->map->count();
        $this->info('📈 Resumen por estado:');
        foreach ($byStatus as $estado => $count) {
            $this->line("   • {$estado}: {$count}");
        }
        $this->line('');

        // Show sample records
        $this->info('📋 Muestra de registros a eliminar (primeros 5):');
        $this->line('');

        $tableData = [];
        foreach ($testRecords->take(5) as $record) {
            $tableData[] = [
                'ID' => $record->id,
                'Documento' => $record->documento,
                'Nombre' => $record->nombre_afiliado,
                'Convenio' => $record->nombre_convenio,
                'Estado' => $record->estado,
                'Fecha' => $record->created_at->format('Y-m-d H:i:s'),
            ];
        }

        $this->table(['ID', 'Documento', 'Nombre', 'Convenio', 'Estado', 'Fecha'], $tableData);

        if ($totalRecords > 5) {
            $this->line("   ... y " . ($totalRecords - 5) . " registros más");
            $this->line('');
        }

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No se eliminarán registros');
            $this->line('');
            $this->info("Se eliminarían {$totalRecords} registros de prueba.");
            return 0;
        }

        // Confirmation
        if (!$this->option('force')) {
            $this->warn("⚠️  Se eliminarán {$totalRecords} registros de prueba de la tabla convenio_email_tracking");
            $this->line('');
            
            if (!$this->confirm('¿Deseas continuar con la eliminación?')) {
                $this->info('❌ Operación cancelada por el usuario.');
                return 0;
            }
        } else {
            $this->info('✅ Modo --force activado, saltando confirmación...');
            $this->line('');
        }

        // Delete records
        $this->info('🗑️  Eliminando registros...');
        $this->line('');

        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();

        $deleted = 0;
        $errors = 0;

        try {
            DB::beginTransaction();

            foreach ($testRecords as $record) {
                try {
                    $record->delete();
                    $deleted++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Error al eliminar registro de prueba', [
                        'record_id' => $record->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $progressBar->advance();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Error durante la eliminación: ' . $e->getMessage());
            Log::error('Error al eliminar registros de prueba', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');

        // Summary
        $this->info('✅ Limpieza completada');
        $this->line('');
        $this->info("📊 Resumen:");
        $this->line("  • Registros eliminados: {$deleted}");
        if ($errors > 0) {
            $this->warn("  • Errores: {$errors}");
        }
        $this->line('');
        $this->warn('⚠️  RECUERDA: Este código es TEMPORAL y debe ser ELIMINADO después de las pruebas.');

        Log::info('Test convenio email tracking records cleaned', [
            'total_found' => $totalRecords,
            'deleted' => $deleted,
            'errors' => $errors,
            'test_email' => self::TEST_EMAIL,
        ]);

        return 0;
    }
}
