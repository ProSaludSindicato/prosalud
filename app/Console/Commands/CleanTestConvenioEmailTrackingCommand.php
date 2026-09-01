<?php

namespace App\Console\Commands;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanTestConvenioEmailTrackingCommand extends Command
{
    protected $signature = 'convenios:clean-test-tracking
                            {--force : Omitir confirmación}
                            {--dry-run : Mostrar lo que se eliminaría sin borrar}';

    protected $description = 'Elimina registros de convenio marcados como TEST (CONVENIO_DELIVERY_MODE distinto de production) y sus archivos asociados.';

    public function handle(ConvenioPdfStorageService $convenioPdfStorageService): int
    {
        $this->info('Limpieza de registros TEST de convenios');
        $this->line('');

        $testRecords = ConvenioEmailTracking::query()
            ->test()
            ->orderBy('id')
            ->get();

        if ($testRecords->isEmpty()) {
            $this->info('No hay registros TEST para eliminar.');

            return self::SUCCESS;
        }

        $totalRecords = $testRecords->count();
        $this->info("Registros TEST encontrados: {$totalRecords}");
        $this->line('');

        $byStatus = $testRecords->groupBy('estado')->map->count();
        $this->info('Resumen por estado:');
        foreach ($byStatus as $estado => $count) {
            $this->line("  • {$estado}: {$count}");
        }
        $this->line('');

        $tableData = [];
        foreach ($testRecords->take(5) as $record) {
            $tableData[] = [
                'ID' => $record->id,
                'Documento' => $record->documento,
                'Nombre' => $record->nombre_afiliado,
                'Convenio' => $record->nombre_convenio,
                'Estado' => $record->estado,
                'Fecha' => $record->created_at?->format('Y-m-d H:i:s'),
            ];
        }

        $this->table(['ID', 'Documento', 'Nombre', 'Convenio', 'Estado', 'Fecha'], $tableData);

        if ($totalRecords > 5) {
            $this->line('  ... y '.($totalRecords - 5).' registros más');
            $this->line('');
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: no se eliminó ningún registro.');
            $this->info("Se eliminarían {$totalRecords} registros TEST.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("¿Eliminar {$totalRecords} registros TEST y sus archivos?")) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $errors = 0;

        foreach ($testRecords as $record) {
            try {
                $this->deleteOwnedTempPdf($record->ruta_archivo_pdf);
                $convenioPdfStorageService->deleteStoredDirectory($record);
                $record->delete();
                $deleted++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('Error al eliminar registro TEST de convenio', [
                    'record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Registros eliminados: {$deleted}");
        if ($errors > 0) {
            $this->warn("Errores: {$errors}");
        }

        Log::info('Test convenio email tracking records cleaned', [
            'total_found' => $totalRecords,
            'deleted' => $deleted,
            'errors' => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function deleteOwnedTempPdf(?string $path): void
    {
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return;
        }

        $tempDir = realpath(storage_path('app/temp/convenios'));
        $realPath = realpath($path);

        if ($tempDir === false || $realPath === false) {
            return;
        }

        if (! str_starts_with($realPath, $tempDir.DIRECTORY_SEPARATOR) && $realPath !== $tempDir) {
            return;
        }

        @unlink($realPath);
    }
}
