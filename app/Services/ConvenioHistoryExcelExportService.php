<?php

namespace App\Services;

use App\Models\ConvenioEmailTracking;
use App\Support\ConvenioSemesterPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ConvenioHistoryExcelExportService
{
    private const EMAIL_STATUS_LABELS = [
        'pendiente' => 'Pendiente envío',
        'enviado' => 'Enviado',
        'fallido' => 'Fallido',
        'verificacion' => 'Verificación',
    ];

    private const SIGNING_STATUS_LABELS = [
        ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA => 'Pendiente firma',
        ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO => 'Firmado afiliado',
        ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE => 'Firmando presidente',
        ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION => 'Pendiente revisión',
        ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE => 'Error firma presidente',
        ConvenioEmailTracking::SIGNING_COMPLETADO => 'Completado',
        ConvenioEmailTracking::SIGNING_RECHAZADO => 'Rechazado',
    ];

    private const HEADER_FILL = '4472C4';

    private const STATUS_FILLS = [
        ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA => 'FFF2CC',
        ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO => 'DDEBF7',
        ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE => 'DDEBF7',
        ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION => 'E9D5FF',
        ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE => 'F8CBAD',
        ConvenioEmailTracking::SIGNING_COMPLETADO => 'C6EFCE',
        ConvenioEmailTracking::SIGNING_RECHAZADO => 'F8CBAD',
        'fallido' => 'F8CBAD',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function generateReport(array $filters): string
    {
        try {
            $this->validateFilters($filters);

            $digitalSigningEnabled = (bool) config('convenio_signing.enabled', true);
            $trackings = $this->getTrackings($filters, $digitalSigningEnabled);

            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);

            $summarySheet = $spreadsheet->createSheet();
            $summarySheet->setTitle('Resumen');
            $this->buildSummarySheet($summarySheet, $trackings, $filters, $digitalSigningEnabled);

            $detailSheet = $spreadsheet->createSheet();
            $detailSheet->setTitle('Detalle Convenios');
            $this->buildDetailSheet($detailSheet, $trackings, $digitalSigningEnabled);

            if ($digitalSigningEnabled) {
                $pendingSheet = $spreadsheet->createSheet();
                $pendingSheet->setTitle('Pendientes de firma');
                $pendingTrackings = $trackings
                    ->filter(fn (ConvenioEmailTracking $tracking): bool => $tracking->signing_estado === ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA)
                    ->values();
                $this->buildDetailSheet($pendingSheet, $pendingTrackings, true);
            }

            $spreadsheet->setActiveSheetIndex(0);

            $tempFile = tempnam(sys_get_temp_dir(), 'convenio_report_').'.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            return $tempFile;
        } catch (\Exception $e) {
            Log::error('Error generando reporte Excel de convenios', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function validateFilters(array $filters): void
    {
        if (isset($filters['fecha_desde'], $filters['fecha_hasta']) && $filters['fecha_desde'] && $filters['fecha_hasta']) {
            $startDate = Carbon::parse($filters['fecha_desde']);
            $endDate = Carbon::parse($filters['fecha_hasta']);

            if ($startDate->gt($endDate)) {
                throw new \InvalidArgumentException('La fecha desde debe ser anterior o igual a la fecha hasta.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ConvenioEmailTracking>
     */
    private function getTrackings(array $filters, bool $digitalSigningEnabled): Collection
    {
        return ConvenioEmailTracking::query()
            ->with([
                'generatedBy:id,name,email',
                'parentTracking:id,convenio_data',
            ])
            ->applyHistoryFilters($filters, $digitalSigningEnabled)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @param  Collection<int, ConvenioEmailTracking>  $trackings
     * @param  array<string, mixed>  $filters
     */
    private function buildSummarySheet(Worksheet $sheet, Collection $trackings, array $filters, bool $digitalSigningEnabled): void
    {
        $row = 1;
        $sheet->setCellValue("A{$row}", 'REPORTE DE CONVENIOS PROSALUD');
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $row += 2;

        $sheet->setCellValue("A{$row}", 'Fecha de generación:');
        $sheet->setCellValue("B{$row}", now()->setTimezone('America/Bogota')->format('d/m/Y H:i:s'));
        $row++;

        $sheet->setCellValue("A{$row}", 'Período:');
        $sheet->setCellValue("B{$row}", $this->periodLabel($filters));
        $row++;

        $appliedFilters = $this->appliedFiltersLabel($filters, $digitalSigningEnabled);
        if ($appliedFilters !== '') {
            $sheet->setCellValue("A{$row}", 'Filtros aplicados:');
            $sheet->setCellValue("B{$row}", $appliedFilters);
            $row++;
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'RESUMEN GENERAL');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $stats = $this->calculateSummaryStats($trackings, $digitalSigningEnabled);
        $summaryRows = [
            ['Métrica', 'Valor'],
            ['Total de convenios', $stats['total']],
            ['Afiliados únicos', $stats['unique_affiliates']],
            ['Pendiente de envío', $stats['email_pendiente']],
            ['Enviados', $stats['email_enviado']],
            ['Fallidos', $stats['email_fallido']],
            ['Registros TEST', $stats['test']],
        ];

        if ($digitalSigningEnabled) {
            $summaryRows = array_merge($summaryRows, [
                ['Pendientes de firma', $stats['signing_pendiente']],
                ['Firmado por afiliado', $stats['signing_firmado']],
                ['Firma completada', $stats['signing_completado']],
                ['Rechazados', $stats['signing_rechazado']],
            ]);
        }

        $summaryStartRow = $row;
        $sheet->fromArray($summaryRows, null, "A{$row}");
        $this->styleHeaderRange($sheet, "A{$row}:B{$row}");
        $row += count($summaryRows);

        $sheet->getStyle("A{$summaryStartRow}:B".($row - 1))->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        if ($digitalSigningEnabled && $stats['signing_pendiente'] > 0) {
            $pendingMetricRow = $summaryStartRow + 7;
            $sheet->getStyle("A{$pendingMetricRow}:B{$pendingMetricRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB(self::STATUS_FILLS[ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA]);
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'CONVENIOS POR SEDE');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $sedeHeaders = $digitalSigningEnabled
            ? ['Sede', 'Total', 'Pendientes de firma', 'Firmado afiliado', 'Completado', 'Rechazado']
            : ['Sede', 'Total'];
        $sedeHeaderRow = $row;
        $sheet->fromArray([$sedeHeaders], null, "A{$row}");
        $lastSedeCol = Coordinate::stringFromColumnIndex(count($sedeHeaders));
        $this->styleHeaderRange($sheet, "A{$row}:{$lastSedeCol}{$row}");
        $row++;

        $sedeRows = $this->statsBySede($trackings, $digitalSigningEnabled);
        foreach ($sedeRows as $sedeRow) {
            $sheet->fromArray([$sedeRow], null, "A{$row}");
            $row++;
        }

        if ($sedeRows->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'Sin registros');
            $row++;
        }

        $sedeLastRow = $row - 1;
        if ($sedeLastRow >= $sedeHeaderRow) {
            $sheet->getStyle("A{$sedeHeaderRow}:{$lastSedeCol}{$sedeLastRow}")->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
            ]);
            $sheet->setAutoFilter("A{$sedeHeaderRow}:{$lastSedeCol}{$sedeLastRow}");
        }

        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(16);
        $sheet->freezePane('A2');
    }

    /**
     * @param  Collection<int, ConvenioEmailTracking>  $trackings
     */
    private function buildDetailSheet(Worksheet $sheet, Collection $trackings, bool $digitalSigningEnabled): void
    {
        $headers = $this->detailHeaders($digitalSigningEnabled);
        $sheet->fromArray([$headers], null, 'A1');
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $this->styleHeaderRange($sheet, "A1:{$lastCol}1");
        $sheet->getRowDimension(1)->setRowHeight(22);

        $row = 2;
        foreach ($trackings as $tracking) {
            $rowData = $this->detailRow($tracking, $digitalSigningEnabled);
            $sheet->fromArray([$rowData], null, "A{$row}");

            $signingEstado = (string) ($tracking->signing_estado ?? '');
            $emailEstado = (string) $tracking->estado;
            $statusFill = self::STATUS_FILLS[$signingEstado] ?? (self::STATUS_FILLS[$emailEstado] ?? null);
            if ($statusFill !== null) {
                $statusCol = $digitalSigningEnabled ? 'G' : 'F';
                $sheet->getStyle("{$statusCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB($statusFill);
            }

            $row++;
        }

        $lastRow = max(1, $row - 1);
        $sheet->getStyle("A1:{$lastCol}{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
        ]);

        $sheet->setAutoFilter("A1:{$lastCol}{$lastRow}");
        $sheet->freezePane('A2');

        foreach (range(1, count($headers)) as $col) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }
    }

    /**
     * @return list<string>
     */
    private function detailHeaders(bool $digitalSigningEnabled): array
    {
        $headers = [
            'Documento',
            'Nombre afiliado',
            'Email',
            'Convenio',
            'Sede',
            'Estado envío',
        ];

        if ($digitalSigningEnabled) {
            $headers = array_merge($headers, [
                'Estado firma',
                'Pendiente de firmar',
                'Fecha firma afiliado',
                'Fecha firma presidente',
                'Calificación',
                'Integridad',
            ]);
        }

        return array_merge($headers, [
            'Fecha creación',
            'Fecha envío',
            'Intentos',
            'Error envío',
            'Proceso',
            'Fecha inicio convenio',
            'Fecha fin convenio',
            'Celular',
            'TEST',
            'Generado por',
            'PDF original',
            'PDF firmado',
        ]);
    }

    /**
     * @return list<string|int>
     */
    private function detailRow(ConvenioEmailTracking $tracking, bool $digitalSigningEnabled): array
    {
        $row = [
            (string) $tracking->documento,
            (string) $tracking->nombre_afiliado,
            (string) $tracking->email_afiliado,
            (string) $tracking->nombre_convenio,
            (string) ($tracking->sede ?: ''),
            $this->emailStatusLabel((string) $tracking->estado),
        ];

        if ($digitalSigningEnabled) {
            $signingEstado = (string) ($tracking->signing_estado ?? '');
            $row = array_merge($row, [
                $this->signingStatusLabel($signingEstado),
                $signingEstado === ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA ? 'Sí' : 'No',
                $this->formatDateTime($tracking->firmado_afiliado_at),
                $this->formatDateTime($tracking->firmado_presidente_at),
                $tracking->signing_satisfaction_score !== null ? (int) $tracking->signing_satisfaction_score : 'Sin calificar',
                $tracking->resolveIntegrityBadgeLabel() ?? '',
            ]);
        }

        return array_merge($row, [
            $this->formatDateTime($tracking->created_at),
            $this->formatDateTime($tracking->enviado_at),
            (int) $tracking->intentos,
            (string) ($tracking->resolveVisibleErrorMessage() ?? ''),
            $this->convenioDataValue($tracking, 'proceso'),
            $this->formatFlexibleDate($this->convenioDataValue($tracking, 'fecha_inicio')),
            $this->formatFlexibleDate($this->convenioDataValue($tracking, 'fecha_finalizacion')),
            $this->convenioDataValue($tracking, 'celular'),
            $tracking->isTestRecord() ? 'Sí' : 'No',
            (string) ($tracking->generatedBy?->name ?? ''),
            $this->hasOriginalPdf($tracking) ? 'Sí' : 'No',
            $this->hasSignedPdf($tracking) ? 'Sí' : 'No',
        ]);
    }

    /**
     * @param  Collection<int, ConvenioEmailTracking>  $trackings
     * @return array{
     *     total: int,
     *     unique_affiliates: int,
     *     email_pendiente: int,
     *     email_enviado: int,
     *     email_fallido: int,
     *     test: int,
     *     signing_pendiente: int,
     *     signing_firmado: int,
     *     signing_completado: int,
     *     signing_rechazado: int
     * }
     */
    private function calculateSummaryStats(Collection $trackings, bool $digitalSigningEnabled): array
    {
        return [
            'total' => $trackings->count(),
            'unique_affiliates' => $trackings->pluck('documento')->unique()->count(),
            'email_pendiente' => $trackings->where('estado', 'pendiente')->count(),
            'email_enviado' => $trackings->where('estado', 'enviado')->count(),
            'email_fallido' => $trackings->where('estado', 'fallido')->count(),
            'test' => $trackings->filter(fn (ConvenioEmailTracking $tracking): bool => $tracking->isTestRecord())->count(),
            'signing_pendiente' => $digitalSigningEnabled
                ? $trackings->where('signing_estado', ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA)->count()
                : 0,
            'signing_firmado' => $digitalSigningEnabled
                ? $trackings->where('signing_estado', ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO)->count()
                : 0,
            'signing_completado' => $digitalSigningEnabled
                ? $trackings->where('signing_estado', ConvenioEmailTracking::SIGNING_COMPLETADO)->count()
                : 0,
            'signing_rechazado' => $digitalSigningEnabled
                ? $trackings->where('signing_estado', ConvenioEmailTracking::SIGNING_RECHAZADO)->count()
                : 0,
        ];
    }

    /**
     * @param  Collection<int, ConvenioEmailTracking>  $trackings
     * @return Collection<int, list<string|int>>
     */
    private function statsBySede(Collection $trackings, bool $digitalSigningEnabled): Collection
    {
        return $trackings
            ->groupBy(function (ConvenioEmailTracking $tracking): string {
                $sede = trim((string) ($tracking->sede ?? ''));

                return $sede !== '' ? $sede : 'Sin sede';
            })
            ->map(function (Collection $group, string $sede) use ($digitalSigningEnabled): array {
                $row = [$sede, $group->count()];

                if ($digitalSigningEnabled) {
                    $row[] = $group->where('signing_estado', ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA)->count();
                    $row[] = $group->where('signing_estado', ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO)->count();
                    $row[] = $group->where('signing_estado', ConvenioEmailTracking::SIGNING_COMPLETADO)->count();
                    $row[] = $group->where('signing_estado', ConvenioEmailTracking::SIGNING_RECHAZADO)->count();
                }

                return $row;
            })
            ->sortByDesc(fn (array $row): int => (int) $row[1])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function periodLabel(array $filters): string
    {
        $periodo = $filters['periodo'] ?? null;
        $from = $filters['fecha_desde'] ?? null;
        $to = $filters['fecha_hasta'] ?? null;

        $parts = [];

        if (is_string($periodo) && ConvenioSemesterPeriod::isValid($periodo)) {
            $parts[] = ConvenioSemesterPeriod::label($periodo);
        }

        if ($from && $to) {
            $parts[] = Carbon::parse($from)->format('d/m/Y').' - '.Carbon::parse($to)->format('d/m/Y');
        } elseif ($from) {
            $parts[] = 'Desde '.Carbon::parse($from)->format('d/m/Y');
        } elseif ($to) {
            $parts[] = 'Hasta '.Carbon::parse($to)->format('d/m/Y');
        }

        if ($parts === []) {
            return 'Todos los registros';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function appliedFiltersLabel(array $filters, bool $digitalSigningEnabled): string
    {
        $parts = [];

        if (! empty($filters['q'])) {
            $parts[] = 'Búsqueda: '.$filters['q'];
        }

        $estadoFiltro = $filters['estado_filtro'] ?? null;
        if (is_string($estadoFiltro) && $estadoFiltro !== '' && $estadoFiltro !== 'todos') {
            $parts[] = 'Estado: '.$this->estadoFiltroLabel($estadoFiltro, $digitalSigningEnabled);
        }

        if (! empty($filters['sede'])) {
            $parts[] = 'Sede: '.$filters['sede'];
        }

        if (array_key_exists('is_test', $filters) && $filters['is_test'] !== null) {
            $parts[] = filter_var($filters['is_test'], FILTER_VALIDATE_BOOLEAN) ? 'Solo TEST' : 'Sin TEST';
        }

        if (! empty($filters['calificacion'])) {
            $parts[] = 'Calificación: '.$filters['calificacion'];
        }

        return implode(' | ', $parts);
    }

    private function estadoFiltroLabel(string $estadoFiltro, bool $digitalSigningEnabled): string
    {
        $labels = [
            'pendiente' => 'Pendiente de envío',
            'enviado' => 'Enviado',
            'fallido' => 'Fallido',
            'verificacion' => 'TEST',
            'test' => 'TEST',
            'firma_pendiente_firma' => $digitalSigningEnabled ? 'Pendiente de firma' : $estadoFiltro,
            'firma_firmado_afiliado' => $digitalSigningEnabled ? 'Firmado por afiliado' : $estadoFiltro,
            'firma_completado' => $digitalSigningEnabled ? 'Firma completada' : $estadoFiltro,
            'firma_error_presidente' => $digitalSigningEnabled ? 'Error firma presidente' : $estadoFiltro,
        ];

        return $labels[$estadoFiltro] ?? $estadoFiltro;
    }

    private function emailStatusLabel(string $estado): string
    {
        return self::EMAIL_STATUS_LABELS[$estado] ?? $estado;
    }

    private function signingStatusLabel(string $estado): string
    {
        if ($estado === '') {
            return '';
        }

        return self::SIGNING_STATUS_LABELS[$estado] ?? $estado;
    }

    private function convenioDataValue(ConvenioEmailTracking $tracking, string $key): string
    {
        $data = is_array($tracking->convenio_data) ? $tracking->convenio_data : [];
        if ($data === [] && is_array($tracking->parentTracking?->convenio_data)) {
            $data = $tracking->parentTracking->convenio_data;
        }

        $value = $data[$key] ?? '';
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_array($value) || $value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function hasOriginalPdf(ConvenioEmailTracking $tracking): bool
    {
        return filled($tracking->pdf_original_path) || filled($tracking->ruta_archivo_pdf);
    }

    private function hasSignedPdf(ConvenioEmailTracking $tracking): bool
    {
        return filled($tracking->pdf_firmado_afiliado_path) || filled($tracking->pdf_final_path);
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::parse($value)->setTimezone('America/Bogota')->format('d/m/Y H:i');
    }

    private function formatFlexibleDate(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Exception) {
            return $value;
        }
    }

    private function styleHeaderRange(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::HEADER_FILL],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }
}
