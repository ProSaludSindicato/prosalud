<?php

namespace App\Console\Commands;

use App\Models\Vote;
use App\Services\AfiliadoService;
use App\Support\ConvenioRateLimiter;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

class BackfillVoterIdentityOnVotesCommand extends Command
{
    protected $signature = 'votes:backfill-voter-identity
                            {--election= : Limitar a una clave de elección}
                            {--force : Ejecutar sin pedir confirmación (modo no interactivo)}
                            {--dry-run : Solo contar los votos pendientes, sin consultar ProSaNet ni guardar}';

    protected $description = 'Completar el nombre y el cargo de los votantes en votos registrados antes de guardarlos al votar, usando la API de detalle de ProSaNet';

    public function handle(AfiliadoService $afiliadoService): int
    {
        $pendingVotes = Vote::query()
            ->whereNull('voter_full_name')
            ->when($this->option('election'), fn ($query, string $election) => $query->where('candidate_election_key', $election));

        $documents = (clone $pendingVotes)->distinct()->pluck('voter_document_number');

        if ($documents->isEmpty()) {
            $this->info('No hay votos sin nombre de votante.');

            return Command::SUCCESS;
        }

        $this->warn("Votos pendientes: {$pendingVotes->count()} de {$documents->count()} votantes.");

        if ($this->option('dry-run')) {
            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! ($this->input->isInteractive() && $this->confirm('¿Consultar ProSaNet y actualizar los votos?', false))) {
            $this->info('Cancelado. Usa --force para ejecutar sin confirmación.');

            return Command::SUCCESS;
        }

        $stats = ['updated' => 0, 'withPosition' => 0, 'notFound' => 0, 'failed' => 0];

        foreach ($documents as $document) {
            $this->acquireProsanetSlot();
            $voterInfo = $afiliadoService->getVoterAuditInfoFromApi($document);

            if ($voterInfo === false) {
                $stats['failed']++;

                continue;
            }

            if ($voterInfo === null) {
                $stats['notFound']++;
            }

            (clone $pendingVotes)->where('voter_document_number', $document)->get()
                ->each(function (Vote $vote) use ($voterInfo, &$stats): void {
                    $vote->update([
                        'voter_full_name' => $voterInfo['nombre_completo'] ?? $this->legacyStoredName($vote),
                        'voter_position' => $voterInfo['cargo'] ?? null,
                    ]);

                    $stats['updated']++;
                    if ($vote->voter_position !== null) {
                        $stats['withPosition']++;
                    }
                });
        }

        $this->table(['Métrica', 'Valor'], [
            ['Votos actualizados', $stats['updated']],
            ['Votos con cargo', $stats['withPosition']],
            ['Votantes sin datos en ProSaNet (se conserva el nombre guardado)', $stats['notFound']],
            ['Votantes sin respuesta de ProSaNet (pendientes)', $stats['failed']],
        ]);

        if ($stats['failed'] > 0) {
            $this->warn('Vuelve a ejecutar el comando para reintentar los votantes pendientes.');
        }

        return Command::SUCCESS;
    }

    /**
     * Antes de este cambio el formulario de votación guardaba el nombre del afiliado en `voter_position`.
     */
    private function legacyStoredName(Vote $vote): ?string
    {
        $storedName = trim((string) $vote->voter_position);

        return $storedName !== '' ? $storedName : null;
    }

    /**
     * Respetar el límite por minuto de ProSaNet compartido con los demás procesos.
     */
    private function acquireProsanetSlot(): void
    {
        while (ConvenioRateLimiter::tooManyProsanetAttempts()) {
            Sleep::for(max(1, ConvenioRateLimiter::prosanetAvailableIn()))->seconds();
        }

        ConvenioRateLimiter::hitProsanet();
    }
}
