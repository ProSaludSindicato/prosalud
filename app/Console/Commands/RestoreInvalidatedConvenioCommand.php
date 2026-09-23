<?php

namespace App\Console\Commands;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioInvalidationRestoreService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class RestoreInvalidatedConvenioCommand extends Command
{
    protected $signature = 'convenios:restore-invalidated
                            {ids?* : IDs de registros invalidados en convenio_email_tracking}
                            {--documento= : Filtrar por número de documento del afiliado}
                            {--sede= : Filtrar por sede}
                            {--latest : Si hay varios resultados, restaurar solo el más reciente}
                            {--all : Si hay varios resultados, restaurar todos los que coincidan}
                            {--force : Omitir confirmación}
                            {--dry-run : Mostrar lo que se restauraría sin guardar}';

    protected $description = 'Restaura un convenio invalidado por error al estado firmado por el afiliado para volver a firmar con el presidente';

    public function handle(ConvenioInvalidationRestoreService $restoreService): int
    {
        $records = $this->resolveRecords();

        if (is_int($records)) {
            return $records;
        }

        if ($records->isEmpty()) {
            $this->error('No se encontró ningún convenio invalidado para restaurar.');

            return self::FAILURE;
        }

        $this->info('Convenios invalidados seleccionados: '.$records->count());
        $this->line('');
        $this->displayRecordsTable($records);
        $this->line('');

        if ($this->option('dry-run')) {
            foreach ($records as $record) {
                $this->line("DRY RUN: #{$record->id} pasaría de «invalidado» a «firmado afiliado».");
                $this->line('  - Se limpiarían rechazo, firma del presidente y PDF final si existieran.');
                $this->line('  - Se conservaría la firma del afiliado.');
                $this->line('  - Quedaría elegible para firma del presidente: '.($record->pdf_firmado_afiliado_path ? 'sí (si el PDF existe)' : 'no'));
            }

            $this->warn('DRY RUN: no se guardó nada.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Restaurar estos convenios al estado firmado por el afiliado?')) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $restored = 0;
        $errors = 0;

        foreach ($records as $record) {
            try {
                $restoredTracking = $restoreService->restore($record);
                $restored++;
                $this->line("Restaurado tracking #{$restoredTracking->id} ({$restoredTracking->documento}) → firmado afiliado");
            } catch (InvalidArgumentException $exception) {
                $errors++;
                $this->warn("No se pudo restaurar tracking #{$record->id}: {$exception->getMessage()}");
            }
        }

        $this->line('');
        $this->info("Convenios restaurados: {$restored}");

        if ($errors > 0) {
            $this->warn("Errores: {$errors}");

            return self::FAILURE;
        }

        $this->line('');
        $this->comment('Desde el historial de convenios puede solicitar la firma del presidente nuevamente.');

        return self::SUCCESS;
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
                ->where('signing_estado', ConvenioEmailTracking::SIGNING_RECHAZADO)
                ->orderBy('id')
                ->get();

            $missingIds = $ids->diff($records->pluck('id'));
            if ($missingIds->isNotEmpty()) {
                $this->warn('IDs no encontrados o no invalidados: '.$missingIds->implode(', '));
            }

            return $records;
        }

        $documento = $this->option('documento');
        if (! is_string($documento) || trim($documento) === '') {
            $this->error('Indique IDs o use --documento= con los filtros deseados.');

            return self::FAILURE;
        }

        $query = ConvenioEmailTracking::query()
            ->where('signing_estado', ConvenioEmailTracking::SIGNING_RECHAZADO)
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

        $this->warn('Se encontraron '.$records->count().' convenios invalidados con esos filtros.');
        $this->displayRecordsTable($records);
        $this->line('');
        $this->error('Use --latest para restaurar solo el más reciente, --all para restaurar todos, o pase el ID exacto.');

        return self::FAILURE;
    }

    /**
     * @param  Builder<ConvenioEmailTracking>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $sede = $this->option('sede');
        if (is_string($sede) && trim($sede) !== '') {
            $query->where('sede', 'like', '%'.trim($sede).'%');
        }
    }

    /**
     * @param  Collection<int, ConvenioEmailTracking>  $records
     */
    private function displayRecordsTable(Collection $records): void
    {
        $this->table(
            ['ID', 'Documento', 'Afiliado', 'Sede', 'Firmado afiliado', 'Motivo invalidación', 'Invalidado'],
            $records->map(fn (ConvenioEmailTracking $record): array => [
                'ID' => $record->id,
                'Documento' => $record->documento,
                'Afiliado' => $record->nombre_afiliado,
                'Sede' => $record->sede ?? '-',
                'Firmado afiliado' => $record->firmado_afiliado_at?->format('Y-m-d H:i') ?? '-',
                'Motivo invalidación' => $record->motivo_rechazo
                    ? str($record->motivo_rechazo)->limit(40)->toString()
                    : '-',
                'Invalidado' => $record->rechazado_at?->format('Y-m-d H:i') ?? '-',
            ])->all(),
        );
    }
}
