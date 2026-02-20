<?php

namespace App\Services;

use App\Constants\RequestStatuses;
use App\Constants\RequestTypes;
use App\Models\RequestForm;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Chart\{Chart, DataSeries, DataSeriesValues, Legend, PlotArea, Title};

class RequestExcelExportService
{
    /**
     * Mapeo de tipos de solicitud a etiquetas en español.
     */
    private const REQUEST_TYPE_LABELS = [
        RequestTypes::CERTIFICADO_CONVENIO => 'Certificado de Convenio',
        RequestTypes::COMPENSACION_ANUAL => 'Compensación Anual Diferida',
        RequestTypes::COMPENSACION_DESCANSO => 'Compensación por Descanso',
        RequestTypes::VERIFICACION_PAGOS => 'Verificación de Pagos',
        RequestTypes::SOLICITUD_RETIRO_SINDICAL => 'Retiro Sindical',
        RequestTypes::ACTUALIZAR_DATOS_PERSONALES => 'Actualizar Datos Personales',
        RequestTypes::SOLICITUD_MICROCREDITO => 'Microcrédito CEII',
        RequestTypes::INCAPACIDADES_LICENCIAS => 'Incapacidades y Licencias',
        // Alias para compatibilidad con datos antiguos
        'retiro-sindical' => 'Retiro Sindical',
        // Tipos adicionales mencionados en la documentación
        'permisos-turnos' => 'Permisos y Cambio de Turnos',
        'solicitud-bienestar' => 'Solicitud de Bienestar',
    ];

    /**
     * Mapeo de estados a etiquetas en español.
     */
    private const STATUS_LABELS = [
        'pending' => 'Pendiente',
        'in_progress' => 'En Proceso',
        'resolved' => 'Resuelto',
        'rejected' => 'Rechazado',
        RequestStatuses::PENDING => 'Pendiente',
        RequestStatuses::IN_REVIEW => 'En Proceso',
        RequestStatuses::COMPLETED => 'Resuelto',
        RequestStatuses::REJECTED => 'Rechazado',
        'processed' => 'Procesado',
    ];

    /**
     * Mapeo de razones de rechazo técnicas a etiquetas amigables.
     */
    private const REJECTION_REASON_LABELS = [
        'compensacion_pignorada_libranza' => 'Compensación pignorada por libranza',
        'anexos_no_validos' => 'Los anexos adjuntos no son válidos para la solicitud',
        'formato_archivos' => 'Los archivos adjuntos no cumplen con el formato de ProSalud',
        'no_aplica_otros_certificado' => 'No aplica la opción de "Otros" para el certificado de convenio',
        'no_cumple_causales_retiro' => 'No cumple con las causales para el retiro (Vivienda / Educación)',
        'no_vb_coordinadora' => 'No cuenta con el V°B de la coordinadora',
        'sin_capacidad_endeudamiento' => 'No tiene capacidad de endeudamiento',
        'sin_evidencias' => 'No anexa evidencias de la solicitud',
        'sin_tiempo_provisionado' => 'No cuenta con el tiempo provisionado',
        'solicitud_repetida' => 'Solicitud repetida',
    ];

