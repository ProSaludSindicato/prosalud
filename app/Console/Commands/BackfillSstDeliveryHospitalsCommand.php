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
}
