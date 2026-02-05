<?php

namespace App\Services;

use App\Constants\SurveyOptions;
use App\Models\SocioDemographicSurvey;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\AutoFilter\Column;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Illuminate\Support\Facades\Storage;

class SocioDemographicSurveyExcelExportService
{
    private const SIGNATURE_DISK = 'prosalud-private';
    private const MAX_IMAGE_SIZE = 2 * 1024 * 1024; // 2MB

    /**
     * Temporary image files to clean up after report generation.
     */
    private array $tempImageFiles = [];
    /**
     * Generar reporte Excel con información completa de las encuestas.
     */
    public function generateReport(array $filters): string
    {
        try {
            // Validar filtros
            $this->validateFilters($filters);

            // Obtener encuestas con filtros aplicados
            $surveys = $this->getSurveys($filters);

            // Crear spreadsheet
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0);

            // Crear hoja "Resumen"
            $summarySheet = $spreadsheet->createSheet();
            $summarySheet->setTitle('Resumen');
            $this->buildSummarySheet($summarySheet, $surveys, $filters);

            // Crear hoja "Detalle Encuestas"
            $detailSheet = $spreadsheet->createSheet();
            $detailSheet->setTitle('Detalle Encuestas');
            $this->buildDetailSheet($detailSheet, $surveys, $filters);

            // Crear hoja "Beneficiarios"
            $beneficiariosSheet = $spreadsheet->createSheet();
            $beneficiariosSheet->setTitle('Beneficiarios');
            $this->buildBeneficiariosSheet($beneficiariosSheet, $surveys);

            // Crear hoja unificada "Estadísticas"
            $statsSheet = $spreadsheet->createSheet();
            $statsSheet->setTitle('Estadísticas');
            $this->buildStatsSheet($statsSheet, $surveys);

            // Establecer primera hoja como activa
            $spreadsheet->setActiveSheetIndex(0);

            // Guardar en archivo temporal
            $tempFile = tempnam(sys_get_temp_dir(), 'survey_report_') . '.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            // Clean up temporary image files
            $this->cleanupTempFiles();

            return $tempFile;
        } catch (\Exception $e) {
            Log::error('Error generando reporte Excel de encuestas sociodemográficas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);

            // Clean up temporary image files on error
            $this->cleanupTempFiles();

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
     * Obtener encuestas con filtros aplicados.
     */
    private function getSurveys(array $filters): Collection
    {
        $query = SocioDemographicSurvey::query();

        // Filtro por tipo de encuesta
        $surveyType = $filters['survey_type'] ?? 'all';
        if ($surveyType !== 'all') {
            if ($surveyType === 'active_affiliate') {
                $query->where(function ($q) {
                    $q->where('survey_type', 'active_affiliate')
                      ->orWhereNull('survey_type');
                });
            } elseif ($surveyType === 'new_entry') {
                $query->where('survey_type', 'new_entry');
            } elseif ($surveyType === 'bulk_entry') {
                // Compatibilidad con encuestas antiguas
                $query->where('survey_type', 'bulk_entry');
            }
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

        // Filtro por hospital (opcional)
        if (isset($filters['hospital']) && !empty($filters['hospital'])) {
            $query->where('hospital', $filters['hospital']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Construir hoja de resumen.
     */
    private function buildSummarySheet(Worksheet $sheet, Collection $surveys, array $filters): void
    {
        $row = 1;

        // Título
        $sheet->setCellValue('A1', 'RESUMEN DE ENCUESTAS SOCIODEMOGRÁFICAS');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row = 3;

        // Información de filtros aplicados
        $sheet->setCellValue('A' . $row, 'Filtros Aplicados:');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $dateRange = $filters['date_range'] ?? [];
        if ($dateRange['include_all'] ?? true) {
            $sheet->setCellValue('A' . $row, 'Rango de fechas: Todos los registros');
        } else {
            $startDate = $dateRange['start_date'] ?? 'No especificada';
            $endDate = $dateRange['end_date'] ?? 'No especificada';
            $sheet->setCellValue('A' . $row, "Rango de fechas: {$startDate} a {$endDate}");
        }
        $row++;

        $surveyType = $filters['survey_type'] ?? 'all';
        $typeLabel = $surveyType === 'all' ? 'Todos' : ($surveyType === 'active_affiliate' ? 'Afiliados Activos' : ($surveyType === 'new_entry' ? 'Nuevo Ingreso' : 'Ingreso Masivo'));
        $sheet->setCellValue('A' . $row, "Tipo de encuesta: {$typeLabel}");
        $row += 2;

        // Métricas generales
        $sheet->setCellValue('A' . $row, 'Métricas Generales');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $total = $surveys->count();
        $activeAffiliate = $surveys->where('survey_type', 'active_affiliate')->count() + $surveys->whereNull('survey_type')->count();
        $newEntry = $surveys->where('survey_type', 'new_entry')->count();
        $bulkEntry = $surveys->where('survey_type', 'bulk_entry')->count(); // Compatibilidad con encuestas antiguas

        $sheet->setCellValue('A' . $row, 'Total de encuestas:');
        $sheet->setCellValue('B' . $row, $total);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('A' . $row, 'Encuestas de Afiliados Activos:');
        $sheet->setCellValue('B' . $row, $activeAffiliate);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('A' . $row, 'Encuestas de Nuevo Ingreso:');
        $sheet->setCellValue('B' . $row, $newEntry);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        if ($bulkEntry > 0) {
            $sheet->setCellValue('A' . $row, 'Encuestas de Ingreso Masivo (legacy):');
            $sheet->setCellValue('B' . $row, $bulkEntry);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
        }

        // Fecha de generación
        $row += 2;
        $sheet->setCellValue('A' . $row, 'Fecha de generación:');
        $sheet->setCellValue('B' . $row, now()->setTimezone('America/Bogota')->format('d/m/Y H:i:s'));
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);

        // Ajustar ancho de columnas
        $sheet->getColumnDimension('A')->setWidth(35);
        $sheet->getColumnDimension('B')->setWidth(20);
    }

    /**
     * Construir hoja de detalle de encuestas.
     */
    private function buildDetailSheet(Worksheet $sheet, Collection $surveys, array $filters = []): void
    {
        $includeSignatures = $filters['include_signatures'] ?? false;
        $signatureWidth = 100;
        $signatureHeight = 50;
        
        $row = 1;

        // Encabezados completos con todos los campos desglosados
        $headers = [
            // Información básica
            'ID',
            'Tipo Encuesta',
            'Fecha Creación',
            'Correo',
            'Tipo Documento',
            'Número Documento',
            'Nombres',
            'Apellidos',
            'Hospital',
            'Profesión',
            'RH',
            'Fecha Expedición',
            'Lugar Nacimiento',
            'Departamento',
            'Celular',
            'Dirección',
            'Municipio',
            'Talla Calzado',
            'Talla Vestimenta',
            'País Nacimiento',
            
            // Contacto de Emergencia
            'Nombre Contacto Emergencia',
            'Relación Contacto Emergencia',
            'Teléfono Contacto Emergencia',
            
            // Datos Sociodemográficos
            'Tiene Personas a Cargo',
            'Estado Civil',
            'Fecha Nacimiento',
            'Estatura (cm)',
            'Peso (kg)',
            'Género',
            'Grupo Étnico',
            'Nivel Educativo',
            'Número Hijos',
            'Número Personas Dependientes',
            'Tipo Vivienda',
            'Estrato Socioeconómico',
            'Convive Con',
            'Transporte',
            'Tiempo Libre Con',
            'Servicios Públicos - Agua',
            'Servicios Públicos - Luz',
            'Servicios Públicos - Teléfono',
            'Servicios Públicos - Internet',
            'Servicios Públicos - Gas',
            'Manejo Tiempo Libre - Recreativas',
            'Manejo Tiempo Libre - Deportivas',
            'Manejo Tiempo Libre - Educativas',
            'Manejo Tiempo Libre - Descanso',
            'Manejo Tiempo Libre - Artísticas',
            'Manejo Tiempo Libre - Religiosas',
            'Manejo Tiempo Libre - Otras',
            
            // Datos de Consumo
            'Consumo Licor',
            'Frecuencia Licor',
            'Consumo Cigarrillo',
            'Frecuencia Cigarrillo',
            
            // Condiciones de Salud
            'Sobrepeso/Obesidad',
            'Hipertensión Arterial',
            'Enfermedades del Corazón',
            'Diabetes',
            'Problemas Renales',
            'Depresión/Bipolaridad',
            'Antecedentes Médicos Mentales',
            'Epilepsia/Convulsiones',
            'Trasplante',
            'Tipo Trasplante',
            'Cáncer',
            'Problemas Pulmonares',
            'Tipo Problema Pulmonar',
            'Alergias',
            'Tipo Alergia',
            'Tuberculosis',
            'Problemas Visuales',
            'Tipo Problema Visual',
            'Dolores Articulares',
            'Tipo Dolor Articular',
            'Problemas de Sangre',
            'Otra Enfermedad',
            'Tipo Otra Enfermedad',
            'Prótesis Articular',
            'Medicamento Permanente',
            'Tipo Medicamento',
            'Tratamiento Médico',
            'Cirugías',
            'Tipo Cirugía',
            'Tiempo Cirugía',
            'Accidente Laboral',
            'Tipo Accidente Laboral',
            'Tiempo Accidente Laboral',
            'Accidente Tránsito/Casero',
            'Tipo Accidente Tránsito',
            'Tiempo Accidente Tránsito',
            'Vacunado COVID',
            
            // Limitaciones Físicas
            'Limitación - Esfuerzos Intensos',
            'Limitación - Esfuerzos Moderados',
            'Limitación - Subir Pisos',
            'Limitación - Agacharse/Arrodillarse',
            
            // Recomendaciones Laborales
            'Recomendación Restricción Laboral',
            'Detalle Recomendación Laboral',
        ];

        // Agregar columna de firma si se solicita
        if ($includeSignatures) {
            $headers[] = 'Firma';
        }

        $col = 'A';
        $lastCol = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E0E0E0'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ]);
            $lastCol = $col;
            $col++;
        }
        $row++;

        // Datos
        foreach ($surveys as $survey) {
            $col = 'A';
            
            $datosSociodemograficos = $survey->datos_sociodemograficos ?? [];
            $datosConsumo = $survey->datos_consumo ?? [];
            $condicionesSalud = $survey->condiciones_salud ?? [];
            $limitacionesFisicas = $survey->limitaciones_fisicas ?? [];
            $serviciosPublicos = $datosSociodemograficos['serviciosPublicos'] ?? [];
            $manejoTiempoLibre = $datosSociodemograficos['manejoTiempoLibre'] ?? [];
            $hijos = $datosSociodemograficos['hijos'] ?? [];

            $data = [
                // Información básica
                $survey->id,
                $this->getSurveyTypeDisplayName($survey->survey_type),
                $survey->created_at?->format('d/m/Y H:i:s'),
                $survey->correo,
                $this->getTipoDocumentoDisplayName($survey->tipo_documento),
                $survey->numero_documento,
                $survey->nombres,
                $survey->apellidos,
                $survey->hospital,
                $survey->profesion,
                $survey->rh,
                $survey->fecha_expedicion?->format('d/m/Y'),
                $survey->lugar_nacimiento,
                $survey->departamento,
                $survey->celular,
                $survey->direccion,
                $survey->municipio,
                $survey->talla_calzado,
                $this->getTallaVestimentaDisplayName($survey->talla_vestimenta),
                $this->getPaisDisplayName($survey->pais_nacimiento),
                
                // Contacto de Emergencia
                $survey->nombre_contacto_emergencia,
                $this->getRelacionContactoEmergenciaDisplayName($survey->relacion_contacto_emergencia),
                $survey->telefono_contacto_emergencia,
                
                // Datos Sociodemográficos
                $this->formatSiNo($datosSociodemograficos['tienePersonasACargo'] ?? null),
                $this->getEstadoCivilDisplayName($datosSociodemograficos['estadoCivil'] ?? null),
                $datosSociodemograficos['fechaNacimiento'] ?? '',
                $datosSociodemograficos['estatura'] ?? '',
                $datosSociodemograficos['peso'] ?? '',
                $this->getGeneroDisplayName($datosSociodemograficos['genero'] ?? null),
                $this->getRazaDisplayName($datosSociodemograficos['raza'] ?? null),
                $this->getNivelEducativoDisplayName($datosSociodemograficos['nivelEducativo'] ?? null),
                $datosSociodemograficos['numeroHijos'] ?? '',
                $datosSociodemograficos['numeroPersonasDependientes'] ?? '',
                $this->getViviendaDisplayName($datosSociodemograficos['vivienda'] ?? null),
                $datosSociodemograficos['estratoSocioeconomico'] ?? '',
                $this->getConviveConDisplayName($datosSociodemograficos['conviveCon'] ?? null),
                $this->getTransporteDisplayName($datosSociodemograficos['transporte'] ?? null),
                $this->getTiempoLibreConDisplayName($datosSociodemograficos['tiempoLibreCon'] ?? null),
                $this->formatSiNo($serviciosPublicos['agua'] ?? null),
                $this->formatSiNo($serviciosPublicos['luz'] ?? null),
                $this->formatSiNo($serviciosPublicos['telefono'] ?? null),
                $this->formatSiNo($serviciosPublicos['internet'] ?? null),
                $this->formatSiNo($serviciosPublicos['gas'] ?? null),
                $this->formatSiNo($manejoTiempoLibre['recreativas'] ?? null),
                $this->formatSiNo($manejoTiempoLibre['deportivas'] ?? null),
                $this->formatSiNo($manejoTiempoLibre['educativas'] ?? null),
                $this->formatSiNo($manejoTiempoLibre['descanso'] ?? null),
                $this->formatSiNo($manejoTiempoLibre['artisticas'] ?? null),
                $this->formatSiNo($manejoTiempoLibre['religiosas'] ?? null),
                $this->formatSiNo($manejoTiempoLibre['otras'] ?? null),
                
                // Datos de Consumo
                $this->formatSiNo($datosConsumo['consumoLicor'] ?? null),
                $this->getFrecuenciaDisplayName($datosConsumo['frecuenciaLicor'] ?? null),
                $this->formatSiNo($datosConsumo['consumoCigarrillo'] ?? null),
                $this->getFrecuenciaDisplayName($datosConsumo['frecuenciaCigarrillo'] ?? null),
                
                // Condiciones de Salud
                $this->formatSiNo($condicionesSalud['sobrepesoObesidad'] ?? null),
                $this->formatSiNo($condicionesSalud['hipertensionArterial'] ?? null),
                $this->formatSiNo($condicionesSalud['enfermedadesCorazon'] ?? null),
                $this->formatSiNo($condicionesSalud['diabetes'] ?? null),
                $this->formatSiNo($condicionesSalud['problemasRenales'] ?? null),
                $this->formatSiNo($condicionesSalud['depresionBipolaridad'] ?? null),
                $this->formatSiNo($condicionesSalud['antecedentesMedicosMentales'] ?? null),
                $this->formatSiNo($condicionesSalud['epilepsiaConvulsiones'] ?? null),
                $this->formatSiNo($condicionesSalud['trasplante'] ?? null),
                $condicionesSalud['tipoTrasplante'] ?? '',
                $this->formatSiNo($condicionesSalud['cancer'] ?? null),
                $this->formatSiNo($condicionesSalud['problemasPulmonares'] ?? null),
                $condicionesSalud['tipoProblemaPulmonar'] ?? '',
                $this->formatSiNo($condicionesSalud['alergias'] ?? null),
                $condicionesSalud['tipoAlergia'] ?? '',
                $this->formatSiNo($condicionesSalud['tuberculosis'] ?? null),
                $this->formatSiNo($condicionesSalud['problemasVisuales'] ?? null),
                $condicionesSalud['tipoProblemaVisual'] ?? '',
                $this->formatSiNo($condicionesSalud['doloresArticulares'] ?? null),
                $condicionesSalud['tipoDolorArticular'] ?? '',
                $this->formatSiNo($condicionesSalud['problemasSangre'] ?? null),
                $this->formatSiNo($condicionesSalud['otraEnfermedad'] ?? null),
                $condicionesSalud['tipoOtraEnfermedad'] ?? '',
                $this->formatSiNo($condicionesSalud['protesisArticular'] ?? null),
                $this->formatSiNo($condicionesSalud['medicamentoPermanente'] ?? null),
                $condicionesSalud['tipoMedicamento'] ?? '',
                $this->formatSiNo($condicionesSalud['tratamientoMedico'] ?? null),
                $this->formatSiNo($condicionesSalud['cirugias'] ?? null),
                $condicionesSalud['tipoCirugia'] ?? '',
                $condicionesSalud['tiempoCirugia'] ?? '',
                $this->formatSiNo($condicionesSalud['accidenteLaboral'] ?? null),
                $condicionesSalud['tipoAccidenteLaboral'] ?? '',
                $condicionesSalud['tiempoAccidenteLaboral'] ?? '',
                $this->formatSiNo($condicionesSalud['accidenteTransitoCasero'] ?? null),
                $condicionesSalud['tipoAccidenteTransito'] ?? '',
                $condicionesSalud['tiempoAccidenteTransito'] ?? '',
                $this->formatSiNo($condicionesSalud['vacunadoCovid'] ?? null),
                
                // Limitaciones Físicas
                $this->formatLimitacion($limitacionesFisicas['esfuerzosIntensos'] ?? null),
                $this->formatLimitacion($limitacionesFisicas['esfuerzosModerados'] ?? null),
                $this->formatLimitacion($limitacionesFisicas['subirPisos'] ?? null),
                $this->formatLimitacion($limitacionesFisicas['agacharseArrodillarse'] ?? null),
                
                // Recomendaciones Laborales
                $this->formatSiNo($survey->recomendacion_restriccion_laboral),
                $survey->detalle_recomendacion_laboral,
            ];

            // Agregar celda vacía para firma si se solicita
            if ($includeSignatures) {
                $data[] = ''; // Celda vacía, la imagen se embebirá después
            }

            // Calcular la columna de firma antes de escribir los datos
            $signatureCol = null;
            if ($includeSignatures) {
                // La columna de firma es la última columna (count($headers) porque getColumnLetter es 1-based)
                // Si hay 100 headers, la última columna es la 100, que corresponde a getColumnLetter(100)
                $signatureCol = $this->getColumnLetter(count($headers));
            }

            // Guardar la columna inicial para calcular la de firma después
            $initialCol = $col;
            $dataIndex = 0;
            
            foreach ($data as $value) {
                $sheet->setCellValue($col . $row, $value);
                
                // Si estamos en la última columna de datos (la de firma), guardar la columna
                if ($includeSignatures && $dataIndex === count($data) - 1) {
                    $signatureCol = $col; // Esta es la columna donde está la celda vacía de la firma
                }
                
                $col++;
                $dataIndex++;
            }

            // Embebir firma si se solicita y está disponible
            if ($includeSignatures && $survey->firma_path && $signatureCol) {
                try {
                    $this->embedSignature($sheet, $survey, $signatureCol . $row, $signatureWidth, $signatureHeight);
                    // Ajustar altura de fila para mostrar la firma
                    $sheet->getRowDimension($row)->setRowHeight(max(60, $signatureHeight + 10));
                } catch (\Exception $e) {
                    Log::warning('No se pudo embebir firma en reporte de encuesta', [
                        'survey_id' => $survey->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $row++;
        }

        // Ajustar ancho de columnas (más anchas para mejor legibilidad)
        $headerCount = count($headers);
        $colIndex = 0;
        foreach (range('A', 'ZZ') as $col) {
            if ($colIndex >= $headerCount) {
                break;
            }
            
            // Calcular ancho basado en la longitud del nombre del header
            $headerText = $headers[$colIndex] ?? '';
            $headerLength = mb_strlen($headerText);
            
            // Ancho mínimo de 15, máximo de 50
            // Para nombres largos (más de 30 caracteres), usar más espacio
            if ($headerLength > 30) {
                $width = min(50, max(30, $headerLength * 0.8));
            } elseif ($headerLength > 20) {
                $width = min(35, max(25, $headerLength * 0.9));
            } else {
                $width = max(15, $headerLength * 1.1);
            }
            
            // Columna de firma más ancha
            if ($includeSignatures && $colIndex === $headerCount - 1) {
                $width = 30;
            }
            
            $sheet->getColumnDimension($col)->setWidth($width);
            $colIndex++;
        }

        // Agregar autofiltros a todas las columnas
        $sheet->setAutoFilter('A1:' . $lastCol . '1');

        // Congelar primera fila
        $sheet->freezePane('A2');
    }

    /**
     * Construir hoja de estadísticas por tipo.
     */
    private function buildStatsByTypeSheet(Worksheet $sheet, Collection $surveys): void
    {
        $row = 1;

        // Título
        $sheet->setCellValue('A1', 'ESTADÍSTICAS POR TIPO DE ENCUESTA');
        $sheet->mergeCells('A1:C1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row = 3;

        // Encabezados
        $sheet->setCellValue('A' . $row, 'Tipo de Encuesta');
        $sheet->setCellValue('B' . $row, 'Cantidad');
        $sheet->setCellValue('C' . $row, 'Porcentaje');
        $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
        ]);
        $row++;

        $total = $surveys->count();
        $activeAffiliate = $surveys->where('survey_type', 'active_affiliate')->count() + $surveys->whereNull('survey_type')->count();
        $bulkEntry = $surveys->where('survey_type', 'bulk_entry')->count();

        $sheet->setCellValue('A' . $row, 'Afiliados Activos');
        $sheet->setCellValue('B' . $row, $activeAffiliate);
        $sheet->setCellValue('C' . $row, $total > 0 ? round(($activeAffiliate / $total) * 100, 2) . '%' : '0%');
        $row++;

        $sheet->setCellValue('A' . $row, 'Ingreso Masivo');
        $sheet->setCellValue('B' . $row, $bulkEntry);
        $sheet->setCellValue('C' . $row, $total > 0 ? round(($bulkEntry / $total) * 100, 2) . '%' : '0%');
        $row++;

        $sheet->setCellValue('A' . $row, 'TOTAL');
        $sheet->setCellValue('B' . $row, $total);
        $sheet->setCellValue('C' . $row, '100%');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);

        // Ajustar ancho de columnas
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(15);
    }

    /**
     * Construir hoja de estadísticas por mes.
     */
    private function buildStatsByMonthSheet(Worksheet $sheet, Collection $surveys): void
    {
        $row = 1;

        // Título
        $sheet->setCellValue('A1', 'ESTADÍSTICAS POR MES');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row = 3;

        // Encabezados
        $sheet->setCellValue('A' . $row, 'Mes');
        $sheet->setCellValue('B' . $row, 'Total');
        $sheet->setCellValue('C' . $row, 'Afiliados Activos');
        $sheet->setCellValue('D' . $row, 'Ingreso Masivo');
        $sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
        ]);
        $row++;

        // Agrupar por mes
        $byMonth = $surveys->groupBy(function ($survey) {
            return $survey->created_at?->format('Y-m') ?? 'Sin fecha';
        })->sortKeys();

        foreach ($byMonth as $month => $monthSurveys) {
            $monthLabel = $month !== 'Sin fecha' 
                ? $this->formatMonthInSpanish($month)
                : 'Sin fecha';
            
            $total = $monthSurveys->count();
            $activeAffiliate = $monthSurveys->where('survey_type', 'active_affiliate')->count() + $monthSurveys->whereNull('survey_type')->count();
            $newEntry = $monthSurveys->where('survey_type', 'new_entry')->count();
            $bulkEntry = $monthSurveys->where('survey_type', 'bulk_entry')->count(); // Compatibilidad con encuestas antiguas

            $sheet->setCellValue('A' . $row, $monthLabel);
            $sheet->setCellValue('B' . $row, $total);
            $sheet->setCellValue('C' . $row, $activeAffiliate);
            $sheet->setCellValue('D' . $row, $newEntry);
            $sheet->setCellValue('E' . $row, $bulkEntry);
            $row++;
        }

        // Totales
        $sheet->setCellValue('A' . $row, 'TOTAL');
        $sheet->setCellValue('B' . $row, $surveys->count());
        $sheet->setCellValue('C' . $row, $surveys->where('survey_type', 'active_affiliate')->count() + $surveys->whereNull('survey_type')->count());
        $sheet->setCellValue('D' . $row, $surveys->where('survey_type', 'new_entry')->count());
        $sheet->setCellValue('E' . $row, $surveys->where('survey_type', 'bulk_entry')->count());
        $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);

        // Ajustar ancho de columnas
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
    }

    /**
     * Construir hoja unificada de estadísticas (por tipo y por mes).
     */
    private function buildStatsSheet(Worksheet $sheet, Collection $surveys): void
    {
        $row = 1;

        // ===== ESTADÍSTICAS POR TIPO =====
        // Título
        $sheet->setCellValue('A1', 'ESTADÍSTICAS POR TIPO DE ENCUESTA');
        $sheet->mergeCells('A1:C1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row = 3;

        // Encabezados
        $sheet->setCellValue('A' . $row, 'Tipo de Encuesta');
        $sheet->setCellValue('B' . $row, 'Cantidad');
        $sheet->setCellValue('C' . $row, 'Porcentaje');
        $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
        ]);
        $row++;

        $total = $surveys->count();
        $activeAffiliate = $surveys->where('survey_type', 'active_affiliate')->count() + $surveys->whereNull('survey_type')->count();
        $newEntry = $surveys->where('survey_type', 'new_entry')->count();
        $bulkEntry = $surveys->where('survey_type', 'bulk_entry')->count(); // Compatibilidad con encuestas antiguas

        $sheet->setCellValue('A' . $row, 'Afiliados Activos');
        $sheet->setCellValue('B' . $row, $activeAffiliate);
        $sheet->setCellValue('C' . $row, $total > 0 ? round(($activeAffiliate / $total) * 100, 2) . '%' : '0%');
        $row++;

        $sheet->setCellValue('A' . $row, 'Nuevo Ingreso');
        $sheet->setCellValue('B' . $row, $newEntry);
        $sheet->setCellValue('C' . $row, $total > 0 ? round(($newEntry / $total) * 100, 2) . '%' : '0%');
        $row++;

        if ($bulkEntry > 0) {
            $sheet->setCellValue('A' . $row, 'Ingreso Masivo (legacy)');
            $sheet->setCellValue('B' . $row, $bulkEntry);
            $sheet->setCellValue('C' . $row, $total > 0 ? round(($bulkEntry / $total) * 100, 2) . '%' : '0%');
            $row++;
        }

        $sheet->setCellValue('A' . $row, 'TOTAL');
        $sheet->setCellValue('B' . $row, $total);
        $sheet->setCellValue('C' . $row, '100%');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);

        // Espacio entre secciones
        $row += 3;

        // ===== ESTADÍSTICAS POR MES =====
        // Título
        $sheet->setCellValue('A' . $row, 'ESTADÍSTICAS POR MES');
        $sheet->mergeCells('A' . $row . ':E' . $row);
        $sheet->getStyle('A' . $row)->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row += 2;

        // Encabezados
        $sheet->setCellValue('A' . $row, 'Mes');
        $sheet->setCellValue('B' . $row, 'Total');
        $sheet->setCellValue('C' . $row, 'Afiliados Activos');
        $sheet->setCellValue('D' . $row, 'Nuevo Ingreso');
        $sheet->setCellValue('E' . $row, 'Ingreso Masivo (legacy)');
        $sheet->getStyle('A' . $row . ':E' . $row)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
        ]);
        $row++;

