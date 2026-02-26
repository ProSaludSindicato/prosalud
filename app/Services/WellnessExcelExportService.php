<?php

namespace App\Services;

use App\Models\{WellnessRequest, WellnessActivityRealized};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Log, Storage};
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\{Drawing, Worksheet};
use PhpOffice\PhpSpreadsheet\Chart\{Chart, DataSeries, DataSeriesValues, Legend, PlotArea, Title};

class WellnessExcelExportService
{
    /**
     * Mapeo de estados a etiquetas en español.
     */
    private const STATUS_LABELS = [
        'pending' => 'Pendiente',
        'in_progress' => 'En revisión',
        'resolved' => 'Aprobada',
        'rejected' => 'Rechazada',
    ];

    /**
     * Orden fijo de estados para gráfico de distribución (para colores consistentes).
     */
    private const STATUS_CHART_ORDER = ['Pendiente', 'En revisión', 'Aprobada', 'Rechazada'];

    /**
     * Colores por estado para el gráfico de distribución (Aprobada=verde, Rechazada=rojo, etc.).
     */
    private const STATUS_CHART_COLORS = [
        'Pendiente' => 'FFF2CC',    // Amarillo claro
        'En revisión' => 'DAE3F3', // Azul claro
        'Aprobada' => '70AD47',    // Verde
        'Rechazada' => 'C55A5A',   // Rojo
    ];

    /**
     * Mapeo de centros de costos a etiquetas.
     */
    private const COST_CENTER_LABELS = [
        'Bello' => 'Bello',
        'Rionegro' => 'Rionegro',
        'La Maria asistencial' => 'La María Asistencial',
        'La Maria VIH' => 'La María VIH',
        'La Maria Cosalud' => 'La María Cosalud',
        'La Maria Enterritorio' => 'La María Enterritorio',
        'Admon' => 'Administración',
    ];

    /**
     * Archivos temporales de imágenes para limpiar después de la generación.
     */
    private array $tempImageFiles = [];

    /**
     * Generar reporte Excel con hojas de resumen, detalle y métricas.
     */
    public function generateReport(array $filters, array $options = []): string
    {
        try {
            // Validar filtros
            $this->validateFilters($filters);

            // Obtener solicitudes con filtros aplicados (cargar evidencias si se necesitan imágenes)
            $requests = $this->getRequests($filters, $options);

            // Crear spreadsheet
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0);

            // Crear hoja "Resumen"
            $summarySheet = $spreadsheet->createSheet();
            $summarySheet->setTitle('Resumen');
            $this->buildSummarySheet($summarySheet, $requests, $filters);

            // Crear hoja "Detalle Solicitudes"
            $detailSheet = $spreadsheet->createSheet();
            $detailSheet->setTitle('Detalle Solicitudes');
            $this->buildDetailSheet($detailSheet, $requests);

            // Crear hoja "Actividades Realizadas"
            $realizedSheet = $spreadsheet->createSheet();
            $realizedSheet->setTitle('Actividades Realizadas');
            $this->buildRealizedSheet($realizedSheet, $requests, $options);

            // Crear hoja "Estadísticas por Centro de Costos"
            $costCenterSheet = $spreadsheet->createSheet();
            $costCenterSheet->setTitle('Estadísticas por Centro');
            $this->buildCostCenterStatsSheet($costCenterSheet, $requests);

            // Crear hoja "Estadísticas por Solicitante"
            $requesterSheet = $spreadsheet->createSheet();
            $requesterSheet->setTitle('Estadísticas por Solicitante');
            $this->buildRequesterStatsSheet($requesterSheet, $requests);

            // Establecer primera hoja como activa
            $spreadsheet->setActiveSheetIndex(0);

            // Guardar en archivo temporal
            $tempFile = tempnam(sys_get_temp_dir(), 'wellness_report_') . '.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save($tempFile);

            // Limpiar archivos temporales de imágenes
            $this->cleanupTempFiles();

            return $tempFile;
        } catch (\Exception $e) {
            // Limpiar archivos temporales en caso de error
            $this->cleanupTempFiles();
            
            Log::error('Error generando reporte Excel de bienestar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);
            throw $e;
        }
    }

    /**
     * Validar filtros.
     */
    private function validateFilters(array $filters): void
    {
        if (isset($filters['fecha_desde']) && isset($filters['fecha_hasta'])) {
            $startDate = Carbon::parse($filters['fecha_desde']);
            $endDate = Carbon::parse($filters['fecha_hasta']);

            if ($startDate->gt($endDate)) {
                throw new \InvalidArgumentException('La fecha de inicio debe ser anterior o igual a la fecha de fin.');
            }
        }
    }

