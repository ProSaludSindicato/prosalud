<?php

namespace App\Console\Commands;

use App\Enums\ConvenioPdfStage;
use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioCompletedEmailService;
use App\Services\ConvenioPdfStorageService;
use App\Services\ConvenioPresidentSignReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class CompleteRejectedConvenioCommand extends Command
{
    protected $signature = 'convenios:complete-rejected
                            {ids?* : IDs de registros en convenio_email_tracking}
                            {--documento= : Filtrar por número de documento del afiliado}
                            {--latest : Si hay varios resultados, completar solo el más reciente}
                            {--all : Si hay varios resultados, completar todos los que coincidan}
                            {--no-email : No enviar el correo de convenio completado al afiliado}
                            {--force : Omitir confirmación}
                            {--dry-run : Mostrar lo que se haría sin guardar}';

    protected $description = 'Marca como completado un convenio rechazado por error en la revisión (conserva el PDF final firmado por el presidente)';

    public function handle(
        ConvenioPdfStorageService $pdfStorage,
        ConvenioCompletedEmailService $completedEmailService,
        ConvenioPresidentSignReviewService $reviewService,
    ): int {
        $records = $this->resolveRecords();

        if (is_int($records)) {
            return $records;
        }

        if ($records->isEmpty()) {
            $this->error('No se encontró ningún convenio rechazado en revisión.');

            return self::FAILURE;
        }

        $this->displayRecordsTable($records);
        $this->line('');

        if ($this->option('dry-run')) {
            foreach ($records as $record) {
                $problem = $this->eligibilityProblem($record, $pdfStorage);
                $this->line($problem === null
                    ? "DRY RUN: #{$record->id} pasaría a «completado»".($this->option('no-email') ? '.' : ' y se enviaría el correo al afiliado.')
                    : "DRY RUN: #{$record->id} NO se puede completar: {$problem}");
            }

            $this->warn('DRY RUN: no se guardó nada.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Marcar estos convenios como completados?')) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $completed = 0;
        $errors = 0;

        foreach ($records as $record) {
            try {
                $problem = $this->eligibilityProblem($record, $pdfStorage);

                if ($problem !== null) {
                    throw new InvalidArgumentException($problem);
                }

                if (! $this->option('no-email')) {
                    try {
                        $completedEmailService->send($record->fresh());
                    } catch (Throwable $exception) {
                        throw new InvalidArgumentException('No se pudo enviar el correo: '.$exception->getMessage());
                    }
                }

                $record->update([
                    'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
                    'rechazado_at' => null,
                    'motivo_rechazo' => null,
                    'president_sign_last_error' => null,
                    'completed_at' => now(),
                ]);

                $reviewService->refreshBatchProgress($record->president_sign_batch_id);

                $completed++;
                $this->line("Completado tracking #{$record->id} ({$record->documento})");
            } catch (InvalidArgumentException $exception) {
                $errors++;
                $this->warn("No se pudo completar tracking #{$record->id}: {$exception->getMessage()}");
            }
        }

        $this->line('');
        $this->info("Convenios completados: {$completed}");

        if ($errors > 0) {
            $this->warn("Errores: {$errors}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function eligibilityProblem(ConvenioEmailTracking $record, ConvenioPdfStorageService $pdfStorage): ?string
    {
        if (! in_array($record->signing_estado, self::rejectedStates(), true)) {
            return 'el convenio no está rechazado en revisión.';
        }

        if (! filled($record->pdf_final_path) || ! $pdfStorage->hasStage($record, ConvenioPdfStage::Final)) {
            return 'no hay PDF final firmado por el presidente en almacenamiento.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function rejectedStates(): array
    {
        return [
            ConvenioEmailTracking::SIGNING_RECHAZADO,
            ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
        ];
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
                ->whereIn('signing_estado', self::rejectedStates())
                ->orderBy('id')
                ->get();

            $missingIds = $ids->diff($records->pluck('id'));
            if ($missingIds->isNotEmpty()) {
                $this->warn('IDs no encontrados o no rechazados: '.$missingIds->implode(', '));
            }

            return $records;
        }

        $documento = $this->option('documento');
        if (! is_string($documento) || trim($documento) === '') {
            $this->error('Indique IDs o use --documento=.');

            return self::FAILURE;
        }

        $records = ConvenioEmailTracking::query()
            ->whereIn('signing_estado', self::rejectedStates())
            ->where('documento', preg_replace('/[^0-9]/', '', $documento) ?: $documento)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($records->count() <= 1) {
            return $records;
        }

        if ($this->option('latest')) {
            return collect([$records->first()]);
        }

        if ($this->option('all')) {
            return $records;
        }

        $this->warn('Se encontraron '.$records->count().' convenios rechazados con ese documento.');
        $this->displayRecordsTable($records);
        $this->error('Use --latest, --all o pase el ID exacto.');

        return self::FAILURE;
    }

    /**
     * @param  Collection<int, ConvenioEmailTracking>  $records
     */
    private function displayRecordsTable(Collection $records): void
    {
        $this->table(
            ['ID', 'Documento', 'Afiliado', 'Estado', 'PDF final', 'Motivo'],
            $records->map(fn (ConvenioEmailTracking $record): array => [
                $record->id,
                $record->documento,
                $record->nombre_afiliado,
                $record->signing_estado,
                filled($record->pdf_final_path) ? 'sí' : 'no',
                str($record->motivo_rechazo ?? $record->president_sign_last_error ?? '-')->limit(40)->toString(),
            ])->all(),
        );
    }
}
