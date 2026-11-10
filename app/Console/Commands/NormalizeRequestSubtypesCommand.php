<?php

namespace App\Console\Commands;

use App\Constants\RequestSubtypes;
use App\Constants\RequestTypes;
use App\Models\RequestForm;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NormalizeRequestSubtypesCommand extends Command
{
    protected $signature = 'request-forms:normalize-subtypes
                            {--dry-run : Solo mostrar qué valores se estandarizarían, sin guardar}
                            {--force : Ejecutar sin pedir confirmación (obligatorio en producción)}';

    protected $description = 'Estandarizar en base de datos los subtipos de solicitud (payload.solicitudRelacionadaCon) que quedaron guardados con variantes del valor oficial';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo simulación: no se guarda ningún cambio.');
        }

        ['pending' => $pending, 'unrecognized' => $unrecognized] = $this->analyze();

        $this->showUnrecognized($unrecognized);

        if ($pending->isEmpty()) {
            $this->info('No hay subtipos por estandarizar.');

            return Command::SUCCESS;
        }

        $this->table(
            ['Valor actual', 'Valor estándar', 'Solicitudes'],
            $pending->groupBy(fn (array $change): string => $change['from'])
                ->map(fn (Collection $changes, string $from): array => [$from, $changes->first()['to'], $changes->count()])
                ->sortBy(0)
                ->values()
                ->all(),
        );

        if ($dryRun) {
            $this->info("Se estandarizarían {$pending->count()} solicitudes.");

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirmExecution($pending->count())) {
            return app()->isProduction() ? Command::FAILURE : Command::SUCCESS;
        }

        $updated = $pending->sum(fn (array $change): int => (int) $this->applyChange($change['id']));

        $this->info("Solicitudes estandarizadas: {$updated}.");

        return Command::SUCCESS;
    }

    /**
     * Scan requests with subtypes and split stored values into pending changes and unrecognized values.
     *
     * @return array{pending: Collection<int, array{id: string, from: string, to: string}>, unrecognized: Collection<string, int>}
     */
    private function analyze(): array
    {
        $pending = collect();
        $unrecognized = collect();

        RequestForm::query()
            ->whereIn('request_type', RequestTypes::withSubtypes())
            ->select(['id', 'request_type', 'payload'])
            ->chunkById(500, function (Collection $requestForms) use ($pending, $unrecognized): void {
                foreach ($requestForms as $requestForm) {
                    $storedSubtype = $requestForm->payload['solicitudRelacionadaCon'] ?? null;

                    if (! is_string($storedSubtype) || trim($storedSubtype) === '') {
                        continue;
                    }

                    $canonicalSubtype = RequestSubtypes::normalize($storedSubtype);

                    if ($canonicalSubtype === null) {
                        $unrecognized->put($storedSubtype, $unrecognized->get($storedSubtype, 0) + 1);

                        continue;
                    }

                    if ($canonicalSubtype !== $storedSubtype) {
                        $pending->push(['id' => $requestForm->id, 'from' => $storedSubtype, 'to' => $canonicalSubtype]);
                    }
                }
            });

        return ['pending' => $pending, 'unrecognized' => $unrecognized];
    }

    /**
     * Re-read the request under lock and store the canonical subtype without firing model events.
     */
    private function applyChange(string $requestFormId): bool
    {
        return DB::transaction(function () use ($requestFormId): bool {
            $requestForm = RequestForm::query()->lockForUpdate()->find($requestFormId);

            if (! $requestForm) {
                return false;
            }

            $payload = $requestForm->payload ?? [];
            $storedSubtype = $payload['solicitudRelacionadaCon'] ?? null;
            $canonicalSubtype = is_string($storedSubtype) ? RequestSubtypes::normalize($storedSubtype) : null;

            if ($canonicalSubtype === null || $canonicalSubtype === $storedSubtype) {
                return false;
            }

            $payload['solicitudRelacionadaCon'] = $canonicalSubtype;
            $requestForm->payload = $payload;
            $requestForm->saveQuietly();

            Log::info('Subtipo de solicitud estandarizado', [
                'request_id' => $requestForm->id,
                'from' => $storedSubtype,
                'to' => $canonicalSubtype,
            ]);

            return true;
        });
    }

    /**
     * In production a real run requires --force; elsewhere it asks interactively.
     */
    private function confirmExecution(int $pendingCount): bool
    {
        if (app()->isProduction()) {
            $this->error('En producción este comando requiere --force para guardar cambios.');

            return false;
        }

        if ($this->input->isInteractive() && $this->confirm("¿Estandarizar {$pendingCount} solicitudes?", false)) {
            return true;
        }

        $this->info('Cancelado. Usa --force para ejecutar sin confirmación.');

        return false;
    }

    /**
     * @param  Collection<string, int>  $unrecognized
     */
    private function showUnrecognized(Collection $unrecognized): void
    {
        if ($unrecognized->isEmpty()) {
            return;
        }

        $this->warn('Valores no reconocidos (no se modifican, revisar manualmente):');
        $this->table(
            ['Valor', 'Solicitudes'],
            $unrecognized->map(fn (int $count, string $value): array => [$value, $count])->values()->all(),
        );
    }
}
