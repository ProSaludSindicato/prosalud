<?php

namespace App\Console\Commands;

use App\Services\SstDeliveryReportService;
use App\Support\ConvenioRateLimiter;
use Illuminate\Console\Command;

class BackfillSstDeliveryHospitalsCommand extends Command
{
    protected $signature = 'dotacion-epp:backfill-hospitals
                            {--start-date= : Fecha mínima de entrega/devolución (Y-m-d)}
                            {--end-date= : Fecha máxima de entrega/devolución (Y-m-d)}
                            {--document-number= : Limitar a un número de documento}
                            {--limit=300 : Máximo de afiliados distintos a consultar en ProSaNet}
                            {--force : Ejecutar sin pedir confirmación (modo no interactivo)}
                            {--dry-run : Solo contar en base de datos lo que falta, sin consultar ProSaNet ni guardar}';

    protected $description = 'Resolver en ProSaNet y asignar el hospital y el cargo/proceso de las entregas y devoluciones de dotación/EPP que quedaron sin ellos';

    public function handle(SstDeliveryReportService $reportService): int
    {
        $filters = array_filter([
            'startDate' => $this->option('start-date'),
            'endDate' => $this->option('end-date'),
            'documentNumber' => $this->option('document-number'),
        ]);

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo simulación: solo se revisa la base de datos, no se consulta ProSaNet ni se guarda nada.');
        }

        if (! $dryRun && ! $this->option('force') && ! $this->confirmExecution($reportService, $filters, $limit)) {
            return Command::SUCCESS;
        }

        $stats = $reportService->backfillMissingHospitals($filters, $limit, $dryRun, waitForRateLimit: true);

        if ($stats['pendingDocuments'] === 0) {
            $this->info('No hay entregas ni devoluciones sin hospital o cargo asignado.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Afiliados con datos faltantes', $stats['pendingDocuments']],
                    ['Registros sin hospital', $stats['missingHospitalRecords']],
                    ['Líneas de artículos sin hospital (filas del Excel)', $stats['missingHospitalItemLines']],
                    ['Registros sin cargo/proceso', $stats['missingRoleRecords']],
                    ['Consultas a ProSaNet necesarias', min($stats['pendingDocuments'], $limit)],
                ],
            );

            return Command::SUCCESS;
        }

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Afiliados con datos faltantes', $stats['pendingDocuments']],
                ['Afiliados consultados', $stats['processedDocuments']],
                ['Datos resueltos', $stats['resolvedDocuments']],
                ['Sin datos en ProSaNet', $stats['unresolvedDocuments']],
                ['Registros con hospital actualizado', $stats['updatedRecords']],
                ['Registros con cargo/proceso actualizado', $stats['updatedRoleRecords']],
            ],
        );

        $remaining = $stats['pendingDocuments'] - $stats['processedDocuments'];

        if ($remaining > 0) {
            $this->warn("Quedan {$remaining} afiliados por procesar. Vuelve a ejecutar el comando o sube --limit.");
        }

        return Command::SUCCESS;
    }

    /**
     * Show what a real run would do (database only) and ask before calling ProSaNet.
     * Without --force a non-interactive run is cancelled, since confirmation cannot be given.
     *
     * @param  array<string, mixed>  $filters
     */
    private function confirmExecution(SstDeliveryReportService $reportService, array $filters, int $limit): bool
    {
        $preview = $reportService->backfillMissingHospitals($filters, $limit, true);

        if ($preview['pendingDocuments'] === 0) {
            return true;
        }

        $lookups = min($preview['pendingDocuments'], $limit);
        $perMinute = ConvenioRateLimiter::prosanetPerMinute();
        $minutes = (int) ceil($lookups / $perMinute);

        $this->warn("Se consultarán {$lookups} afiliados en ProSaNet (límite de {$perMinute} por minuto, ~{$minutes} min) y se actualizarán registros en la base de datos.");

        if ($this->input->isInteractive() && $this->confirm('¿Continuar?', false)) {
            return true;
        }

        $this->info('Cancelado. Usa --force para ejecutar sin confirmación.');

        return false;
    }
}
