<?php

namespace App\Console\Commands;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPdfStorageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DeleteConvenioEmailTrackingCommand extends Command
{
    protected $signature = 'convenios:delete-tracking
                            {ids?* : IDs de registros en convenio_email_tracking}
                            {--documento= : Filtrar por número de documento del afiliado}
                            {--estado= : Filtrar por estado (fallido, enviado, pendiente, verificacion)}
                            {--convenio= : Filtrar por nombre de convenio o sede}
                            {--created-on= : Filtrar por fecha de creación (Y-m-d o Y-m-d H:i)}
                            {--latest : Si hay varios resultados, eliminar solo el más reciente}
                            {--all : Si hay varios resultados, eliminar todos los que coincidan}
                            {--force : Omitir confirmación}
                            {--dry-run : Mostrar lo que se eliminaría sin borrar}
                            {--allow-enviado : Permitir eliminar registros en estado enviado}';

    protected $description = 'Elimina uno o más registros de historial de convenios y sus archivos asociados en almacenamiento privado.';

    public function handle(ConvenioPdfStorageService $convenioPdfStorageService): int
    {
        $records = $this->resolveRecords();

        if (is_int($records)) {
            return $records;
        }

        if ($records->isEmpty()) {
            $this->error('No se encontró ningún registro para eliminar.');

            return self::FAILURE;
        }

        $this->info('Registros seleccionados para eliminar: '.$records->count());
        $this->line('');

        $this->displayRecordsTable($records);

        $this->line('');

        $sentRecords = $records->filter(fn (ConvenioEmailTracking $record): bool => $record->estado === 'enviado');
        if ($sentRecords->isNotEmpty() && ! $this->option('allow-enviado')) {
            $this->error('Hay registros en estado enviado. Use --allow-enviado si realmente desea eliminarlos.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: no se eliminó ningún registro.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Eliminar estos registros y sus archivos asociados?')) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $errors = 0;

        foreach ($records as $record) {
            try {
                $this->deleteRecord($record, $convenioPdfStorageService);
                $deleted++;
                $this->line("Eliminado tracking #{$record->id} ({$record->documento})");
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("Error al eliminar tracking #{$record->id}: {$e->getMessage()}");
                Log::error('Error al eliminar registro de convenio', [
                    'record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->info("Registros eliminados: {$deleted}");
        if ($errors > 0) {
            $this->warn("Errores: {$errors}");
        }

        Log::info('Convenio email tracking records deleted manually', [
            'requested_ids' => collect($this->argument('ids'))->all(),
            'filters' => [
                'documento' => $this->option('documento'),
                'estado' => $this->option('estado'),
                'convenio' => $this->option('convenio'),
                'created_on' => $this->option('created-on'),
            ],
            'deleted' => $deleted,
            'errors' => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, ConvenioEmailTracking>|int
     */
    private function resolveRecords(): Collection|int
    {
        $ids = collect($this->argument('ids') ?? [])
            ->flatMap(fn (string $value): array => array_map('trim', explode(',', $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->map(fn (string $value): int => (int) $value)
            ->unique()
            ->values();

        if ($ids->isNotEmpty()) {
            $records = ConvenioEmailTracking::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get();

            $missingIds = $ids->diff($records->pluck('id'));
            if ($missingIds->isNotEmpty()) {
                $this->warn('IDs no encontrados: '.$missingIds->implode(', '));
            }

            return $records;
        }

        $documento = $this->option('documento');
        if (! is_string($documento) || trim($documento) === '') {
            $this->error('Indique IDs o use --documento= con los filtros deseados.');

            return self::FAILURE;
        }

        $query = ConvenioEmailTracking::query()
            ->where('documento', preg_replace('/[^0-9]/', '', $documento) ?: $documento);

        $this->applyFilters($query);

        $records = $query->orderByDesc('created_at')->orderByDesc('id')->get();

        if ($records->isEmpty()) {
            return $records;
        }

        if ($records->count() === 1) {
            return $records;
        }

        if ($this->option('latest')) {
            return collect([$records->first()])->filter();
        }

        if ($this->option('all')) {
            return $records;
        }

        $this->warn('Se encontraron '.$records->count().' registros con esos filtros.');
        $this->displayRecordsTable($records);
        $this->line('');
        $this->error('Use --latest para eliminar solo el más reciente, --all para eliminar todos, o pase el ID exacto.');

        return self::FAILURE;
    }

    /**
     * @param  Builder<ConvenioEmailTracking>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $estado = $this->option('estado');
        if (is_string($estado) && trim($estado) !== '') {
            $query->where('estado', trim($estado));
        }

        $convenio = $this->option('convenio');
        if (is_string($convenio) && trim($convenio) !== '') {
            $pattern = '%'.trim($convenio).'%';
            $query->where(function (Builder $inner) use ($pattern): void {
                $inner->where('nombre_convenio', 'like', $pattern)
                    ->orWhere('sede', 'like', $pattern);
            });
        }

        $createdOn = $this->option('created-on');
        if (is_string($createdOn) && trim($createdOn) !== '') {
            $parsed = Carbon::parse(trim($createdOn));
            if (strlen(trim($createdOn)) <= 10) {
                $query->whereBetween('created_at', [
                    $parsed->copy()->startOfDay(),
                    $parsed->copy()->endOfDay(),
                ]);
            } else {
                $query->whereBetween('created_at', [
                    $parsed->copy()->startOfMinute(),
                    $parsed->copy()->endOfMinute(),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, ConvenioEmailTracking>  $records
     */
    private function displayRecordsTable(Collection $records): void
    {
        $this->table(
            ['ID', 'Documento', 'Afiliado', 'Convenio', 'Estado', 'Error', 'Creado'],
            $records->map(fn (ConvenioEmailTracking $record): array => [
                'ID' => $record->id,
                'Documento' => $record->documento,
                'Afiliado' => $record->nombre_afiliado,
                'Convenio' => $record->nombre_convenio,
                'Estado' => $record->estado,
                'Error' => $record->error_message ? str($record->error_message)->limit(40)->toString() : '-',
                'Creado' => $record->created_at?->format('Y-m-d H:i:s') ?? '-',
            ])->all(),
        );
    }

    private function deleteRecord(
        ConvenioEmailTracking $record,
        ConvenioPdfStorageService $convenioPdfStorageService,
    ): void {
        $this->deleteOwnedTempPdf($record->ruta_archivo_pdf);
        $convenioPdfStorageService->deleteStoredDirectory($record);
        $record->delete();
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