    /**
     * Mapeo de códigos de hospital a nombres completos para generación de certificados.
     */
    private const HOSPITAL_NAME_MAPPINGS = [
        'ABEJORRAL' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
        'ABEJORRAL - ADMON' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
        'ABEJORRAL - ADMON ' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
        'ABEJORRAL - ASIST' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
        'ABEJORRAL - BUEN COMIENZO' => 'E.S.E. Hospital San Juan de Dios Abejorral - Programa Buen Comienzo',
        'ABEJORRAL - CBA' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
        'ABEJORRAL - SALUD P' => 'E.S.E. Hospital San Juan de Dios Abejorral - Programa Salud Pública',
        'ABEJORRAL SP' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
        'ADMON' => 'Sede Administrativa',
        'ADMON-HSJDRionegro' => 'E.S.E. Hospital San Juan de Dios - Rionegro',
        'BARBOSA' => 'E.S.E. Hospital San Vicente de Paul de Barbosa (Ant)',
        'BELLO' => 'E.S.E. Hospital Marco Fidel Suarez de Bello',
        'BETANIA' => 'E.S.E. Hospital San Antonio de Betania',
        'CALDAS' => 'E.S.E. Hospital San Vicente de Paúl de Caldas',
        'CENTRO NEUROLOGICO' => 'Centro Neurológico',
        'CISNEROS' => 'E.S.E. Hospital San Antonio - Cisneros (Ant)',
        'CIUDAD BOLIVAR' => 'E.S.E. Hospital La Merced - Ciudad Bolivar (Ant)',
        'CIUDADBOLIVAR' => 'E.S.E. Hospital La Merced - Ciudad Bolivar (Ant)',
        'E.S.E CARISMA ADMON ' => 'E.S.E. Hospital Carisma',
        'E.S.E CARISMA ASISTENCIAL' => 'E.S.E. Hospital Carisma',
        'E.S.ECARISMA' => 'E.S.E. Hospital Carisma',
        'FREDONIA' => 'E.S.E. Hospital Santa Lucia - Fredonia (Ant)',
        'HGM SEDE 80 ADMON' => 'E.S.E. Hospital General de Medellín - Sede 80',
        'HGM SEDE 80 ASISTENCIAL' => 'E.S.E. Hospital General de Medellín - Sede 80',
        'HGM SEDE 80 ASISTENCIAL ' => 'E.S.E. Hospital General de Medellín - Sede 80',
        'HLM - GRUPO 1' => 'E.S.E. Hospital La María',
        'HLM - GRUPO 2' => 'E.S.E. Hospital La María',
        'HLM - GRUPO 3' => 'E.S.E. Hospital La María',
        'HMFS - BELLO' => 'E.S.E. Hospital Marco Fidel Suarez de Bello',
        'HSJD Rionegro - ADMON' => 'E.S.E. Hospital San Juan de Dios - Rionegro',
        'HSJD Rionegro - ASISTENCIAL' => 'Centro Neurológico',
        'HSJD Rionegro - PIC ' => 'E.S.E. Hospital San Antonio - Cisneros (Ant)',
        'HSJDRionegro' => 'E.S.E. Hospital San Juan de Dios - Rionegro',
        'HSRI' => 'E.S.E. Hospital San Rafael de Itagüí',
        'HSRI ' => 'E.S.E. Hospital San Rafael de Itagüí',
        'JARDIN' => 'E.S.E. Hospital Gabriel Peláez Montoya',
        'LA MARIA' => 'E.S.E. Hospital La María',
        'LA MARIA - 000065-2021' => 'E.S.E. Hospital La María',
        'LA MARIA - 262-2021' => 'E.S.E. Hospital La María',
        'LA MARIA - COOSALUD' => 'E.S.E. Hospital La María',
        'LA MARIA - ENTERRITORIO' => 'E.S.E. Hospital La María',
        'LA MARIA - ENTERRITORIO 1 - 044' => 'E.S.E. Hospital La María',
        'LA MARIA - ENTERRITORIO 2' => 'E.S.E. Hospital La María',
        'LA MARIA - ENTERRITORIO 2 - 045' => 'E.S.E. Hospital La María',
        'LA MARIA - INFECCIOSA PS 268' => 'E.S.E. Hospital La María',
        'LA MARIA - ITS 257' => 'E.S.E. Hospital La María',
        'LA MARIA - PROGRAMA ESPECIAL SAVIA SALUD EPS - VIH-SIDA' => 'E.S.E. Hospital La María',
        'LA MARIA - TRANSMISIBLES' => 'E.S.E. Hospital La María',
        'LA MARIA - TRANSMISIBLES - 122 - 2023' => 'E.S.E. Hospital La María',
        'LA MARIA - TRANSMISIBLES 176' => 'E.S.E. Hospital La María',
        'LA MARIA - UNION TEMPORAL' => 'E.S.E. Hospital La María',
        'LA MARIA - UNION TEMPORAL 020 - 2023' => 'E.S.E. Hospital La María',
        'LA MARIA - VIH' => 'E.S.E. Hospital La María',
        'LA MARIA - VIH - 1' => 'E.S.E. Hospital La María',
        'LA MARIA 216 - 2021' => 'E.S.E. Hospital La María',
        'LA MARIA 317 COOSALUD' => 'E.S.E. Hospital La María',
        'LA MARIA COOSALUD - 046' => 'E.S.E. Hospital La María',
        'LA MARIA COOSALUD 191' => 'E.S.E. Hospital La María',
        'LA MARIA COOSALUD 36-2022' => 'E.S.E. Hospital La María',
        'LA MARIA ENTERRITORIO - 287' => 'E.S.E. Hospital La María',
        'LA MARIA ENTERRITORIO 038' => 'E.S.E. Hospital La María',
        'LA MARIA ENTERRITORIO 238' => 'E.S.E. Hospital La María',
        'LA MARIA- INFECCIOSA PS 268' => 'E.S.E. Hospital La María',
        'LA MARIA ITS ' => 'E.S.E. Hospital La María',
        'LA MARIA ITS 127' => 'E.S.E. Hospital La María',
        'LA MARIA ITS- 376' => 'E.S.E. Hospital La María',
        'LA MARIA PAI ' => 'E.S.E. Hospital La María',
        'LA MARIA TB 137' => 'E.S.E. Hospital La María',
        'LA MARIA TB Y LEPRA  319-2021' => 'E.S.E. Hospital La María',
        'LA MARIA TBC' => 'E.S.E. Hospital La María',
        'LA MARIA TRANSMISIBLES - 122' => 'E.S.E. Hospital La María',
        'LA MARIA TRANSMISIBLES - 275' => 'E.S.E. Hospital La María',
        'LA MARIA TRANSMISIBLES 234' => 'E.S.E. Hospital La María',
        'LA MARIA UPAI - 0028 - 2023' => 'E.S.E. Hospital La María',
        'LA MARIA UPAI - 140 - 2023' => 'E.S.E. Hospital La María',
        'LA MARIA UPAI - 271' => 'E.S.E. Hospital La María',
        'LA MARIA UPAI 0028 - 2023' => 'E.S.E. Hospital La María',
        'LA MARIA UPAI 245' => 'E.S.E. Hospital La María',
        'LA MARIA UPAI 35' => 'E.S.E. Hospital La María',
        'LA MARIA VIH - 158' => 'E.S.E. Hospital La María',
        'LA MARIA VIH 037' => 'E.S.E. Hospital La María',
        'LA MARIA VIH 131' => 'E.S.E. Hospital La María',
        'LA MARIA VIH 131 - 2023' => 'E.S.E. Hospital La María',
        'LA MARIA VIH 158' => 'E.S.E. Hospital La María',
        'LA MARIA VIH 188' => 'E.S.E. Hospital La María',
        'LA MARIA VIH N°043' => 'E.S.E. Hospital La María',
        'LA MARIA VIH UT ' => 'E.S.E. Hospital La María',
        'LAMARIACOOSALUD36' => 'E.S.E. Hospital La María',
        'LAMARIAENTERRITORIO038' => 'E.S.E. Hospital La María',
        'LAMARIAITS127' => 'E.S.E. Hospital La María',
        'LAMARIATB2022' => 'E.S.E. Hospital La María',
        'LAMARIAUPAI35' => 'E.S.E. Hospital La María',
        'LAMARIAVIH037' => 'E.S.E. Hospital La María',
        'POLICLINICO' => 'POLICLINICO',
        'PROMOTORA MEDICA Y ODONTOLOGICA DE ANTIOQUIA S.A.' => 'PROMOTORA MEDICA Y ODONTOLOGICA DE ANTIOQUIA S.A.',
        'PUERTO BERRIO' => 'E.S.E. Hospital La Cruz',
        'SOMER' => 'SOMER',
        'STA GERTRUDIS' => 'E.S.E. Santa Gertrudis',
        'UNION TEMPORAL - 020 - 2023' => 'E.S.E. Hospital La María',
        'VENANCIO' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO -  SALUD MENTAL ' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO - ADMON' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO - ASIST' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO - ASIST ' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO - PIC ' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO - SALUD MENTAL ' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO - SALUD P.' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO - UCI' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENANCIO ADMON - APH' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
        'VENECIA' => 'ESE Hospital San Rafael de Venecia',
    ];