        // Agrupar por mes
        $byMonth = $surveys->groupBy(function ($survey) {
            return $survey->created_at?->format('Y-m') ?? 'Sin fecha';
        })->sortKeys();

        foreach ($byMonth as $month => $monthSurveys) {
            $monthLabel = $month !== 'Sin fecha' 
                ? $this->formatMonthInSpanish($month)
                : 'Sin fecha';
            
            $totalMonth = $monthSurveys->count();
            $activeAffiliateMonth = $monthSurveys->where('survey_type', 'active_affiliate')->count() + $monthSurveys->whereNull('survey_type')->count();
            $newEntryMonth = $monthSurveys->where('survey_type', 'new_entry')->count();
            $bulkEntryMonth = $monthSurveys->where('survey_type', 'bulk_entry')->count(); // Compatibilidad con encuestas antiguas

            $sheet->setCellValue('A' . $row, $monthLabel);
            $sheet->setCellValue('B' . $row, $totalMonth);
            $sheet->setCellValue('C' . $row, $activeAffiliateMonth);
            $sheet->setCellValue('D' . $row, $newEntryMonth);
            $sheet->setCellValue('E' . $row, $bulkEntryMonth);
            $row++;
        }

        // Totales
        $sheet->setCellValue('A' . $row, 'TOTAL');
        $sheet->setCellValue('B' . $row, $surveys->count());
        $sheet->setCellValue('C' . $row, $surveys->where('survey_type', 'active_affiliate')->count() + $surveys->whereNull('survey_type')->count());
        $sheet->setCellValue('D' . $row, $surveys->where('survey_type', 'new_entry')->count());
        $sheet->setCellValue('E' . $row, $surveys->where('survey_type', 'bulk_entry')->count());
        $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);

        // Ajustar ancho de columnas
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(25);
    }

    /**
     * Construir hoja de beneficiarios.
     */
    private function buildBeneficiariosSheet(Worksheet $sheet, Collection $surveys): void
    {
        $row = 1;

        // Encabezados
        $headers = [
            'Documento afiliado',
            'Tipo documento',
            'Documento',
            'Nombres',
            'Apellidos',
            'Fecha nacimiento',
            'Sexo',
            'Notas',
        ];

        $col = 'A';
        $lastCol = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E0E0E0'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ]);
            $lastCol = $col;
            $col++;
        }
        $row++;

        // Recopilar todos los beneficiarios de todas las encuestas
        foreach ($surveys as $survey) {
            $datosSociodemograficos = $survey->datos_sociodemograficos ?? [];
            $hijos = $datosSociodemograficos['hijos'] ?? [];

            if (empty($hijos) || !is_array($hijos)) {
                continue;
            }

            // Documento del afiliado
            $documentoAfiliado = $survey->numero_documento ?? '';

            foreach ($hijos as $hijo) {
                if (empty($hijo) || !is_array($hijo)) {
                    continue;
                }

                // Tipo documento del hijo
                $tipoDocumento = $hijo['tipoDocumento'] ?? '';
                // Normalizar tipo documento (puede venir como NUIP, CC, TI, RC, etc.)
                $tipoDocumentoNormalizado = strtoupper(trim($tipoDocumento));
                if ($tipoDocumentoNormalizado === 'NUIP') {
                    $tipoDocumentoNormalizado = 'NUIP';
                } elseif (in_array($tipoDocumentoNormalizado, ['CC', 'TI', 'RC', 'CE', 'PA', 'PT'])) {
                    $tipoDocumentoNormalizado = $tipoDocumentoNormalizado;
                } else {
                    // Si no es reconocido, mantener el valor original
                    $tipoDocumentoNormalizado = $tipoDocumento;
                }

                // Documento del hijo
                $documento = $hijo['numeroDocumento'] ?? '';

                // Nombre completo del hijo
                $nombreCompleto = $hijo['nombre'] ?? '';
                
                // Intentar dividir nombre en nombres y apellidos
                // Generalmente los apellidos son las últimas 1-2 palabras
                $nombres = '';
                $apellidos = '';
                
                if (!empty($nombreCompleto)) {
                    $partes = preg_split('/\s+/', trim($nombreCompleto));
                    $numPartes = count($partes);
                    
                    if ($numPartes > 2) {
                        // Si hay más de 2 palabras, asumimos que las últimas 2 son apellidos
                        // y las anteriores son nombres
                        $ultimoApellido = array_pop($partes);
                        $penultimoApellido = array_pop($partes);
                        $apellidos = $penultimoApellido . ' ' . $ultimoApellido;
                        $nombres = implode(' ', $partes);
                    } elseif ($numPartes === 2) {
                        // Si hay 2 palabras, asumimos que la primera es nombre y la segunda es apellido
                        $nombres = $partes[0];
                        $apellidos = $partes[1];
                    } else {
                        // Si solo hay una palabra, ponerla en nombres
                        $nombres = $nombreCompleto;
                    }
                }

                // Fecha de nacimiento
                $fechaNacimiento = $hijo['fechaNacimiento'] ?? '';
                // Formatear fecha si está disponible
                if (!empty($fechaNacimiento)) {
                    try {
                        $fecha = Carbon::parse($fechaNacimiento);
                        $fechaNacimiento = $fecha->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Si no se puede parsear, mantener el valor original
                    }
                }

                // Género/Sexo
                $genero = $hijo['genero'] ?? '';
                $sexo = '';
                if (!empty($genero)) {
                    $generoLower = strtolower(trim($genero));
                    if (in_array($generoLower, ['masculino', 'm', 'male'])) {
                        $sexo = 'M';
                    } elseif (in_array($generoLower, ['femenino', 'f', 'female'])) {
                        $sexo = 'F';
                    } else {
                        $sexo = strtoupper(substr($genero, 0, 1));
                    }
                }

                // Notas (parentesco) - basado en el género
                $notas = '';
                if ($sexo === 'M') {
                    $notas = 'HIJO';
                } elseif ($sexo === 'F') {
                    $notas = 'HIJA';
                }

                // Escribir fila
                $sheet->setCellValue('A' . $row, $documentoAfiliado);
                $sheet->setCellValue('B' . $row, $tipoDocumentoNormalizado);
                $sheet->setCellValue('C' . $row, $documento);
                $sheet->setCellValue('D' . $row, $nombres);
                $sheet->setCellValue('E' . $row, $apellidos);
                $sheet->setCellValue('F' . $row, $fechaNacimiento);
                $sheet->setCellValue('G' . $row, $sexo);
                $sheet->setCellValue('H' . $row, $notas);

                $row++;
            }
        }

        // Ajustar ancho de columnas
        $sheet->getColumnDimension('A')->setWidth(18); // Documento afiliado
        $sheet->getColumnDimension('B')->setWidth(15); // Tipo documento
        $sheet->getColumnDimension('C')->setWidth(18); // Documento
        $sheet->getColumnDimension('D')->setWidth(25); // Nombres
        $sheet->getColumnDimension('E')->setWidth(25); // Apellidos
        $sheet->getColumnDimension('F')->setWidth(18); // Fecha nacimiento
        $sheet->getColumnDimension('G')->setWidth(10); // Sexo
        $sheet->getColumnDimension('H')->setWidth(15); // Notas

        // Agregar autofiltros a todas las columnas
        $sheet->setAutoFilter('A1:' . $lastCol . '1');

        // Congelar primera fila
        $sheet->freezePane('A2');
    }

    /**
     * Transformar valores Sí/No.
     */
    private function formatSiNo($value): string
    {
        if ($value === null || $value === '') {
            return 'No especificado';
        }

        $normalized = strtolower(trim((string) $value));
        
        if (in_array($normalized, ['si', 'sí', 'yes', 'true', '1', '1.0'])) {
            return 'Sí';
        }
        
        if (in_array($normalized, ['no', 'false', '0', '0.0'])) {
            return 'No';
        }

        return 'No especificado';
    }

    /**
     * Transformar tipo de vivienda.
     */
    private function getViviendaDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'propia' => 'Propia',
            'arrendada' => 'Arrendada',
            'familiar' => 'Familiar',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar convive con.
     */
    private function getConviveConDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'familia_origen' => 'Familia de origen',
            'nueva_familia' => 'Nueva familia (cónyuge e hijos)',
            'ambas' => 'Las dos anteriores',
            'amigos' => 'Amigos',
            'otros_familiares' => 'Otros familiares',
            'solo' => 'Vive solo',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar transporte.
     */
    private function getTransporteDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'carro' => 'Carro',
            'motocicleta' => 'Motocicleta',
            'bicicleta' => 'Bicicleta',
            'transporte_publico' => 'Transporte público',
            'caminando' => 'Caminando',
            'otra' => 'Otra',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar tiempo libre con.
     */
    private function getTiempoLibreConDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'familia' => 'Con la familia',
            'pareja' => 'Con la pareja',
            'amigos' => 'Con amigos',
            'solo' => 'Solo',
            'otros' => 'Otros',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar género.
     */
    private function getGeneroDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'masculino' => 'Masculino',
            'femenino' => 'Femenino',
            'otro' => 'Otro',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar relación contacto emergencia.
     */
    private function getRelacionContactoEmergenciaDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'conyuge' => 'Cónyuge',
            'esposo' => 'Cónyuge',
            'esposa' => 'Cónyuge',
            'padre' => 'Padre',
            'madre' => 'Madre',
            'hijo' => 'Hijo/a',
            'hija' => 'Hijo/a',
            'hermano' => 'Hermano/a',
            'hermana' => 'Hermano/a',
            'abuelo' => 'Abuelo/a',
            'abuela' => 'Abuelo/a',
            'tio' => 'Tío/a',
            'tia' => 'Tío/a',
            'primo' => 'Primo/a',
            'prima' => 'Primo/a',
            'amigo' => 'Amigo/a',
            'amiga' => 'Amigo/a',
            'otro' => 'Otro',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar frecuencia.
     */
    private function getFrecuenciaDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'diario' => 'Diario',
            'varias_veces_semana' => 'Varias veces en la semana',
            'fines_semana' => 'Fines de semana',
            'cada_quince_dias' => 'Cada quince días',
            'ocasionalmente' => 'Ocasionalmente',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar raza/grupo étnico.
     */
    private function getRazaDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'ninguno' => 'Ninguno',
            'afro' => 'Afrocolombiano',
            'indigena' => 'Indígena',
            'otro' => 'Otro',
            'no_responde' => 'Prefiere no responder',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar nivel educativo.
     */
    private function getNivelEducativoDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'primaria' => 'Primaria',
            'bachiller' => 'Bachiller',
            'tecnico' => 'Técnico',
            'tecnologo' => 'Tecnólogo',
            'profesional' => 'Profesional',
            'especialista' => 'Especialista',
            'maestria' => 'Maestría',
            'doctorado' => 'Doctorado',
        ];

        return $map[strtolower(trim($value))] ?? ucfirst($value);
    }

    /**
     * Transformar estado civil.
     */
    private function getEstadoCivilDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'soltero' => 'Soltero/a',
            'casado' => 'Casado/a',
            'divorciado' => 'Divorciado/a',
            'viudo' => 'Viudo/a',
            'union_libre' => 'Unión libre',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar talla vestimenta.
     */
    private function getTallaVestimentaDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'xs' => 'XS',
            's' => 'S',
            'm' => 'M',
            'l' => 'L',
            'xl' => 'XL',
            'xxl' => 'XXL',
            'xxxl' => 'XXXL',
            '4xl' => '4XL',
            '5xl' => '5XL',
        ];

        return $map[strtolower(trim($value))] ?? strtoupper($value);
    }

    /**
     * Transformar tipo de encuesta.
     */
    private function getSurveyTypeDisplayName(?string $value): string
    {
        if (!$value || $value === 'active_affiliate') {
            return 'Afiliados Activos';
        }

        if ($value === 'new_entry') {
            return 'Nuevo Ingreso';
        }

        if ($value === 'bulk_entry') {
            return 'Ingreso Masivo (legacy)';
        }

        return 'Afiliados Activos';
    }

    /**
     * Transformar tipo de documento.
     */
    private function getTipoDocumentoDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'CC' => 'Cédula de Ciudadanía',
            'TI' => 'Tarjeta de Identidad',
            'CE' => 'Cédula de Extranjería',
            'PA' => 'Pasaporte',
            'PT' => 'Permiso por Protección Temporal',
            'RC' => 'Registro Civil',
            'NUIP' => 'Número Único de Identificación Personal',
        ];

        return $map[strtoupper(trim($value))] ?? $value;
    }

    /**
     * Transformar país de nacimiento.
     */
    private function getPaisDisplayName(?string $value): string
    {
        if (!$value) {
            return '';
        }

        $map = [
            'colombia' => 'Colombia',
            'venezuela' => 'Venezuela',
            'ecuador' => 'Ecuador',
            'peru' => 'Perú',
            'brasil' => 'Brasil',
            'argentina' => 'Argentina',
            'chile' => 'Chile',
            'panama' => 'Panamá',
            'costa_rica' => 'Costa Rica',
            'nicaragua' => 'Nicaragua',
            'honduras' => 'Honduras',
            'guatemala' => 'Guatemala',
            'el_salvador' => 'El Salvador',
            'mexico' => 'México',
            'cuba' => 'Cuba',
            'republica_dominicana' => 'República Dominicana',
            'puerto_rico' => 'Puerto Rico',
            'bolivia' => 'Bolivia',
            'paraguay' => 'Paraguay',
            'uruguay' => 'Uruguay',
            'estados_unidos' => 'Estados Unidos',
            'canada' => 'Canadá',
            'espana' => 'España',
            'francia' => 'Francia',
            'italia' => 'Italia',
            'alemania' => 'Alemania',
            'reino_unido' => 'Reino Unido',
            'portugal' => 'Portugal',
            'holanda' => 'Holanda',
            'belgica' => 'Bélgica',
            'suiza' => 'Suiza',
            'australia' => 'Australia',
            'nueva_zelanda' => 'Nueva Zelanda',
            'japon' => 'Japón',
            'china' => 'China',
            'india' => 'India',
            'rusia' => 'Rusia',
            'corea_del_sur' => 'Corea del Sur',
            'filipinas' => 'Filipinas',
            'indonesia' => 'Indonesia',
            'tailandia' => 'Tailandia',
            'singapur' => 'Singapur',
            'malasia' => 'Malasia',
            'vietnam' => 'Vietnam',
            'israel' => 'Israel',
            'turquia' => 'Turquía',
            'egipto' => 'Egipto',
            'sudafrica' => 'Sudáfrica',
            'nigeria' => 'Nigeria',
            'kenia' => 'Kenia',
            'marruecos' => 'Marruecos',
            'argelia' => 'Argelia',
            'tunez' => 'Túnez',
            'otro' => 'Otro',
        ];

        return $map[strtolower(trim($value))] ?? ucfirst(str_replace('_', ' ', $value));
    }

    /**
     * Transformar limitación física.
     */
    private function formatLimitacion(?string $value): string
    {
        if (!$value) {
            return 'No especificado';
        }

        $map = [
            'limita_mucho' => 'Me limita mucho',
            'limita_poco' => 'Me limita un poco',
            'no_limita' => 'No me limita nada',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Formatear mes en español (formato: "Enero 2026").
     */
    private function formatMonthInSpanish(string $monthYear): string
    {
        try {
            $date = Carbon::parse($monthYear . '-01');
            $year = $date->year;
            
            $months = [
                1 => 'Enero',
                2 => 'Febrero',
                3 => 'Marzo',
                4 => 'Abril',
                5 => 'Mayo',
                6 => 'Junio',
                7 => 'Julio',
                8 => 'Agosto',
                9 => 'Septiembre',
                10 => 'Octubre',
                11 => 'Noviembre',
                12 => 'Diciembre',
            ];
            
            $monthNumber = $date->month;
            $monthName = $months[$monthNumber] ?? $date->format('F');
            
            return $monthName . ' ' . $year;
        } catch (\Exception $e) {
            return $monthYear;
        }
    }

    /**
     * Embebir imagen de firma en celda de Excel.
     */
    private function embedSignature(
        Worksheet $sheet,
        SocioDemographicSurvey $survey,
        string $cell,
        int $width,
        int $height
    ): void {
        if (!$survey->firma_path) {
            return;
        }

        $disk = Storage::disk(self::SIGNATURE_DISK);

        if (!$disk->exists($survey->firma_path)) {
            throw new \Exception('Firma no encontrada en almacenamiento');
        }

        // Get file content
        $imageContent = $disk->get($survey->firma_path);

        // Check size
        if (strlen($imageContent) > self::MAX_IMAGE_SIZE) {
            throw new \Exception('Imagen excede el tamaño máximo permitido');
        }

        // Detect image type
        $extension = strtolower(pathinfo($survey->firma_path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
            $imageInfo = @getimagesizefromstring($imageContent);
            if ($imageInfo && isset($imageInfo['mime'])) {
                $mime = $imageInfo['mime'];
                if ($mime === 'image/png') {
                    $extension = 'png';
                } elseif (in_array($mime, ['image/jpeg', 'image/jpg'])) {
                    $extension = 'jpg';
                } else {
                    $extension = 'png'; // Default
                }
            } else {
                $extension = 'png'; // Default
            }
        }

        // Create temporary file for image
        $tempImageFile = tempnam(sys_get_temp_dir(), 'survey_signature_') . '.' . $extension;
        file_put_contents($tempImageFile, $imageContent);

        // Track temp file for cleanup
        $this->tempImageFiles[] = $tempImageFile;

        // Create drawing object
        $drawing = new Drawing();
        $drawing->setPath($tempImageFile);
        $drawing->setCoordinates($cell);
        $drawing->setWidth($width);
        $drawing->setHeight($height);
        $drawing->setOffsetX(5);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }

    /**
     * Obtener letra de columna basada en índice numérico (1 = A, 27 = AA, etc.).
     */
    private function getColumnLetter(int $columnIndex): string
    {
        $letter = '';
        while ($columnIndex > 0) {
            $columnIndex--;
            $letter = chr(65 + ($columnIndex % 26)) . $letter;
            $columnIndex = intval($columnIndex / 26);
        }
        return $letter;
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