    /**
     * Obtener solicitudes con filtros aplicados.
     */
    private function getRequests(array $filters, array $options = []): Collection
    {
        $includeImages = $options['include_images'] ?? false;
        
        $relationships = ['requester', 'details', 'activityRealized'];
        
        // Si se necesitan imágenes, cargar también las evidencias
        if ($includeImages) {
            $relationships[] = 'activityRealized.evidences';
        }
        
        $query = WellnessRequest::with($relationships);

        // Filtro por centro de costos (hospital)
        if (!empty($filters['cost_center'])) {
            $query->where('cost_center', $filters['cost_center']);
        }

        // Filtro por solicitante
        if (!empty($filters['requester_id'])) {
            $query->where('requester_id', $filters['requester_id']);
        }

        // Filtro por estado
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filtro por rango de fechas (fecha propuesta)
        if (!empty($filters['fecha_desde'])) {
            $startDate = Carbon::parse($filters['fecha_desde'])->startOfDay();
            $query->where('proposed_date', '>=', $startDate);
        }

        if (!empty($filters['fecha_hasta'])) {
            $endDate = Carbon::parse($filters['fecha_hasta'])->endOfDay();
            $query->where('proposed_date', '<=', $endDate);
        }

        // Ordenar por fecha propuesta descendente
        $query->orderBy('proposed_date', 'desc');

        return $query->get();
    }

    /**
     * Construir hoja "Resumen".
     */
    private function buildSummarySheet(Worksheet $sheet, Collection $requests, array $filters): void
    {
        $row = 1;

        // Encabezado
        $sheet->setCellValue("A{$row}", 'REPORTE DE ACTIVIDADES DE BIENESTAR PROSALUD');
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $row += 2;

        // Fecha de generación
        $sheet->setCellValue("A{$row}", 'Fecha de generación:');
        $sheet->setCellValue("B{$row}", now()->setTimezone('America/Bogota')->format('d/m/Y H:i:s'));
        $row++;

        // Período
        if (!empty($filters['fecha_desde']) && !empty($filters['fecha_hasta'])) {
            $startDate = Carbon::parse($filters['fecha_desde'])->format('d/m/Y');
            $endDate = Carbon::parse($filters['fecha_hasta'])->format('d/m/Y');
            $sheet->setCellValue("A{$row}", 'Período:');
            $sheet->setCellValue("B{$row}", "{$startDate} - {$endDate}");
            $row++;
        } else {
            $sheet->setCellValue("A{$row}", 'Período:');
            $sheet->setCellValue("B{$row}", 'Todos los registros');
            $row++;
        }

        // Filtros aplicados
        $row++;
        $sheet->setCellValue("A{$row}", 'Filtros aplicados:');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        if (!empty($filters['cost_center'])) {
            $sheet->setCellValue("A{$row}", 'Centro de Costos:');
            $sheet->setCellValue("B{$row}", $this->getCostCenterLabel($filters['cost_center']));
            $row++;
        }

        if (!empty($filters['requester_id'])) {
            $sheet->setCellValue("A{$row}", 'Solicitante ID:');
            $sheet->setCellValue("B{$row}", $filters['requester_id']);
            $row++;
        }

        if (!empty($filters['status'])) {
            $sheet->setCellValue("A{$row}", 'Estado:');
            $sheet->setCellValue("B{$row}", $this->getStatusLabel($filters['status']));
            $row++;
        }

        $row += 2;

        // RESUMEN GENERAL
        $sheet->setCellValue("A{$row}", 'RESUMEN GENERAL');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $stats = $this->calculateSummaryStats($requests);

        $summaryData = [
            ['Métrica', 'Valor'],
            ['Total de solicitudes', $stats['total']],
            ['Solicitudes pendientes', $stats['pending']],
            ['Solicitudes en revisión', $stats['in_progress']],
            ['Solicitudes aprobadas', $stats['resolved']],
            ['Solicitudes rechazadas', $stats['rejected']],
            ['Actividades realizadas', $stats['realized']],
            ['Total de participantes estimados', $stats['total_participants_estimated']],
            ['Total de participantes reales', $stats['total_participants_real']],
            ['Tasa de aprobación', $stats['approval_rate'] . '%'],
            ['Tasa de realización', $stats['realization_rate'] . '%'],
        ];

        $summaryStartRow = $row;
        $sheet->fromArray($summaryData, null, "A{$row}");

        // Aplicar estilo a encabezados de tabla
        $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Aplicar bordes a todas las celdas de datos
        $dataRange = "A{$row}:B" . ($row + count($summaryData) - 1);
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        $row += count($summaryData) + 2;

        // DISTRIBUCIÓN POR CENTRO DE COSTOS
        $sheet->setCellValue("A{$row}", 'DISTRIBUCIÓN POR CENTRO DE COSTOS');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $costCenterDistribution = $this->calculateCostCenterDistribution($requests);

        $distributionData = [['Centro de Costos', 'Cantidad']];
        if (empty($costCenterDistribution)) {
            $distributionData[] = ['No hay datos', 0];
        } else {
            foreach ($costCenterDistribution as $center => $count) {
                $distributionData[] = [$center, $count];
            }
        }

        $sheet->fromArray($distributionData, null, "A{$row}");

        // Aplicar estilo a encabezados de tabla
        $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Aplicar bordes a todas las celdas de datos
        $distributionDataRange = "A{$row}:B" . ($row + count($distributionData) - 1);
        $sheet->getStyle($distributionDataRange)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Ajustar anchos de columna
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(15);

        // Agregar gráficas estratégicas
        $row += count($distributionData) + 2;
        $this->addChartsToSummarySheet($sheet, $requests, $row);
    }