    /**
     * Generar reporte Excel con las 4 hojas especificadas.
     */
    public function generateReport(array $filters): string
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

            // Crear hoja "Detalle Solicitudes"
            $detailSheet = $spreadsheet->createSheet();
            $detailSheet->setTitle('Detalle Solicitudes');
            $this->buildDetailSheet($detailSheet, $requests);

            // Crear hoja "Estadísticas por Tipo"
            $statsByTypeSheet = $spreadsheet->createSheet();
            $statsByTypeSheet->setTitle('Estadísticas por Tipo');
            $this->buildStatsByTypeSheet($statsByTypeSheet, $requests);

            // Crear hoja "Detalles Específicos"
            $specificDetailsSheet = $spreadsheet->createSheet();
            $specificDetailsSheet->setTitle('Detalles Específicos');
            $this->buildSpecificDetailsSheet($specificDetailsSheet, $requests);

            // Establecer primera hoja como activa
            $spreadsheet->setActiveSheetIndex(0);

            // Guardar en archivo temporal
            $tempFile = tempnam(sys_get_temp_dir(), 'request_report_') . '.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save($tempFile);

            return $tempFile;
        } catch (\Exception $e) {
            Log::error('Error generando reporte Excel de solicitudes', [
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
        $dateRange = $filters['date_range'] ?? [];

        if (!($dateRange['include_all'] ?? true)) {
            if (isset($dateRange['start_date']) && isset($dateRange['end_date'])) {
                $startDate = Carbon::parse($dateRange['start_date']);
                $endDate = Carbon::parse($dateRange['end_date']);

                if ($startDate->gt($endDate)) {
                    throw new \InvalidArgumentException('La fecha inicial debe ser anterior o igual a la fecha final.');
                }
            }
        }
    }

    /**
     * Obtener solicitudes con filtros aplicados.
     */
    private function getRequests(array $filters): Collection
    {
        $query = RequestForm::query();

        // Filtro por tipo de solicitud
        $requestType = $filters['request_type'] ?? 'all';
        if ($requestType !== 'all') {
            $query->where('request_type', $requestType);
        }

        // Filtro por rango de fechas
        $dateRange = $filters['date_range'] ?? [];
        if (!($dateRange['include_all'] ?? true)) {
            if (isset($dateRange['start_date'])) {
                $startDate = Carbon::parse($dateRange['start_date'])->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }

            if (isset($dateRange['end_date'])) {
                $endDate = Carbon::parse($dateRange['end_date'])->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }
        }

        // Cargar relaciones necesarias para obtener el responsable de la respuesta final
        $query->with(['responses.responder']);

        // Ordenar por fecha de creación descendente
        $query->orderBy('created_at', 'desc');

        return $query->get();
    }

    /**
     * Construir hoja "Resumen".
     */
    private function buildSummarySheet(Worksheet $sheet, Collection $requests, array $filters): void
    {
        $row = 1;

        // Encabezado
        $sheet->setCellValue("A{$row}", 'REPORTE DE SOLICITUDES PROSALUD');
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $row += 2;

        // Fecha de generación
        $sheet->setCellValue("A{$row}", 'Fecha de generación:');
        $sheet->setCellValue("B{$row}", now()->setTimezone('America/Bogota')->format('d/m/Y H:i:s'));
        $row++;

        // Período
        $dateRange = $filters['date_range'] ?? [];
        if (!($dateRange['include_all'] ?? true) && isset($dateRange['start_date']) && isset($dateRange['end_date'])) {
            $startDate = Carbon::parse($dateRange['start_date'])->format('d/m/Y');
            $endDate = Carbon::parse($dateRange['end_date'])->format('d/m/Y');
            $sheet->setCellValue("A{$row}", 'Período:');
            $sheet->setCellValue("B{$row}", "{$startDate} - {$endDate}");
            $row++;
        } else {
            $sheet->setCellValue("A{$row}", 'Período:');
            $sheet->setCellValue("B{$row}", 'Todos los registros');
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
            ['Solicitudes en proceso', $stats['in_progress']],
            ['Solicitudes resueltas', $stats['resolved']],
            ['Solicitudes rechazadas', $stats['rejected']],
            ['Afiliados Únicos', $stats['unique_affiliates']],
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

        // DISTRIBUCIÓN POR TIPO DE SOLICITUD
        $sheet->setCellValue("A{$row}", 'DISTRIBUCIÓN POR TIPO DE SOLICITUD');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $typeDistribution = $this->calculateTypeDistribution($requests);

        $distributionData = [['Tipo', 'Cantidad']];
        if (empty($typeDistribution)) {
            $distributionData[] = ['No hay datos', 0];
        } else {
            foreach ($typeDistribution as $type => $count) {
                $distributionData[] = [$type, $count];
            }
        }

        $distributionStartRow = $row;
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

        $row += count($distributionData) + 3; // Add more space before hospital table

        // DISTRIBUCIÓN POR HOSPITAL
        $sheet->setCellValue("A{$row}", 'DISTRIBUCIÓN POR HOSPITAL');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $hospitalDistribution = $this->calculateHospitalDistribution($requests);

        $hospitalData = [['Hospital', 'Cantidad']];
        if (empty($hospitalDistribution)) {
            $hospitalData[] = ['No hay datos', 0];
        } else {
            foreach ($hospitalDistribution as $hospital => $count) {
                $hospitalData[] = [$hospital, $count];
            }
        }

        $hospitalStartRow = $row;
        $sheet->fromArray($hospitalData, null, "A{$row}");

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
        $hospitalDataRange = "A{$row}:B" . ($row + count($hospitalData) - 1);
        $sheet->getStyle($hospitalDataRange)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        $row += count($hospitalData) + 3; // Add more space before unique affiliates table

        // AFILIADOS ÚNICOS POR HOSPITAL
        $sheet->setCellValue("A{$row}", 'AFILIADOS ÚNICOS POR HOSPITAL');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $uniqueAffiliatesByHospital = $this->calculateUniqueAffiliatesByHospital($requests);

        $uniqueAffiliatesData = [['Hospital', 'Afiliados Únicos']];
        if (empty($uniqueAffiliatesByHospital)) {
            $uniqueAffiliatesData[] = ['No hay datos', 0];
        } else {
            foreach ($uniqueAffiliatesByHospital as $hospital => $count) {
                $uniqueAffiliatesData[] = [$hospital, $count];
            }
        }

        $uniqueAffiliatesStartRow = $row;
        $sheet->fromArray($uniqueAffiliatesData, null, "A{$row}");

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
        $uniqueAffiliatesDataRange = "A{$row}:B" . ($row + count($uniqueAffiliatesData) - 1);
        $sheet->getStyle($uniqueAffiliatesDataRange)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Ajustar anchos de columna
        $sheet->getColumnDimension('A')->setWidth(50);
        $sheet->getColumnDimension('B')->setWidth(15);

        // Agregar gráficas estratégicas
        $row += 2;
        $this->addChartsToSummarySheet($sheet, $requests, $row);
    }

    /**
     * Construir hoja "Detalle Solicitudes".
     */
    private function buildDetailSheet(Worksheet $sheet, Collection $requests): void
    {
        $headers = [
            'ID Solicitud',
            'Nombre',
            'Apellido',
            'Tipo ID',
            'Número ID',
            'Email',
            'Teléfono',
            'Tipo Solicitud',
            'Subtipo de Solicitud',
            'Estado',
            'Fecha Creación',
            'Fecha Procesamiento',
            'Tiempo Procesamiento (Horas Laborales)',
            'Programa',
            'Hospital',
            'Proceso',
            'Razón de Rechazo',
            'Responsable de Respuesta Final',
            'Respuesta al afiliado',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Estilizar encabezados
        $headerRange = 'A1:S1';
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
            'A' => 15, // ID Solicitud
            'B' => 25, // Nombre
            'C' => 25, // Apellido
            'D' => 12, // Tipo ID
            'E' => 18, // Número ID
            'F' => 30, // Email
            'G' => 18, // Teléfono
            'H' => 30, // Tipo Solicitud
            'I' => 35, // Subtipo de Solicitud
            'J' => 18, // Estado
            'K' => 20, // Fecha Creación
            'L' => 20, // Fecha Procesamiento
            'M' => 35, // Tiempo Procesamiento (Horas Laborales)
            'N' => 30, // Programa
            'O' => 40, // Hospital
            'P' => 30, // Proceso
            'Q' => 50, // Razón de Rechazo
            'R' => 55, // Responsable de Respuesta Final
            'S' => 75, // Respuesta al afiliado
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;

        foreach ($requests as $request) {
            // Obtener responsable de la respuesta final (solo para solicitudes completadas o rechazadas)
            $responsiblePerson = '';
            if ($this->isResolvedStatus($request->status) || $this->isRejectedStatus($request->status)) {
                // Obtener la respuesta más reciente que tenga estado completado o rechazado
                $latestResponse = $request->responses
                    ->whereIn('status', [RequestStatuses::COMPLETED, RequestStatuses::REJECTED])
                    ->sortByDesc('created_at')
                    ->first();
                
                if ($latestResponse && $latestResponse->responder) {
                    $responder = $latestResponse->responder;
                    $responsiblePerson = $responder->name . ' (' . $responder->email . ')';
                }
            }

            // Transformar razón de rechazo técnica a etiqueta amigable
            $rejectionReason = $this->getRejectionReasonLabel($request->rejection_reason);

            // Obtener cuerpo del correo de la última respuesta (la más reciente) para trazabilidad
            $respuestaAlAfiliado = '';
            $latestResponse = $request->responses->first();
            if ($latestResponse && !empty($latestResponse->email_body)) {
                $respuestaAlAfiliado = trim(strip_tags($latestResponse->email_body));
            }

            // Formatear subtipo a etiqueta amigable
            $formattedSubtype = $this->getSubtypeLabel($request->request_subtype);
            
            // Calcular horas laborales de procesamiento
            $businessHours = $this->calculateBusinessHours($request->created_at, $request->processed_at);
            $businessHoursDisplay = $businessHours > 0 ? $businessHours . ' horas' : '';

            // Extraer valores del payload
            $payload = $request->payload ?? [];
            // Soportar ambos campos: 'dondeRealizaProceso' y 'sedeProceso'
            $programa = $payload['dondeRealizaProceso'] ?? $payload['sedeProceso'] ?? '';
            $hospital = $this->getHospitalName($payload['dondeRealizaProceso'] ?? $payload['sedeProceso'] ?? '');
            $proceso = $payload['proceso'] ?? '';

            $rowData = [
                $request->id,
                $request->name,
                $request->last_name,
                $request->document_type,
                $request->document_number,
                $request->email,
                $request->phone_number,
                $this->getRequestTypeLabel($request->request_type),
                $formattedSubtype,
                $this->getStatusLabel($request->status),
                $this->formatDateTime($request->created_at),
                $this->formatDateTime($request->processed_at),
                $businessHoursDisplay,
                $programa,
                $hospital,
                $proceso,
                $rejectionReason,
                $responsiblePerson,
                $respuestaAlAfiliado,
            ];

            $sheet->fromArray([$rowData], null, "A{$row}");

            // Aplicar formato condicional a la columna de estado
            $statusCell = "J{$row}";
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
            $dataRange = "A1:S" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        // Agregar autofiltro
        if ($row > 2) {
            $sheet->setAutoFilter("A1:S" . ($row - 1));
        }

        // Congelar primera fila
        $sheet->freezePane('A2');
    }

    /**
     * Construir hoja "Estadísticas por Tipo".
     */
    private function buildStatsByTypeSheet(Worksheet $sheet, Collection $requests): void
    {
        $headers = [
            'Tipo',
            'Total',
            'Pendientes',
            'En Proceso',
            'Resueltas',
            'Rechazadas',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Estilizar encabezados
        $headerRange = 'A1:F1';
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
            'A' => 35, // Tipo
            'B' => 12, // Total
            'C' => 15, // Pendientes
            'D' => 15, // En Proceso
            'E' => 15, // Resueltas
            'F' => 15, // Rechazadas
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $statsByType = $this->calculateStatsByType($requests);

        $row = 2;

        foreach ($statsByType as $typeLabel => $stats) {
            $rowData = [
                $typeLabel,
                $stats['total'],
                $stats['pending'],
                $stats['in_progress'],
                $stats['resolved'],
                $stats['rejected'],
            ];

            $sheet->fromArray([$rowData], null, "A{$row}");
            $row++;
        }

        // Aplicar bordes a todas las filas de datos
        if ($row > 2) {
            $dataRange = "A1:F" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
            ]);
        }

        // Agregar autofiltro
        if ($row > 2) {
            $sheet->setAutoFilter("A1:F" . ($row - 1));
        }

        // Congelar primera fila
        $sheet->freezePane('A2');
    }

    /**
     * Construir hoja "Detalles Específicos".
     */
    private function buildSpecificDetailsSheet(Worksheet $sheet, Collection $requests): void
    {
        $headers = [
            'ID Solicitud',
            'Solicitante',
            'Tipo Documento',
            'Número Documento',
            'Tipo',
            'Detalles Específicos',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Estilizar encabezados
        $headerRange = 'A1:F1';
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
        $sheet->getColumnDimension('A')->setWidth(15); // ID Solicitud
        $sheet->getColumnDimension('B')->setWidth(35); // Solicitante
        $sheet->getColumnDimension('C')->setWidth(18); // Tipo Documento
        $sheet->getColumnDimension('D')->setWidth(20); // Número Documento
        $sheet->getColumnDimension('E')->setWidth(30); // Tipo
        $sheet->getColumnDimension('F')->setWidth(80); // Detalles Específicos

        $row = 2;

        foreach ($requests as $request) {
            $payloadFormatted = 'N/A';
            if ($request->payload && is_array($request->payload)) {
                $payloadFormatted = $this->formatPayloadAsReadableText($request->payload);
            }

            $rowData = [
                $request->id,
                $request->full_name,
                $request->document_type ?? '',
                $request->document_number ?? '',
                $this->getRequestTypeLabel($request->request_type),
                $payloadFormatted,
            ];

            $sheet->fromArray([$rowData], null, "A{$row}");

            // Configurar formato de texto para la columna de payload (para que el JSON se vea mejor)
            $sheet->getStyle("F{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(-1); // Auto-height

            $row++;
        }

        // Aplicar bordes a todas las filas de datos
        if ($row > 2) {
            $dataRange = "A1:F" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        // Agregar autofiltro
        if ($row > 2) {
            $sheet->setAutoFilter("A1:F" . ($row - 1));
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
            'unique_affiliates' => $requests->pluck('document_number')->unique()->count(),
        ];

        foreach ($requests as $request) {
            $normalizedStatus = $this->normalizeStatus($request->status);
            
            if (isset($stats[$normalizedStatus])) {
                $stats[$normalizedStatus]++;
            }
        }

        return $stats;
    }

    /**
     * Calcular distribución por tipo.
     */
    private function calculateTypeDistribution(Collection $requests): array
    {
        $distribution = [];

        foreach ($requests as $request) {
            $typeLabel = $this->getRequestTypeLabel($request->request_type);
            $distribution[$typeLabel] = ($distribution[$typeLabel] ?? 0) + 1;
        }

        // Ordenar por cantidad descendente
        arsort($distribution);

        return $distribution;
    }

    /**
     * Calcular distribución por hospital.
     */
    private function calculateHospitalDistribution(Collection $requests): array
    {
        $distribution = [];

        foreach ($requests as $request) {
            $payload = $request->payload ?? [];
            // Soportar ambos campos: 'dondeRealizaProceso' y 'sedeProceso'
            $hospitalCode = $payload['dondeRealizaProceso'] ?? $payload['sedeProceso'] ?? '';
            
            if (empty($hospitalCode)) {
                continue;
            }

            $hospitalName = $this->getHospitalName($hospitalCode);
            $distribution[$hospitalName] = ($distribution[$hospitalName] ?? 0) + 1;
        }

        // Ordenar por cantidad descendente
        arsort($distribution);

        return $distribution;
    }

    /**
     * Calcular afiliados únicos por hospital.
     */
    private function calculateUniqueAffiliatesByHospital(Collection $requests): array
    {
        $distribution = [];

        foreach ($requests as $request) {
            $payload = $request->payload ?? [];
            // Soportar ambos campos: 'dondeRealizaProceso' y 'sedeProceso'
            $hospitalCode = $payload['dondeRealizaProceso'] ?? $payload['sedeProceso'] ?? '';
            
            if (empty($hospitalCode)) {
                continue;
            }

            $hospitalName = $this->getHospitalName($hospitalCode);
            
            // Agregar afiliado único a la distribución del hospital
            if (!isset($distribution[$hospitalName])) {
                $distribution[$hospitalName] = [];
            }
            
            $distribution[$hospitalName][] = $request->document_number;
        }

        // Contar afiliados únicos por hospital
        $uniqueDistribution = [];
        foreach ($distribution as $hospital => $affiliates) {
            $uniqueDistribution[$hospital] = count(array_unique($affiliates));
        }

        // Ordenar por cantidad descendente
        arsort($uniqueDistribution);

        return $uniqueDistribution;
    }

    /**
     * Calcular estadísticas por tipo.
     */
    private function calculateStatsByType(Collection $requests): array
    {
        $statsByType = [];

        foreach ($requests as $request) {
            $typeLabel = $this->getRequestTypeLabel($request->request_type);
            $normalizedStatus = $this->normalizeStatus($request->status);

            if (!isset($statsByType[$typeLabel])) {
                $statsByType[$typeLabel] = [
                    'total' => 0,
                    'pending' => 0,
                    'in_progress' => 0,
                    'resolved' => 0,
                    'rejected' => 0,
                ];
            }

            $statsByType[$typeLabel]['total']++;

            if (isset($statsByType[$typeLabel][$normalizedStatus])) {
                $statsByType[$typeLabel][$normalizedStatus]++;
            }
        }

        // Ordenar por total descendente
        uasort($statsByType, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return $statsByType;
    }

    /**
     * Obtener etiqueta del tipo de solicitud.
     */
    private function getRequestTypeLabel(string $requestType): string
    {
        return self::REQUEST_TYPE_LABELS[$requestType] ?? $requestType;
    }

    /**
     * Obtener etiqueta del estado.
     */
    private function getStatusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', strtolower($status)));
    }

    /**
     * Normalizar estado a formato estándar.
     */
    private function normalizeStatus(string $status): string
    {
        // Mapear estados de constantes a formato estándar
        $statusMap = [
            RequestStatuses::PENDING => 'pending',
            RequestStatuses::IN_REVIEW => 'in_progress',
            RequestStatuses::COMPLETED => 'resolved',
            RequestStatuses::REJECTED => 'rejected',
            'processed' => 'resolved',
        ];

        $normalized = $statusMap[strtoupper($status)] ?? strtolower($status);

        // Asegurar que sea uno de los estados esperados
        if (!in_array($normalized, ['pending', 'in_progress', 'resolved', 'rejected'])) {
            // Intentar mapear por similitud
            if (str_contains(strtolower($status), 'pend')) {
                return 'pending';
            } elseif (str_contains(strtolower($status), 'proces') || str_contains(strtolower($status), 'revis')) {
                return 'in_progress';
            } elseif (str_contains(strtolower($status), 'resuel') || str_contains(strtolower($status), 'complet')) {
                return 'resolved';
            } elseif (str_contains(strtolower($status), 'rechaz')) {
                return 'rejected';
            }

            return 'pending'; // Default
        }

        return $normalized;
    }

    /**
     * Verificar si el estado es resuelto.
     */
    private function isResolvedStatus(string $status): bool
    {
        $normalized = $this->normalizeStatus($status);
        return $normalized === 'resolved';
    }

    /**
     * Verificar si el estado es rechazado.
     */
    private function isRejectedStatus(string $status): bool
    {
        $normalized = $this->normalizeStatus($status);
        return $normalized === 'rejected';
    }

    /**
     * Obtener color para el estado.
     */
    private function getStatusColor(string $status): ?string
    {
        $normalized = $this->normalizeStatus($status);

        $colorMap = [
            'pending' => 'FFF2CC',    // Amarillo claro
            'in_progress' => 'D5E8D4', // Verde claro
            'resolved' => 'D5E8D4',    // Verde claro
            'rejected' => 'F8CECC',    // Rojo claro
        ];

        return $colorMap[$normalized] ?? null;
    }

    /**
     * Formatear fecha para Excel (solo fecha, sin hora).
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
     * Formatear fecha y hora para Excel.
     */
    private function formatDateTime($date): string
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

        return $date->format('d/m/Y H:i');
    }

    /**
     * Obtener etiqueta amigable para razón de rechazo.
     */
    private function getRejectionReasonLabel(?string $rejectionReason): string
    {
        if (!$rejectionReason) {
            return '';
        }

        // Si el valor es uno de los códigos predefinidos, devolver la etiqueta
        if (isset(self::REJECTION_REASON_LABELS[$rejectionReason])) {
            return self::REJECTION_REASON_LABELS[$rejectionReason];
        }

        // Si no se encuentra, devolver el valor original (texto libre de "otro")
        return $rejectionReason;
    }

    /**
     * Obtener etiqueta amigable para subtipo de solicitud.
     * Convierte valores en mayúsculas a un formato más legible.
     */
    private function getSubtypeLabel(?string $subtype): string
    {
        if (!$subtype) {
            return '';
        }

        // Mapeo de subtipos a etiquetas amigables
        $subtypeLabels = [
            'COMPENSACIÓN. FINAL (LIQUIDACIÓN)' => 'Compensación Final (Liquidación)',
            'COMPENSACIÓN ANUAL DIFERIDA Y/O DESCANSO' => 'Compensación Anual Diferida y/o Descanso',
            'COMPENSACIÓN POR DESCANSO' => 'Compensación por Descanso',
            'DESCUENTOS SEGURIDAD SOCIAL' => 'Descuentos Seguridad Social',
            'DUPLICADO COLILLAS' => 'Duplicado de Colillas',
            'VIATICOS' => 'Viáticos',
            'Ceiisas' => 'Ceiisas',
            'COMPENSACIÓN. MENSUAL' => 'Compensación Mensual',
            'COMPENSACIÓN SEMESTRAL' => 'Compensación Semestral',
            'INCAPACIDADES' => 'Incapacidades',
            'SUBSIDIOS' => 'Subsidios',
        ];

        // Si existe un mapeo, usarlo
        if (isset($subtypeLabels[$subtype])) {
            return $subtypeLabels[$subtype];
        }

        // Para otros valores, formatear automáticamente
        // Convertir a título y limificar espacios
        $formatted = ucwords(strtolower(trim($subtype)));
        
        // Reemplazar puntos y caracteres extraños
        $formatted = str_replace(['.', '  ', '  '], [' ', ' ', ' '], $formatted);
        
        return $formatted;
    }

    /**
     * Obtener nombre completo del hospital a partir del código.
     */
    private function getHospitalName(string $hospitalCode): string
    {
        // Si el código ya contiene el nombre completo, retornarlo tal cual
        if (empty($hospitalCode)) {
            return '';
        }

        // Buscar en el mapeo de hospitales
        return self::HOSPITAL_NAME_MAPPINGS[$hospitalCode] ?? $hospitalCode;
    }

    /**
     * Calcular horas laborales entre dos fechas.
     * Solo cuenta horas de lunes a viernes de 7am a 5pm (10 horas día).
     */
    private function calculateBusinessHours($createdAt, $processedAt): float
    {
        if (!$createdAt || !$processedAt) {
            return 0;
        }

        try {
            $start = is_string($createdAt) ? Carbon::parse($createdAt) : $createdAt;
            $end = is_string($processedAt) ? Carbon::parse($processedAt) : $processedAt;
            
            $totalHours = 0;
            $current = $start->copy();
            
            while ($current < $end) {
                // Solo procesar días laborales (lunes-viernes)
                if ($current->dayOfWeek >= Carbon::MONDAY && $current->dayOfWeek <= Carbon::FRIDAY) {
                    // Inicio del día laboral (7am)
                    $dayStart = $current->copy()->setTime(7, 0, 0);
                    // Fin del día laboral (5pm)
                    $dayEnd = $current->copy()->setTime(17, 0, 0);
                    
                    // Ajustar el tiempo actual al inicio del rango del día
                    $periodStart = max($current, $dayStart);
                    // Ajustar el tiempo final al fin del rango del día
                    $periodEnd = min($end, $dayEnd);
                    
                    // Si hay superposición, agregar las horas
                    if ($periodStart < $periodEnd) {
                        $totalHours += $periodStart->diffInHours($periodEnd);
                    }
                }
                
                // Mover al siguiente día
                $current = $current->addDay()->setTime(0, 0, 0);
            }
            
            return round($totalHours, 1);
        } catch (\Exception $e) {
            Log::error('Error calculating business hours', [
                'error' => $e->getMessage(),
                'created_at' => $createdAt,
                'processed_at' => $processedAt,
            ]);
            return 0;
        }
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
        $typeData = $this->prepareTypeDataForChart($requests);
        $monthlyData = $this->prepareMonthlyDataForChart($requests);
        $statusByMonthData = $this->prepareStatusByMonthDataForChart($requests);
        $hospitalData = $this->prepareHospitalDataForChart($requests);

        // Gráfica 1: Distribución por Estado (Pastel) - Columna D
        if (!empty($statusData)) {
            $this->addStatusDistributionChart($sheet, $statusData, 'D2', 'I2', 'D17', 'I30');
        }

        // Gráfica 2: Distribución por Tipo de Solicitud (Barras) - Columna D, después de la primera
        if (!empty($typeData)) {
            $this->addTypeDistributionChart($sheet, $typeData, 'K2', 'Q2', 'K17', 'Q30');
        }

        // Gráfica 3: Tendencia Mensual de Solicitudes (Líneas) - Más abajo
        if (!empty($monthlyData)) {
            $this->addMonthlyTrendChart($sheet, $monthlyData, 'D32', 'I32', 'D47', 'I60');
        }

        // Gráfica 4: Estados por Mes (Barras Apiladas) - Más abajo
        if (!empty($statusByMonthData)) {
            $this->addStatusByMonthChart($sheet, $statusByMonthData, 'K32', 'Q32', 'K47', 'Q60');
        }

        // Gráfica 5: Distribución por Hospital (Barras Horizontales) - A la derecha de las gráficas superiores
        if (!empty($hospitalData)) {
            $this->addHospitalDistributionChart($sheet, $hospitalData, 'R2', 'Y2', 'R17', 'Y30');
        }
    }

    /**
     * Preparar datos de distribución por estado para gráfica.
     */
    private function prepareStatusDataForChart(Collection $requests): array
    {
        $statusCounts = [
            'Pendiente' => 0,
            'En Proceso' => 0,
            'Resuelto' => 0,
            'Rechazado' => 0,
        ];

        foreach ($requests as $request) {
            $statusLabel = $this->getStatusLabel($request->status);
            if (isset($statusCounts[$statusLabel])) {
                $statusCounts[$statusLabel]++;
            } else {
                // Agregar estado personalizado si no existe
                $statusCounts[$statusLabel] = 1;
            }
        }

        // Filtrar estados con 0 solicitudes
        return array_filter($statusCounts, fn($count) => $count > 0);
    }

    /**
     * Preparar datos de distribución por tipo para gráfica.
     */
    private function prepareTypeDataForChart(Collection $requests): array
    {
        $typeCounts = [];

        foreach ($requests as $request) {
            $typeLabel = $this->getRequestTypeLabel($request->request_type);
            $typeCounts[$typeLabel] = ($typeCounts[$typeLabel] ?? 0) + 1;
        }

        // Ordenar por cantidad descendente y tomar los top 10
        arsort($typeCounts);
        return array_slice($typeCounts, 0, 10, true);
    }

    /**
     * Preparar datos mensuales para gráfica de tendencia.
     */
    private function prepareMonthlyDataForChart(Collection $requests): array
    {
        $monthlyCounts = [];

        foreach ($requests as $request) {
            if (!$request->created_at) {
                continue;
            }

            $month = is_string($request->created_at)
                ? Carbon::parse($request->created_at)->format('Y-m')
                : $request->created_at->format('Y-m');

            $monthlyCounts[$month] = ($monthlyCounts[$month] ?? 0) + 1;
        }

        // Ordenar por mes
        ksort($monthlyCounts);

        return $monthlyCounts;
    }

    /**
     * Preparar datos de estados por mes para gráfica apilada.
     */
    private function prepareStatusByMonthDataForChart(Collection $requests): array
    {
        $statusByMonth = [];

        foreach ($requests as $request) {
            if (!$request->created_at) {
                continue;
            }

            $month = is_string($request->created_at)
                ? Carbon::parse($request->created_at)->format('Y-m')
                : $request->created_at->format('Y-m');

            $statusLabel = $this->getStatusLabel($request->status);

            if (!isset($statusByMonth[$month])) {
                $statusByMonth[$month] = [
                    'Pendiente' => 0,
                    'En Proceso' => 0,
                    'Resuelto' => 0,
                    'Rechazado' => 0,
                ];
            }

            if (isset($statusByMonth[$month][$statusLabel])) {
                $statusByMonth[$month][$statusLabel]++;
            } else {
                $statusByMonth[$month][$statusLabel] = 1;
            }
        }

        // Ordenar por mes
        ksort($statusByMonth);

        return $statusByMonth;
    }

    /**
     * Preparar datos de distribución por hospital para gráfica.
     */
    private function prepareHospitalDataForChart(Collection $requests): array
    {
        return $this->calculateHospitalDistribution($requests);
    }

    /**
     * Agregar gráfica de distribución por estado (Pastel).
     */
    private function addStatusDistributionChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
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
            DataSeries::TYPE_PIECHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        // Definir colores personalizados para cada estado
        $colors = [];
        foreach ($labels as $label) {
            switch ($label) {
                case 'Pendiente':
                    $colors[] = 'FFE699'; // Amarillo suave
                    break;
                case 'En Proceso':
                    $colors[] = '0070C0'; // Azul
                    break;
                case 'Resuelto':
                    $colors[] = '00B050'; // Verde
                    break;
                case 'Rechazado':
                    $colors[] = 'FF6B6B'; // Rojo suave
                    break;
                default:
                    $colors[] = '95A5A6'; // Gris por defecto
                    break;
            }
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
     * Agregar gráfica de distribución por tipo (Barras).
     */
    private function addTypeDistributionChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $types = array_keys($data);
        // Truncar nombres largos para mejor visualización
        $types = array_map(function ($name) {
            return mb_strlen($name) > 25 ? mb_substr($name, 0, 22) . '...' : $name;
        }, $types);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($types),
                $types
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
        $title = new Title('Top Tipos de Solicitudes');

        $chart = new Chart(
            'chart_type_distribution',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            new Title('Tipo de Solicitud'),
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
     * Agregar gráfica de estados por mes (Barras Apiladas).
     */
    private function addStatusByMonthChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        // Preparar datos para gráfica apilada
        $months = [];
        $pendingValues = [];
        $inProgressValues = [];
        $resolvedValues = [];
        $rejectedValues = [];

        foreach ($data as $month => $statuses) {
            $months[] = Carbon::parse($month . '-01')->format('M Y');
            $pendingValues[] = $statuses['Pendiente'] ?? 0;
            $inProgressValues[] = $statuses['En Proceso'] ?? 0;
            $resolvedValues[] = $statuses['Resuelto'] ?? 0;
            $rejectedValues[] = $statuses['Rechazado'] ?? 0;
        }

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Pendiente']),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['En Proceso']),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Resuelto']),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Rechazado']),
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
                count($pendingValues),
                $pendingValues,
                null,
                'FFE699' // Amarillo suave
            ),
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($inProgressValues),
                $inProgressValues,
                null,
                '0070C0' // Azul
            ),
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($resolvedValues),
                $resolvedValues,
                null,
                '00B050' // Verde
            ),
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($rejectedValues),
                $rejectedValues,
                null,
                'FF6B6B' // Rojo suave
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_STACKED,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $series->setPlotDirection(DataSeries::DIRECTION_VERTICAL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Estados de Solicitudes por Mes');

        $chart = new Chart(
            'chart_status_by_month',
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
     * Formatear payload como texto legible en lugar de JSON.
     */
    private function formatPayloadAsReadableText(array $payload, int $indentLevel = 0): string
    {
        $lines = [];
        $indent = str_repeat('  ', $indentLevel);

        foreach ($payload as $key => $value) {
            $formattedKey = $this->formatKeyAsLabel($key);

            if (is_array($value)) {
                // Si es un array, mostrar el título y luego los elementos
                if (empty($value)) {
                    $lines[] = "{$indent}{$formattedKey}: (vacío)";
                } else {
                    $lines[] = "{$indent}{$formattedKey}:";
                    $lines[] = $this->formatPayloadAsReadableText($value, $indentLevel + 1);
                }
            } else {
                // Formatear el valor según su tipo
                $formattedValue = $this->formatValue($key, $value);
                $lines[] = "{$indent}{$formattedKey}: {$formattedValue}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Agregar gráfica de distribución por hospital (Barras).
     */
    private function addHospitalDistributionChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
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

        $title = new Title('Distribución por Hospital');
        $yAxisLabel = new Title('Cantidad de Solicitudes');

        $chart = new Chart(
            'chart_hospital_distribution',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            null,
            $yAxisLabel
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Formatear una clave de array como etiqueta legible.
     */
    private function formatKeyAsLabel(string $key): string
    {
        // Reemplazar camelCase por espacios y capitalizar
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        // Reemplazar guiones bajos por espacios
        $label = str_replace('_', ' ', $label);
        // Capitalizar primera letra de cada palabra
        return ucwords(strtolower($label));
    }

    /**
     * Formatear un valor según su tipo y clave.
     */
    private function formatValue(string $key, $value): string
    {
        if ($value === null) {
            return '(no especificado)';
        }

        if ($value === '') {
            return '(vacío)';
        }

        // Formatear montoSolicitado como moneda
        if ($key === 'montoSolicitado' && is_numeric($value)) {
            return $this->formatCurrencyCOP($value);
        }

        // Formatear valores booleanos
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        // Formatear números
        if (is_numeric($value)) {
            return (string)$value;
        }

        // Formatear fechas si parecen ser fechas
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            try {
                $date = Carbon::parse($value);
                return $date->format('d/m/Y');
            } catch (\Exception $e) {
                // Si no es una fecha válida, devolver el valor original
            }
        }

        // Devolver el valor como string
        return (string)$value;
    }

    /**
     * Formatear un valor numérico a formato de moneda COP.
     */
    private function formatCurrencyCOP($amount): string
    {
        if (!is_numeric($amount)) {
            return (string)$amount;
        }

        // Formatear como moneda COP sin decimales: $1.234.567
        return '$' . number_format((float)$amount, 0, ',', '.');
    }
}

