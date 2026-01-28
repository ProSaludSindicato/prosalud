<?php

namespace App\Services;

use App\Models\WellnessDeliveryRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\{Drawing, Worksheet};
use PhpOffice\PhpSpreadsheet\Chart\{Chart, DataSeries, DataSeriesValues, Legend, PlotArea, Title};

class WellnessDeliveryExcelExportService
{
    /**
     * Archivos temporales de imágenes para limpiar después de la generación.
     */
    private array $tempImageFiles = [];

    /**
     * Generar reporte Excel de entregas de bienestar.
     */
    public function generateReport(array $filters, array $options = []): string
    {
        try {
            // Validar filtros
            $this->validateFilters($filters);

            // Obtener solicitudes con filtros aplicados
            $requests = $this->getRequests($filters);

            // Crear spreadsheet
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0);

            // Crear hoja "Resumen"
            $summarySheet = $spreadsheet->createSheet();
            $summarySheet->setTitle('Resumen');
            $this->buildSummarySheet($summarySheet, $requests, $filters);

            // Crear hoja "Detalle Entregas"
            $detailSheet = $spreadsheet->createSheet();
            $detailSheet->setTitle('Detalle Entregas');
            $this->buildDetailSheet($detailSheet, $requests, $options);

            // Establecer primera hoja como activa
            $spreadsheet->setActiveSheetIndex(0);

            // Guardar en archivo temporal
            $tempFile = tempnam(sys_get_temp_dir(), 'wellness_delivery_report_') . '.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save($tempFile);

            // Limpiar archivos temporales de imágenes
            $this->cleanupTempFiles();

            return $tempFile;
        } catch (\Exception $e) {
            // Limpiar archivos temporales en caso de error
            $this->cleanupTempFiles();
            
            Log::error('Error generando reporte Excel de entregas de bienestar', [
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
    private function getRequests(array $filters): Collection
    {
        $query = WellnessDeliveryRequest::query();

        // Filtro por tipo de entrega
        if (!empty($filters['tipo_entrega'])) {
            $query->porTipoEntrega($filters['tipo_entrega']);
        }

        // Filtro por estado
        if (!empty($filters['estado'])) {
            $query->porEstado($filters['estado']);
        }

        // Filtro por rango de fechas (fecha de creación)
        if (!empty($filters['fecha_desde'])) {
            $startDate = Carbon::parse($filters['fecha_desde'])->startOfDay();
            $query->where('created_at', '>=', $startDate);
        }

        if (!empty($filters['fecha_hasta'])) {
            $endDate = Carbon::parse($filters['fecha_hasta'])->endOfDay();
            $query->where('created_at', '<=', $endDate);
        }

        // Ordenar por fecha de creación descendente
        $query->orderBy('created_at', 'desc');

        // Cargar relación del usuario que realizó la entrega
        return $query->with('entregadoPor')->get();
    }

    /**
     * Construir hoja "Resumen".
     */
    private function buildSummarySheet(Worksheet $sheet, Collection $requests, array $filters): void
    {
        $row = 1;

        // Encabezado
        $sheet->setCellValue("A{$row}", 'REPORTE DE ENTREGAS DE BIENESTAR PROSALUD');
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

        if (!empty($filters['tipo_entrega'])) {
            $sheet->setCellValue("A{$row}", 'Tipo de Entrega:');
            $sheet->setCellValue("B{$row}", WellnessDeliveryRequest::TIPOS_ENTREGA[$filters['tipo_entrega']] ?? $filters['tipo_entrega']);
            $row++;
        }

        if (!empty($filters['estado'])) {
            $sheet->setCellValue("A{$row}", 'Estado:');
            $sheet->setCellValue("B{$row}", WellnessDeliveryRequest::ESTADOS[$filters['estado']] ?? $filters['estado']);
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
            ['Solicitudes pendientes', $stats['pendiente']],
            ['Solicitudes entregadas', $stats['entregado']],
            ['Solicitudes canceladas', $stats['cancelado']],
            ['Total cantidad entregada', $stats['total_cantidad_entregada']],
            ['Total cantidad por entregar (según beneficiarios)', $stats['total_por_entregar']],
            ['Cantidad pendiente por entregar', $stats['total_pendiente_por_entregar']],
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

        // Ajustar anchos de columna
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(15);

        // Agregar gráficas estratégicas usando los datos calculados
        $this->addChartsToSummarySheet($sheet, $requests, $stats);
    }

    /**
     * Construir hoja "Detalle Entregas".
     */
    private function buildDetailSheet(Worksheet $sheet, Collection $requests, array $options = []): void
    {
        $includeFirmas = $options['include_firmas'] ?? false;

        // Definir encabezados base
        $headers = [
            'ID',
            'Tipo de Entrega',
            'Documento Afiliado',
            'Nombre Afiliado',
            'Hospital',
            'Fecha Expedición',
            'Beneficiarios',
            'Estado',
            'Cantidad Entregada',
            'Por entregar',
            'Observaciones',
            'Usuario Entrega',
            'Email Usuario Entrega',
            'IP Address',
            'Fecha Creación',
            'Fecha Actualización',
        ];

        // Agregar columnas de firmas si se solicitan
        if ($includeFirmas) {
            $headers[] = 'Firma Solicitud';
            $headers[] = 'Firma Recibido';
        }

        $sheet->fromArray([$headers], null, 'A1');

        // Calcular última columna
        $lastColumn = $this->getColumnLetter(count($headers));
        $headerRange = "A1:{$lastColumn}1";

        // Estilizar encabezados
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
            'B' => 20,  // Tipo de Entrega
            'C' => 18,  // Documento Afiliado
            'D' => 35,  // Nombre Afiliado
            'E' => 20,  // Hospital
            'F' => 15,  // Fecha Expedición
            'G' => 40,  // Beneficiarios
            'H' => 15,  // Estado
            'I' => 18,  // Cantidad Entregada
            'J' => 15,  // Por entregar
            'K' => 40,  // Observaciones
            'L' => 30,  // Usuario Entrega
            'M' => 30,  // Email Usuario Entrega
            'N' => 18,  // IP Address
            'O' => 18,  // Fecha Creación
            'P' => 18,  // Fecha Actualización
        ];

        if ($includeFirmas) {
            $columnWidths['Q'] = 30;  // Firma Solicitud
            $columnWidths['R'] = 30;  // Firma Recibido
        }

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;

        foreach ($requests as $request) {
            // Formatear beneficiarios
            $beneficiariosText = '';
            if (!empty($request->beneficiarios) && is_array($request->beneficiarios)) {
                $beneficiariosList = [];
                foreach ($request->beneficiarios as $beneficiario) {
                    $nombre = $beneficiario['beneficiario'] ?? $beneficiario['nombre'] ?? '';
                    $parentesco = $beneficiario['parentesco'] ?? '';
                    $edad = $beneficiario['edad'] ?? '';
                    $beneficiariosList[] = trim("{$nombre} ({$parentesco}, {$edad} años)");
                }
                $beneficiariosText = implode('; ', $beneficiariosList);
            }

            // Calcular "Por entregar" como la cantidad de beneficiarios asociados al registro
            $porEntregar = '';
            if (!empty($request->beneficiarios) && is_array($request->beneficiarios)) {
                $porEntregar = count($request->beneficiarios);
            }

            $rowData = [
                $request->id,
                $request->tipo_entrega_text,
                $request->documento_afiliado,
                $request->nombre_afiliado,
                $request->hospital ?? '',
                $request->fecha_expedicion,
                $beneficiariosText,
                $request->estado_text,
                $request->cantidad_entregada ?? '',
                $porEntregar,
                $request->observaciones ?? '',
                $request->entregadoPor ? $request->entregadoPor->name : '',
                $request->entregadoPor ? $request->entregadoPor->email : '',
                $request->ip_address ?? '',
                $this->formatDate($request->created_at),
                $this->formatDate($request->updated_at),
            ];

            // Agregar firmas si se solicitan
            if ($includeFirmas) {
                // Firma de solicitud
                $firmaSolicitud = $request->firma ?? '';
                if (!empty($firmaSolicitud)) {
                    // Si es base64, intentar embeber como imagen
                    if (strpos($firmaSolicitud, 'data:image') === 0 || preg_match('/^[A-Za-z0-9+\/]+=*$/', $firmaSolicitud)) {
                        $rowData[] = 'Ver firma en celda'; // Se embebrá después
                    } else {
                        $rowData[] = $firmaSolicitud; // Texto
                    }
                } else {
                    $rowData[] = '';
                }

                // Firma de recibido
                $firmaRecibido = $request->firma_recibido ?? '';
                if (!empty($firmaRecibido)) {
                    // Si es base64, intentar embeber como imagen
                    if (strpos($firmaRecibido, 'data:image') === 0 || preg_match('/^[A-Za-z0-9+\/]+=*$/', $firmaRecibido)) {
                        $rowData[] = 'Ver firma en celda'; // Se embebrá después
                    } else {
                        $rowData[] = $firmaRecibido; // Texto
                    }
                } else {
                    $rowData[] = '';
                }
            }

            $sheet->fromArray([$rowData], null, "A{$row}");

            // Embeber firmas como imágenes si es necesario
            if ($includeFirmas) {
                $firmaSolicitudCol = $this->getColumnLetter(17); // Columna Q
                $firmaRecibidoCol = $this->getColumnLetter(18); // Columna R

                // Embeber firma de solicitud
                if (!empty($request->firma)) {
                    $this->embedSignatureImage($sheet, $request->firma, "{$firmaSolicitudCol}{$row}", $row);
                }

                // Embeber firma de recibido
                if (!empty($request->firma_recibido)) {
                    $this->embedSignatureImage($sheet, $request->firma_recibido, "{$firmaRecibidoCol}{$row}", $row);
                }
            }

            // Aplicar formato condicional a la columna de estado
            $statusCell = "H{$row}";
            $statusColor = $this->getStatusColor($request->estado);
            if ($statusColor) {
                $sheet->getStyle($statusCell)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($statusColor);
            }

            // Formatear columna de cantidad entregada (alinear a la derecha si tiene valor)
            $cantidadCell = "I{$row}";
            if ($request->cantidad_entregada !== null) {
                $sheet->getStyle($cantidadCell)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
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

        // Agregar autofiltro (filtros en columnas)
        if ($row > 2) {
            $sheet->setAutoFilter("A1:{$lastColumn}" . ($row - 1));
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
            'pendiente' => 0,
            'entregado' => 0,
            'cancelado' => 0,
            'total_cantidad_entregada' => 0,
            'total_por_entregar' => 0,
            'total_pendiente_por_entregar' => 0,
        ];

        foreach ($requests as $request) {
            $stats[$request->estado]++;
            if ($request->estado === 'entregado' && $request->cantidad_entregada !== null) {
                $stats['total_cantidad_entregada'] += $request->cantidad_entregada;
            }
            // Acumular cantidad total "por entregar" basada en los beneficiarios
            if (!empty($request->beneficiarios) && is_array($request->beneficiarios)) {
                $stats['total_por_entregar'] += count($request->beneficiarios);
            }
        }

        // Calcular cantidad pendiente por entregar
        $stats['total_pendiente_por_entregar'] = max(
            0,
            $stats['total_por_entregar'] - $stats['total_cantidad_entregada']
        );

        return $stats;
    }

    /**
     * Agregar gráficas estratégicas a la hoja de resumen.
     *
     * - Progreso de entregas: cantidad entregada vs cantidad pendiente por entregar.
     * - Top usuarios que más han entregado (por cantidad entregada).
     */
    private function addChartsToSummarySheet(Worksheet $sheet, Collection $requests, array $stats): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        // Gráfica 1: Progreso de entregas (Entregado vs Pendiente por entregar)
        if ($stats['total_por_entregar'] > 0) {
            $labels = ['Entregado', 'Pendiente por entregar'];
            $values = [
                (float) $stats['total_cantidad_entregada'],
                (float) $stats['total_pendiente_por_entregar'],
            ];
            $this->addDeliveryProgressChart($sheet, $labels, $values, 'D2', 'I18');
        }

        // Gráfica 2: Top usuarios que más han entregado (por cantidad entregada)
        $delivererTotals = [];

        foreach ($requests as $request) {
            if ($request->estado === 'entregado' && $request->cantidad_entregada !== null && $request->entregadoPor) {
                $responder = $request->entregadoPor;
                $key = $responder->name . ' (' . $responder->email . ')';
                $delivererTotals[$key] = ($delivererTotals[$key] ?? 0) + (int) $request->cantidad_entregada;
            }
        }

        if (!empty($delivererTotals)) {
            // Ordenar y tomar los top 10
            arsort($delivererTotals);
            $topDeliverers = array_slice($delivererTotals, 0, 10, true);
            $this->addTopDeliverersChart($sheet, $topDeliverers, 'D20', 'K36');
        }
    }

    /**
     * Gráfica de progreso de entregas (pastel) usando cantidades entregadas vs pendientes.
     */
    private function addDeliveryProgressChart(Worksheet $sheet, array $labels, array $values, string $topLeft, string $bottomRight): void
    {
        // Evitar gráficas sin datos útiles
        if (array_sum($values) <= 0) {
            return;
        }

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
            DataSeries::TYPE_PIECHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Cantidad entregada vs pendiente por entregar');

        $chart = new Chart(
            'chart_delivery_progress',
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
     * Gráfica de barras con los usuarios que más han entregado.
     */
    private function addTopDeliverersChart(Worksheet $sheet, array $delivererTotals, string $topLeft, string $bottomRight): void
    {
        if (empty($delivererTotals)) {
            return;
        }

        $names = array_keys($delivererTotals);
        // Truncar nombres largos para mejor visualización
        $names = array_map(function ($name) {
            return mb_strlen($name) > 30 ? mb_substr($name, 0, 27) . '...' : $name;
        }, $names);

        $values = array_values($delivererTotals);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($names),
                $names
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
        $title = new Title('Top usuarios que más han entregado (cantidad de kits)');

        $chart = new Chart(
            'chart_top_deliverers',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            new Title('Usuario'),
            new Title('Cantidad entregada')
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Obtener letra de columna desde índice (1-based).
     */
    private function getColumnLetter(int $columnIndex): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
    }

    /**
     * Embeber imagen de firma en una celda de Excel.
     */
    private function embedSignatureImage(Worksheet $sheet, string $firma, string $cell, int $row): void
    {
        try {
            // Extraer base64 si viene en formato data URI
            if (strpos($firma, 'data:image') === 0) {
                $parts = explode(',', $firma, 2);
                if (count($parts) === 2) {
                    $firma = $parts[1];
                }
            }

            // Decodificar base64
            $imageContent = @base64_decode($firma, true);
            if ($imageContent === false) {
                // Si no es base64 válido, tratar como texto
                $sheet->setCellValue($cell, substr($firma, 0, 100));
                return;
            }

            // Verificar tamaño (máximo 1MB por firma)
            if (strlen($imageContent) > 1024 * 1024) {
                $sheet->setCellValue($cell, 'Firma demasiado grande');
                return;
            }

            // Detectar tipo de imagen
            $imageInfo = @getimagesizefromstring($imageContent);
            if (!$imageInfo) {
                $sheet->setCellValue($cell, substr($firma, 0, 100));
                return;
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
            $tempImageFile = tempnam(sys_get_temp_dir(), 'wellness_delivery_signature_') . '.' . $extension;
            file_put_contents($tempImageFile, $imageContent);
            $this->tempImageFiles[] = $tempImageFile;

            // Crear objeto Drawing
            $drawing = new Drawing();
            $drawing->setPath($tempImageFile);
            $drawing->setCoordinates($cell);
            $drawing->setWidth(150);
            $drawing->setHeight(50);
            $drawing->setOffsetX(5);
            $drawing->setOffsetY(5);
            $drawing->setWorksheet($sheet);

            // Ajustar altura de la fila
            $sheet->getRowDimension($row)->setRowHeight(60);
        } catch (\Exception $e) {
            Log::warning('Error embebiendo imagen de firma', [
                'error' => $e->getMessage(),
                'cell' => $cell,
            ]);
            // Si falla, poner texto
            $sheet->setCellValue($cell, 'Error al cargar firma');
        }
    }

    /**
     * Obtener color para el estado.
     */
    private function getStatusColor(string $estado): ?string
    {
        $colorMap = [
            'pendiente' => 'FFF2CC',      // Amarillo claro
            'entregado' => 'C5E0B4',     // Verde más intenso
            'cancelado' => 'F8CECC',     // Rojo claro
        ];

        return $colorMap[$estado] ?? null;
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

        return $date->format('d/m/Y H:i:s');
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