    /**
     * Construir hoja "Detalle Solicitudes".
     */
    private function buildDetailSheet(Worksheet $sheet, Collection $requests): void
    {
        $headers = [
            'ID',
            'Nombre Actividad',
            'Descripción',
            'Centro de Costos',
            'Ubicaciones',
            'Fecha Propuesta',
            'Hora Inicio',
            'Hora Fin',
            'Participantes Estimados',
            'Requiere Detalles',
            'Solicitante',
            'Email Solicitante',
            'Estado',
            'Fecha Creación',
            'Fecha Actualización',
            'Actividad Realizada',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Estilizar encabezados
        $headerRange = 'A1:P1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Ajustar anchos de columna
        $columnWidths = [
            'A' => 10,  // ID
            'B' => 30,  // Nombre Actividad
            'C' => 40,  // Descripción
            'D' => 20,  // Centro de Costos
            'E' => 30,  // Ubicaciones
            'F' => 15,  // Fecha Propuesta
            'G' => 12,  // Hora Inicio
            'H' => 12,  // Hora Fin
            'I' => 20,  // Participantes Estimados
            'J' => 18,  // Requiere Detalles
            'K' => 30,  // Solicitante
            'L' => 30,  // Email Solicitante
            'M' => 15,  // Estado
            'N' => 18,  // Fecha Creación
            'O' => 18,  // Fecha Actualización
            'P' => 18,  // Actividad Realizada
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;

        foreach ($requests as $request) {
            $rowData = [
                $request->id,
                $request->activity_name,
                $request->activity_description ?? '',
                $this->getCostCenterLabel($request->cost_center),
                $request->locations_text,
                $this->formatDate($request->proposed_date),
                $request->start_time ? substr($request->start_time, 0, 5) : '',
                $request->end_time ? substr($request->end_time, 0, 5) : '',
                $request->participant_count ?? 0,
                $request->requires_details ? 'Sí' : 'No',
                $request->requester ? $request->requester->name : 'N/A',
                $request->requester ? $request->requester->email : 'N/A',
                $this->getStatusLabel($request->status),
                $this->formatDate($request->created_at),
                $this->formatDate($request->updated_at),
                $request->activityRealized ? 'Sí' : 'No',
            ];

            $sheet->fromArray([$rowData], null, "A{$row}");

            // Aplicar formato condicional a la columna de estado
            $statusCell = "M{$row}";
            $statusColor = $this->getStatusColor($request->status);
            if ($statusColor) {
                $sheet->getStyle($statusCell)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($statusColor);
            }

            $row++;
        }

        // Aplicar bordes a todas las filas de datos
        if ($row > 2) {
            $dataRange = "A1:P" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        // Agregar autofiltro
        if ($row > 2) {
            $sheet->setAutoFilter("A1:P" . ($row - 1));
        }

        // Congelar primera fila
        $sheet->freezePane('A2');
    }

    /**
     * Construir hoja "Actividades Realizadas".
     */
    private function buildRealizedSheet(Worksheet $sheet, Collection $requests, array $options = []): void
    {
        $includeImages = $options['include_images'] ?? false;
        
        $headers = [
            'ID Solicitud',
            'Nombre Actividad',
            'Centro de Costos',
            'Fecha Propuesta',
            'Fecha Realización',
            'Ubicación Real',
            'Participantes Estimados',
            'Participantes Reales',
            'Diferencia Participantes',
            'Descripción Realización',
            'Souvenir Entregado',
            'Publicado en Galería',
            'Listado Asistencia',
            'Enlace Listado Asistencia',
        ];

        // Agregar columna de imágenes si se solicitan
        if ($includeImages) {
            $headers[] = 'Evidencias (Imágenes)';
        }

        // Calcular rango de encabezados dinámicamente
        $lastColumn = $includeImages ? 'O' : 'N';
        $headerRange = "A1:{$lastColumn}1";

        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '70AD47'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Ajustar anchos de columna
        $columnWidths = [
            'A' => 12,  // ID Solicitud
            'B' => 30,  // Nombre Actividad
            'C' => 20,  // Centro de Costos
            'D' => 15,  // Fecha Propuesta
            'E' => 15,  // Fecha Realización
            'F' => 30,  // Ubicación Real
            'G' => 20,  // Participantes Estimados
            'H' => 18,  // Participantes Reales
            'I' => 20,  // Diferencia Participantes
            'J' => 40,  // Descripción Realización
            'K' => 25,  // Souvenir Entregado
            'L' => 18,  // Publicado en Galería
            'M' => 18,  // Listado Asistencia
            'N' => 50,  // Enlace Listado Asistencia
        ];

        if ($includeImages) {
            $columnWidths['O'] = 35;  // Evidencias (Imágenes)
        }

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;

        foreach ($requests as $request) {
            if (!$request->activityRealized) {
                continue;
            }

            $realized = $request->activityRealized;
            // Diferencia para mostrar: Reales - Estimados → positivo = más asistencia que lo estimado (verde +), negativo = menos (rojo), 0 = iguales
            $estimated = (int) ($request->participant_count ?? 0);
            $real = (int) ($realized->real_attendees_count ?? 0);
            $participantsDiff = $real - $estimated;

            // Generar URL del listado de asistencia
            $listadoUrl = $this->generateListadoAsistenciaUrl($realized);

            $rowData = [
                $request->id,
                $request->activity_name,
                $this->getCostCenterLabel($request->cost_center),
                $this->formatDate($request->proposed_date),
                $this->formatDate($realized->realized_date),
                $realized->real_location ?? '',
                $request->participant_count ?? 0,
                $realized->real_attendees_count ?? 0,
                $participantsDiff, // 0 explícito cuando son iguales
                $realized->realized_description ?? '',
                $realized->gift_delivered ?? 'N/A',
                $realized->published_to_gallery ? 'Sí' : ($realized->published_to_gallery === false ? 'No' : 'N/A'),
                $realized->listado_asistencia_path ? 'Sí' : 'No',
                $listadoUrl,
            ];

            // Agregar columna de imágenes si se solicitan
            if ($includeImages) {
                $rowData[] = ''; // Se llenará después con las imágenes
            }

            $sheet->fromArray([$rowData], null, "A{$row}");

            // Agregar hipervínculo al listado de asistencia si existe
            if ($listadoUrl) {
                $linkCell = "N{$row}";
                $sheet->getCell($linkCell)->getHyperlink()->setUrl($listadoUrl);
                $sheet->getCell($linkCell)->getHyperlink()->setTooltip('Abrir/Descargar listado de asistencia');
                $sheet->getStyle($linkCell)->getFont()->getColor()->setRGB('0000FF');
                $sheet->getStyle($linkCell)->getFont()->setUnderline(true);
            }

            // Formato y color de la diferencia: positivo = verde con +, negativo = rojo, 0 = cero visible
            $diffCell = "I{$row}";
            $sheet->getStyle($diffCell)->getNumberFormat()->setFormatCode('+0;-0;0');
            if ($participantsDiff > 0) {
                $sheet->getStyle($diffCell)->getFont()->getColor()->setRGB('70AD47'); // Verde
            } elseif ($participantsDiff < 0) {
                $sheet->getStyle($diffCell)->getFont()->getColor()->setRGB('C55A5A'); // Rojo
            }

            // Embeber imágenes si se solicitan
            if ($includeImages && $realized->evidences && $realized->evidences->isNotEmpty()) {
                $this->embedEvidenceImages($sheet, $realized, "O{$row}", $row);
            }

            $row++;
        }

        // Aplicar bordes a todas las filas de datos
        if ($row > 2) {
            $dataRange = "A1:{$lastColumn}" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        // Agregar autofiltro
        if ($row > 2) {
            $sheet->setAutoFilter("A1:{$lastColumn}" . ($row - 1));
        }

        // Congelar primera fila
        $sheet->freezePane('A2');
    }

    /**
     * Construir hoja "Estadísticas por Centro de Costos".
     */
    private function buildCostCenterStatsSheet(Worksheet $sheet, Collection $requests): void
    {
        $headers = [
            'Centro de Costos',
            'Total Solicitudes',
            'Pendientes',
            'En Revisión',
            'Aprobadas',
            'Rechazadas',
            'Realizadas',
            'Participantes Estimados',
            'Participantes Reales',
            'Tasa Aprobación',
            'Tasa Realización',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Estilizar encabezados
        $headerRange = 'A1:K1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E74C3C'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Ajustar anchos de columna
        $columnWidths = [
            'A' => 25,  // Centro de Costos
            'B' => 18,  // Total Solicitudes
            'C' => 15,  // Pendientes
            'D' => 15,  // En Revisión
            'E' => 15,  // Aprobadas
            'F' => 15,  // Rechazadas
            'G' => 15,  // Realizadas
            'H' => 22,  // Participantes Estimados
            'I' => 20,  // Participantes Reales
            'J' => 18,  // Tasa Aprobación
            'K' => 18,  // Tasa Realización
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $statsByCostCenter = $this->calculateStatsByCostCenter($requests);

        $row = 2;

        foreach ($statsByCostCenter as $center => $stats) {
            $rowData = [
                $center,
                $stats['total'],
                $stats['pending'],
                $stats['in_progress'],
                $stats['resolved'],
                $stats['rejected'],
                $stats['realized'],
                $stats['total_participants_estimated'],
                $stats['total_participants_real'],
                $stats['approval_rate'] . '%',
                $stats['realization_rate'] . '%',
            ];

            $sheet->fromArray([$rowData], null, "A{$row}");
            $row++;
        }

        // Aplicar bordes a todas las filas de datos
        if ($row > 2) {
            $dataRange = "A1:K" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
            ]);
        }

        // Agregar autofiltro
        if ($row > 2) {
            $sheet->setAutoFilter("A1:K" . ($row - 1));
        }

        // Congelar primera fila
        $sheet->freezePane('A2');
    }

    /**
     * Construir hoja "Estadísticas por Solicitante".
     */
    private function buildRequesterStatsSheet(Worksheet $sheet, Collection $requests): void
    {
        $headers = [
            'Solicitante',
            'Email',
            'Total Solicitudes',
            'Pendientes',
            'En Revisión',
            'Aprobadas',
            'Rechazadas',
            'Realizadas',
            'Participantes Estimados',
            'Participantes Reales',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Estilizar encabezados
        $headerRange = 'A1:J1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '9B59B6'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Ajustar anchos de columna
        $columnWidths = [
            'A' => 30,  // Solicitante
            'B' => 30,  // Email
            'C' => 18,  // Total Solicitudes
            'D' => 15,  // Pendientes
            'E' => 15,  // En Revisión
            'F' => 15,  // Aprobadas
            'G' => 15,  // Rechazadas
            'H' => 15,  // Realizadas
            'I' => 22,  // Participantes Estimados
            'J' => 20,  // Participantes Reales
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $statsByRequester = $this->calculateStatsByRequester($requests);

        $row = 2;

        foreach ($statsByRequester as $requesterName => $stats) {
            $rowData = [
                $requesterName,
                $stats['email'],
                $stats['total'],
                $stats['pending'],
                $stats['in_progress'],
                $stats['resolved'],
                $stats['rejected'],
                $stats['realized'],
                $stats['total_participants_estimated'],
                $stats['total_participants_real'],
            ];

            $sheet->fromArray([$rowData], null, "A{$row}");
            $row++;
        }

        // Aplicar bordes a todas las filas de datos
        if ($row > 2) {
            $dataRange = "A1:J" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
            ]);
        }

        // Agregar autofiltro
        if ($row > 2) {
            $sheet->setAutoFilter("A1:J" . ($row - 1));
        }

        // Congelar primera fila
        $sheet->freezePane('A2');
    }

    /**
     * Calcular estadísticas de resumen.
     */
    private function calculateSummaryStats(Collection $requests): array
    {
        $stats = [
            'total' => $requests->count(),
            'pending' => 0,
            'in_progress' => 0,
            'resolved' => 0,
            'rejected' => 0,
            'realized' => 0,
            'total_participants_estimated' => 0,
            'total_participants_real' => 0,
        ];

        foreach ($requests as $request) {
            $stats[$request->status]++;
            
            if ($request->participant_count) {
                $stats['total_participants_estimated'] += $request->participant_count;
            }

            if ($request->activityRealized) {
                $stats['realized']++;
                if ($request->activityRealized->real_attendees_count) {
                    $stats['total_participants_real'] += $request->activityRealized->real_attendees_count;
                }
            }
        }

        // Calcular tasas
        $stats['approval_rate'] = $stats['total'] > 0 
            ? round(($stats['resolved'] / $stats['total']) * 100, 2) 
            : 0;
        
        $stats['realization_rate'] = $stats['resolved'] > 0 
            ? round(($stats['realized'] / $stats['resolved']) * 100, 2) 
            : 0;

        return $stats;
    }

    /**
     * Calcular distribución por centro de costos.
     */
    private function calculateCostCenterDistribution(Collection $requests): array
    {
        $distribution = [];

        foreach ($requests as $request) {
            $centerLabel = $this->getCostCenterLabel($request->cost_center);
            $distribution[$centerLabel] = ($distribution[$centerLabel] ?? 0) + 1;
        }

        arsort($distribution);
        return $distribution;
    }

    /**
     * Calcular estadísticas por centro de costos.
     */
    private function calculateStatsByCostCenter(Collection $requests): array
    {
        $statsByCenter = [];

        foreach ($requests as $request) {
            $centerLabel = $this->getCostCenterLabel($request->cost_center);

            if (!isset($statsByCenter[$centerLabel])) {
                $statsByCenter[$centerLabel] = [
                    'total' => 0,
                    'pending' => 0,
                    'in_progress' => 0,
                    'resolved' => 0,
                    'rejected' => 0,
                    'realized' => 0,
                    'total_participants_estimated' => 0,
                    'total_participants_real' => 0,
                ];
            }

            $statsByCenter[$centerLabel]['total']++;
            $statsByCenter[$centerLabel][$request->status]++;

            if ($request->participant_count) {
                $statsByCenter[$centerLabel]['total_participants_estimated'] += $request->participant_count;
            }

            if ($request->activityRealized) {
                $statsByCenter[$centerLabel]['realized']++;
                if ($request->activityRealized->real_attendees_count) {
                    $statsByCenter[$centerLabel]['total_participants_real'] += $request->activityRealized->real_attendees_count;
                }
            }
        }

        // Calcular tasas
        foreach ($statsByCenter as $center => &$stats) {
            $stats['approval_rate'] = $stats['total'] > 0 
                ? round(($stats['resolved'] / $stats['total']) * 100, 2) 
                : 0;
            
            $stats['realization_rate'] = $stats['resolved'] > 0 
                ? round(($stats['realized'] / $stats['resolved']) * 100, 2) 
                : 0;
        }

        // Ordenar por total descendente
        uasort($statsByCenter, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return $statsByCenter;
    }

    /**
     * Calcular estadísticas por solicitante.
     */
    private function calculateStatsByRequester(Collection $requests): array
    {
        $statsByRequester = [];

        foreach ($requests as $request) {
            $requesterName = $request->requester ? $request->requester->name : 'N/A';
            $requesterEmail = $request->requester ? $request->requester->email : 'N/A';

            if (!isset($statsByRequester[$requesterName])) {
                $statsByRequester[$requesterName] = [
                    'email' => $requesterEmail,
                    'total' => 0,
                    'pending' => 0,
                    'in_progress' => 0,
                    'resolved' => 0,
                    'rejected' => 0,
                    'realized' => 0,
                    'total_participants_estimated' => 0,
                    'total_participants_real' => 0,
                ];
            }

            $statsByRequester[$requesterName]['total']++;
            $statsByRequester[$requesterName][$request->status]++;

            if ($request->participant_count) {
                $statsByRequester[$requesterName]['total_participants_estimated'] += $request->participant_count;
            }

            if ($request->activityRealized) {
                $statsByRequester[$requesterName]['realized']++;
                if ($request->activityRealized->real_attendees_count) {
                    $statsByRequester[$requesterName]['total_participants_real'] += $request->activityRealized->real_attendees_count;
                }
            }
        }

        // Ordenar por total descendente
        uasort($statsByRequester, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return $statsByRequester;
    }

    /**
     * Agregar gráficas estratégicas a la hoja de resumen.
     */
    private function addChartsToSummarySheet(Worksheet $sheet, Collection $requests, int $startRow): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        // Preparar datos para gráficas
        $statusData = $this->prepareStatusDataForChart($requests);
        $costCenterData = $this->prepareCostCenterDataForChart($requests);
        $monthlyData = $this->prepareMonthlyDataForChart($requests);
        $realizedVsApprovedData = $this->prepareRealizedVsApprovedDataForChart($requests);

        // Gráfica 1: Distribución por Estado (Pastel) - Columna D
        if (!empty($statusData)) {
            $this->addStatusDistributionChart($sheet, $statusData, 'D2', 'I2', 'D17', 'I30');
        }

        // Gráfica 2: Distribución por Centro de Costos (Barras) - Columna K
        if (!empty($costCenterData)) {
            $this->addCostCenterDistributionChart($sheet, $costCenterData, 'K2', 'Q2', 'K17', 'Q30');
        }

        // Gráfica 3: Tendencia Mensual de Solicitudes (Líneas) - Columna D, más abajo
        if (!empty($monthlyData)) {
            $this->addMonthlyTrendChart($sheet, $monthlyData, 'D32', 'I32', 'D47', 'I60');
        }

        // Gráfica 4: Actividades Aprobadas vs Realizadas (Barras) - Columna K, más abajo
        if (!empty($realizedVsApprovedData)) {
            $this->addRealizedVsApprovedChart($sheet, $realizedVsApprovedData, 'K32', 'Q32', 'K47', 'Q60');
        }
    }

    /**
     * Preparar datos de distribución por estado para gráfica.
     * Devuelve los datos en orden fijo para que los colores del gráfico sean consistentes.
     */
    private function prepareStatusDataForChart(Collection $requests): array
    {
        $statusCounts = [
            'Pendiente' => 0,
            'En revisión' => 0,
            'Aprobada' => 0,
            'Rechazada' => 0,
        ];

        foreach ($requests as $request) {
            $statusLabel = $this->getStatusLabel($request->status);
            if (isset($statusCounts[$statusLabel])) {
                $statusCounts[$statusLabel]++;
            }
        }

        // Mantener orden fijo para colores consistentes en el gráfico
        $result = [];
        foreach (self::STATUS_CHART_ORDER as $label) {
            if (($statusCounts[$label] ?? 0) > 0) {
                $result[$label] = $statusCounts[$label];
            }
        }
        return $result;
    }

    /**
     * Preparar datos de distribución por centro de costos para gráfica.
     */
    private function prepareCostCenterDataForChart(Collection $requests): array
    {
        $costCenterCounts = [];

        foreach ($requests as $request) {
            $centerLabel = $this->getCostCenterLabel($request->cost_center);
            $costCenterCounts[$centerLabel] = ($costCenterCounts[$centerLabel] ?? 0) + 1;
        }

        arsort($costCenterCounts);
        return $costCenterCounts;
    }

    /**
     * Preparar datos mensuales para gráfica de tendencia.
     */
    private function prepareMonthlyDataForChart(Collection $requests): array
    {
        $monthlyCounts = [];

        foreach ($requests as $request) {
            if (!$request->proposed_date) {
                continue;
            }

            $month = is_string($request->proposed_date)
                ? Carbon::parse($request->proposed_date)->format('Y-m')
                : $request->proposed_date->format('Y-m');

            $monthlyCounts[$month] = ($monthlyCounts[$month] ?? 0) + 1;
        }

        ksort($monthlyCounts);
        return $monthlyCounts;
    }

    /**
     * Preparar datos de actividades aprobadas vs realizadas.
     */
    private function prepareRealizedVsApprovedDataForChart(Collection $requests): array
    {
        $approved = 0;
        $realized = 0;

        foreach ($requests as $request) {
            if ($request->status === 'resolved') {
                $approved++;
                if ($request->activityRealized) {
                    $realized++;
                }
            }
        }

        return [
            'Aprobadas' => $approved,
            'Realizadas' => $realized,
        ];
    }

    /**
     * Agregar gráfica de distribución por estado (Pastel).
     * Usa orden y colores fijos: Aprobada=verde, Rechazada=rojo, Pendiente=amarillo, En revisión=azul.
     */
    private function addStatusDistributionChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $labels = array_keys($data);
        $values = array_values($data);
        $colors = array_map(fn (string $label) => self::STATUS_CHART_COLORS[$label] ?? 'CCCCCC', $labels);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($labels),
                $labels
            ),
        ];

        $dataSeriesValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($values),
                $values,
                null,
                $colors
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_PIECHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Distribución de Solicitudes por Estado');

        $chart = new Chart(
            'chart_status_distribution',
            $title,
            $legend,
            $plotArea,
            true,
            0
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Agregar gráfica de distribución por centro de costos (Barras).
     */
    private function addCostCenterDistributionChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $centers = array_keys($data);
        $centers = array_map(function ($name) {
            return mb_strlen($name) > 20 ? mb_substr($name, 0, 17) . '...' : $name;
        }, $centers);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($centers),
                $centers
            ),
        ];

        $dataSeriesValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($values),
                $values
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $series->setPlotDirection(DataSeries::DIRECTION_VERTICAL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Solicitudes por Centro de Costos');

        $chart = new Chart(
            'chart_cost_center_distribution',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            new Title('Centro de Costos'),
            new Title('Cantidad')
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Agregar gráfica de tendencia mensual (Líneas).
     */
    private function addMonthlyTrendChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $months = [];
        $values = [];

        foreach ($data as $month => $count) {
            $months[] = Carbon::parse($month . '-01')->format('M Y');
            $values[] = $count;
        }

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Solicitudes']),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($months),
                $months
            ),
        ];

        $dataSeriesValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($values),
                $values
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_LINECHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Tendencia Mensual de Solicitudes');

        $chart = new Chart(
            'chart_monthly_trend',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            new Title('Mes'),
            new Title('Cantidad')
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Agregar gráfica de actividades aprobadas vs realizadas (Barras).
     */
    private function addRealizedVsApprovedChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $labels = array_keys($data);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($labels),
                $labels
            ),
        ];

        $dataSeriesValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($values),
                $values
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $series->setPlotDirection(DataSeries::DIRECTION_VERTICAL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Actividades Aprobadas vs Realizadas');

        $chart = new Chart(
            'chart_realized_vs_approved',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            new Title('Tipo'),
            new Title('Cantidad')
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Obtener etiqueta del estado.
     */
    private function getStatusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Obtener etiqueta del centro de costos.
     */
    private function getCostCenterLabel(string $costCenter): string
    {
        return self::COST_CENTER_LABELS[$costCenter] ?? $costCenter;
    }

    /**
     * Obtener color para el estado.
     */
    private function getStatusColor(string $status): ?string
    {
        $colorMap = [
            'pending' => 'FFF2CC',      // Amarillo claro
            'in_progress' => 'D5E8D4',  // Verde claro
            'resolved' => 'D5E8D4',     // Verde claro
            'rejected' => 'F8CECC',     // Rojo claro
        ];

        return $colorMap[$status] ?? null;
    }

    /**
     * Formatear fecha para Excel.
     */
    private function formatDate($date): string
    {
        if (!$date) {
            return '';
        }

        if (is_string($date)) {
            try {
                $date = Carbon::parse($date);
            } catch (\Exception $e) {
                return '';
            }
        }

        return $date->format('d/m/Y');
    }

    /**
     * Generar URL temporal del listado de asistencia.
     */
    private function generateListadoAsistenciaUrl(WellnessActivityRealized $realized): ?string
    {
        if (!$realized->listado_asistencia_path) {
            return null;
        }

        try {
            // Intentar generar URL temporal del bucket privado
            $storage = Storage::disk('prosalud-private');
            if (method_exists($storage, 'temporaryUrl')) {
                return $storage->temporaryUrl($realized->listado_asistencia_path, now()->addHours(24));
            }
        } catch (\Exception $e) {
            Log::warning('No se pudo generar URL temporal para listado de asistencia', [
                'activity_realized_id' => $realized->id,
                'path' => $realized->listado_asistencia_path,
                'error' => $e->getMessage(),
            ]);

            // Intentar con disco local como fallback
            try {
                $storage = Storage::disk('local');
                if (method_exists($storage, 'temporaryUrl')) {
                    return $storage->temporaryUrl($realized->listado_asistencia_path, now()->addHours(24));
                }
            } catch (\Exception $fallbackError) {
                Log::error('No se pudo generar URL temporal desde disco local', [
                    'error' => $fallbackError->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Embeber imágenes de evidencias en una celda de Excel.
     */
    private function embedEvidenceImages(Worksheet $sheet, WellnessActivityRealized $realized, string $startCell, int $row): void
    {
        if (!$realized->evidences || $realized->evidences->isEmpty()) {
            return;
        }

        $maxImages = 3; // Máximo de imágenes a embebar por fila
        $imageWidth = 100;
        $imageHeight = 75;
        $spacing = 5;

        $imagesEmbedded = 0;
        $currentOffsetX = 5;

        foreach ($realized->evidences->take($maxImages) as $evidence) {
            if ($imagesEmbedded >= $maxImages) {
                break;
            }

            try {
                // Obtener la imagen desde la URL
                $imageUrl = $evidence->image_url;
                if (!$imageUrl) {
                    continue;
                }

                // Descargar la imagen
                $imageContent = @file_get_contents($imageUrl);
                if ($imageContent === false) {
                    Log::warning('No se pudo descargar imagen de evidencia', [
                        'evidence_id' => $evidence->id,
                        'image_url' => $imageUrl,
                    ]);
                    continue;
                }

                // Verificar tamaño (máximo 2MB)
                if (strlen($imageContent) > 2 * 1024 * 1024) {
                    Log::warning('Imagen de evidencia excede el tamaño máximo', [
                        'evidence_id' => $evidence->id,
                        'size' => strlen($imageContent),
                    ]);
                    continue;
                }

                // Detectar tipo de imagen
                $imageInfo = @getimagesizefromstring($imageContent);
                if (!$imageInfo) {
                    continue;
                }

                $mime = $imageInfo['mime'] ?? 'image/jpeg';
                $extension = match ($mime) {
                    'image/png' => 'png',
                    'image/jpeg', 'image/jpg' => 'jpg',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };

                // Crear archivo temporal
                $tempImageFile = tempnam(sys_get_temp_dir(), 'wellness_evidence_') . '.' . $extension;
                file_put_contents($tempImageFile, $imageContent);
                $this->tempImageFiles[] = $tempImageFile;

                // Crear objeto Drawing
                $drawing = new Drawing();
                $drawing->setPath($tempImageFile);
                $drawing->setCoordinates($startCell);
                $drawing->setWidth($imageWidth);
                $drawing->setHeight($imageHeight);
                $drawing->setOffsetX($currentOffsetX);
                $drawing->setOffsetY(5);
                $drawing->setWorksheet($sheet);

                $imagesEmbedded++;
                $currentOffsetX += $imageWidth + $spacing;
            } catch (\Exception $e) {
                Log::warning('Error embebiendo imagen de evidencia', [
                    'evidence_id' => $evidence->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        // Ajustar altura de la fila si hay imágenes
        if ($imagesEmbedded > 0) {
            $sheet->getRowDimension($row)->setRowHeight(max(60, $imageHeight + 10));
        }

        // Si hay más imágenes, agregar nota
        if ($realized->evidences->count() > $maxImages) {
            $sheet->setCellValue($startCell, "({$realized->evidences->count()} imágenes totales, mostrando {$maxImages})");
        }
    }

    /**
     * Limpiar archivos temporales de imágenes.
     */
    private function cleanupTempFiles(): void
    {
        foreach ($this->tempImageFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempImageFiles = [];
    }
}

