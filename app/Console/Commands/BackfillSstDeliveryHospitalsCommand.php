<?php

namespace App\Console\Commands;

use App\Services\SstDeliveryReportService;
use Illuminate\Console\Command;

class BackfillSstDeliveryHospitalsCommand extends Command
{
    protected $signature = 'dotacion-epp:backfill-hospitals
                            {--start-date= : Fecha mínima de entrega/devolución (Y-m-d)}
                            {--end-date= : Fecha máxima de entrega/devolución (Y-m-d)}
                            {--document-number= : Limitar a un número de documento}
                            {--limit=300 : Máximo de afiliados distintos a consultar en ProSaNet}
                            {--dry-run : Mostrar lo que se resolvería sin guardar}';

    protected $description = 'Resolver en ProSaNet y asignar el hospital de las entregas y devoluciones de dotación/EPP que quedaron sin hospital';

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
            $this->warn('Modo simulación: no se guardará ningún cambio.');
        }

        $stats = $reportService->backfillMissingHospitals($filters, $limit, $dryRun);

        if ($stats['pendingDocuments'] === 0) {
            $this->info('No hay entregas ni devoluciones sin hospital asignado.');

            return Command::SUCCESS;
        }

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Afiliados sin hospital', $stats['pendingDocuments']],
                ['Afiliados consultados', $stats['processedDocuments']],
                ['Hospital resuelto', $stats['resolvedDocuments']],
                ['Sin hospital en ProSaNet', $stats['unresolvedDocuments']],
                ['Registros actualizados', $stats['updatedRecords']],
            ],
        );

        $remaining = $stats['pendingDocuments'] - $stats['processedDocuments'];

        if ($remaining > 0) {
            $this->warn("Quedan {$remaining} afiliados por procesar. Vuelve a ejecutar el comando o sube --limit.");
        }

        return Command::SUCCESS;
    }
}
