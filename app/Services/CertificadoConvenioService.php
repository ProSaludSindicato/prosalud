<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\{Log, Storage};
use PhpOffice\PhpWord\{IOFactory as WordIOFactory, TemplateProcessor};
use PhpOffice\PhpSpreadsheet\{IOFactory, Cell\Coordinate};

class CertificadoConvenioService
{
    private const TEMPLATE_PATH = 'resources/templates/certificado_convenio_template.docx';
    private const TEMP_DIR = 'temp';

    public function __construct(
        private readonly AfiliadoService $afiliadoService
    ) {
    }

    /**
     * Genera un certificado de convenio desde la plantilla Word
     * NOTA: Por ahora solo genera Word. La conversión a PDF se implementará después
     * cuando se confirme que la plantilla Word funciona correctamente.
     */
    public function generarCertificadoPDF(string $documento): array
    {
        // Por ahora, generar Word desde la plantilla Word
        // La conversión a PDF se implementará en el futuro
        return $this->generarCertificadoWord($documento);
    }

    /**
     * Genera un certificado de convenio en formato Word
     */
    public function generarCertificadoWord(string $documento): array
    {
        // Obtener información del afiliado
        $afiliadoData = $this->obtenerDatosAfiliado($documento);

        if (!$afiliadoData) {
            throw new \Exception("Afiliado con documento {$documento} no encontrado");
        }

        // Preparar datos
        $datos = $this->prepararDatosCertificado($afiliadoData);

        // Cargar plantilla
        $templatePath = $this->obtenerRutaPlantilla();
        if (!file_exists($templatePath)) {
            throw new \Exception("Plantilla no encontrada en: {$templatePath}. Por favor, coloca la plantilla en resources/templates/certificado_convenio_template.docx");
        }

        // Crear procesador de plantilla
        $templateProcessor = new TemplateProcessor($templatePath);

        // Reemplazar placeholders
        foreach ($datos as $key => $value) {
            $templateProcessor->setValue($key, $value ?? '');
        }

        // Generar nombre de archivo
        $nombreArchivo = $this->generarNombreArchivo($afiliadoData);
        $rutaSalida = storage_path("app/" . self::TEMP_DIR . "/{$nombreArchivo}");

        // Crear directorio si no existe
        $tempDir = storage_path("app/" . self::TEMP_DIR);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Guardar documento
        $templateProcessor->saveAs($rutaSalida);

        return [
            'ruta' => $rutaSalida,
            'nombre' => $nombreArchivo,
            'tipo' => 'docx',
        ];
    }

    /**
     * Obtiene los datos del afiliado por documento (versión optimizada para memoria)
     */
    private function obtenerDatosAfiliado(string $documento): ?array
    {
        $excelPath = 'data/PROSANET_INFORMACION_AFILIADOS.xlsx';
        $disks = ['prosalud-private', 'local'];
        
        $originalMemoryLimit = ini_get('memory_limit');
        $originalMaxExecutionTime = ini_get('max_execution_time');

        try {
            // Aumentar memoria temporalmente
            ini_set('memory_limit', '512M');
            set_time_limit(60);

            foreach ($disks as $disk) {
                try {
                    if (!Storage::disk($disk)->exists($excelPath)) {
                        continue;
                    }

                    // Crear copia temporal
                    $stream = Storage::disk($disk)->readStream($excelPath);
                    if (false === $stream) {
                        continue;
                    }

                    $tempPath = tempnam(sys_get_temp_dir(), 'prosanet_certificado_') . '.xlsx';
                    $destination = fopen($tempPath, 'w+b');
                    if (false === $destination) {
                        fclose($stream);
                        continue;
                    }

                    stream_copy_to_stream($stream, $destination);
                    fclose($stream);
                    fclose($destination);

                    try {
                        // Usar reader optimizado
                        $reader = IOFactory::createReader('Xlsx');
                        
                        // Leer solo datos, no fórmulas ni formato (ahorra memoria)
                        if (method_exists($reader, 'setReadDataOnly')) {
                            $reader->setReadDataOnly(true);
                        }
                        
                        // Cargar solo las hojas necesarias
                        if (method_exists($reader, 'setLoadSheetsOnly')) {
                            $reader->setLoadSheetsOnly(['INFORMACIÓN GENERAL', 'CONVENIOS']);
                        }

                        $spreadsheet = $reader->load($tempPath);
                        $informacionSheet = $spreadsheet->getSheetByName('INFORMACIÓN GENERAL');

                        if (!$informacionSheet) {
                            @unlink($tempPath);
                            continue;
                        }

                        $highestRow = $informacionSheet->getHighestRow();
                        $normalizedDocumento = $this->normalizeDocumento($documento);

                        // Buscar por documento leyendo solo la columna B primero (optimización)
                        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
                            // Leer solo la columna del documento primero
                            $cell = $informacionSheet->getCell('B' . $rowIndex);
                            $rowDocumento = $this->normalizeDocumento($cell->getValue());

                            if ($rowDocumento === $normalizedDocumento) {
                                // Encontrado, leer solo las columnas necesarias
                                $rowData = [];
                                // Solo leer las columnas que necesitamos: 0,1,2,3,4,8,10,18,20
                                $neededColumns = [0, 1, 2, 3, 4, 8, 10, 18, 20];
                                foreach ($neededColumns as $colIndex) {
                                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                                    $cell = $informacionSheet->getCell($colLetter . $rowIndex);
                                    $rowData[$colIndex] = $cell->getValue();
                                }

                                // Completar array con valores vacíos para mantener compatibilidad
                                $fullRowData = array_fill(0, 39, '');
                                foreach ($rowData as $index => $value) {
                                    $fullRowData[$index] = $value;
                                }

                                $afiliadoFull = $this->extractAfiliadoInfo($fullRowData);

                                // Obtener convenios (solo leer la hoja de convenios si es necesario)
                                $conveniosSheet = $spreadsheet->getSheetByName('CONVENIOS');
                                $conveniosFull = [];
                                if ($conveniosSheet) {
                                    $conveniosFull = $this->getConveniosByDocumentoOptimized($conveniosSheet, $documento);
                                }

                                // Liberar memoria explícitamente
                                $spreadsheet->disconnectWorksheets();
                                unset($spreadsheet);
                                @unlink($tempPath);

                                return [
                                    'afiliado' => $afiliadoFull,
                                    'convenio' => !empty($conveniosFull) ? $conveniosFull[0] : null,
                                ];
                            }
                        }

                        // Liberar memoria si no se encontró
                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);
                        @unlink($tempPath);
                    } catch (\Throwable $e) {
                        @unlink($tempPath);
                        Log::error('Error al procesar Excel para certificado', [
                            'documento' => $documento,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('Error al acceder al archivo Excel para certificado', [
                        'documento' => $documento,
                        'disk' => $disk,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return null;
        } finally {
            // Restaurar límites originales
            if (false !== $originalMemoryLimit && null !== $originalMemoryLimit) {
                ini_set('memory_limit', (string) $originalMemoryLimit);
            }
            if (false !== $originalMaxExecutionTime && null !== $originalMaxExecutionTime) {
                set_time_limit((int) $originalMaxExecutionTime);
            }
        }
    }

    /**
     * Extrae información del afiliado desde una fila del Excel
     * Columnas según AfiliadoService:
     * 0: tipo_documento, 1: documento, 2: nombres, 3: apellidos, 4: estado,
     * 8: sexo, 10: fecha_ingreso, 18: correo_personal, 20: fecha_liquidacion
     */
    private function extractAfiliadoInfo(array $row): array
    {
        return [
            'tipo_documento' => $this->normalizeValue($row[0] ?? ''),
            'documento' => $this->normalizeValue($row[1] ?? ''),
            'nombres' => $this->normalizeValue($row[2] ?? ''),
            'apellidos' => $this->normalizeValue($row[3] ?? ''),
            'estado' => $this->normalizeValue($row[4] ?? ''),
            'sexo' => $this->normalizeValue($row[8] ?? ''),
            'fecha_ingreso' => $this->normalizeDate($row[10] ?? ''),
            'correo_personal' => $this->normalizeValue($row[18] ?? ''),
            'fecha_liquidacion' => $this->normalizeDate($row[20] ?? ''),
        ];
    }

    /**
     * Obtiene convenios por documento (versión optimizada)
     */
    private function getConveniosByDocumentoOptimized($sheet, string $documento): array
    {
        $convenios = [];
        $normalizedDocumento = $this->normalizeDocumento($documento);
        $highestRow = $sheet->getHighestRow();

        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
            $cell = $sheet->getCell('A' . $rowIndex);
            $rowDocumento = $this->normalizeDocumento($cell->getValue());

            if ($rowDocumento === $normalizedDocumento) {
                $convenios[] = [
                    'cliente' => $this->normalizeValue($sheet->getCell('D' . $rowIndex)->getValue() ?? ''),
                    'proceso' => $this->normalizeValue($sheet->getCell('F' . $rowIndex)->getValue() ?? ''),
                    'estado' => $this->normalizeValue($sheet->getCell('G' . $rowIndex)->getValue() ?? ''),
                    'fecha_fin' => $this->normalizeDate($sheet->getCell('I' . $rowIndex)->getValue() ?? ''),
                ];
            }
        }

        // Seleccionar convenio activo o el más reciente
        $selectedConvenio = null;
        foreach ($convenios as $conv) {
            if (strcasecmp($conv['estado'], 'Activo') === 0) {
                $selectedConvenio = $conv;
                break;
            }
        }

        if (!$selectedConvenio && !empty($convenios)) {
            // Ordenar por fecha_fin descendente y tomar el primero
            usort($convenios, function ($a, $b) {
                return strcmp($b['fecha_fin'], $a['fecha_fin']);
            });
            $selectedConvenio = $convenios[0];
        }

        return $selectedConvenio ? [$selectedConvenio] : [];
    }

    /**
     * Prepara los datos del certificado
     */
    private function prepararDatosCertificado(array $afiliadoData): array
    {
        $afiliado = $afiliadoData['afiliado'];
        $convenio = $afiliadoData['convenio'] ?? null;

        // Formatear fechas
        $fechaIngreso = $this->formatearFechaEspanol($afiliado['fecha_ingreso'] ?? null);
        $fechaRetiro = $this->formatearFechaEspanol($afiliado['fecha_liquidacion'] ?? null);
        $fechaCertificado = $this->formatearFechaEspanol(now());

        // Nombre completo en mayúsculas
        $nombreCompleto = strtoupper(
            trim(($afiliado['nombres'] ?? '') . ' ' . ($afiliado['apellidos'] ?? ''))
        );

        // Documento formateado con puntos
        $documento = $this->formatearDocumento($afiliado['documento'] ?? '');

        // Lugar de trabajo
        $lugarTrabajo = $convenio['cliente'] ?? 'No asignado';
        
        // Cargo
        $cargo = $convenio['proceso'] ?? '';

        // Texto de fecha de retiro
        $textoFechaRetiro = $fechaRetiro ? ", hasta {$fechaRetiro}" : '';

        // Campos adicionales
        $correoPersonal = $afiliado['correo_personal'] ?? '';
        $sexo = $afiliado['sexo'] ?? '';
        $estado = $afiliado['estado'] ?? '';

        return [
            'NOMBRE_COMPLETO' => $nombreCompleto,
            'DOCUMENTO' => $documento,
            'FECHA_INGRESO' => $fechaIngreso,
            'FECHA_RETIRO' => $textoFechaRetiro,
            'LUGAR_TRABAJO' => $lugarTrabajo,
            'CARGO' => strtoupper($cargo),
            'FECHA_CERTIFICADO' => $fechaCertificado,
            'DIA_CERTIFICADO' => now()->day,
            'MES_CERTIFICADO' => $this->obtenerMesEspanol(now()->month),
            'ANIO_CERTIFICADO' => now()->year,
            'CONSECUTIVO' => $this->generarConsecutivo(),
            // Campos adicionales
            'CORREO_PERSONAL' => $correoPersonal,
            'SEXO' => strtoupper($sexo),
            'ESTADO' => strtoupper($estado),
        ];
    }

    /**
     * Formatea fecha en español
     */
    private function formatearFechaEspanol($fecha): string
    {
        if (!$fecha) {
            return '';
        }

        try {
            $carbon = Carbon::parse($fecha);
            $mes = $this->obtenerMesEspanol($carbon->month);

            return "{$carbon->day} de {$mes} de {$carbon->year}";
        } catch (\Exception $e) {
            Log::warning('Error formateando fecha', [
                'fecha' => $fecha,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Obtiene el nombre del mes en español
     */
    private function obtenerMesEspanol(int $mes): string
    {
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
        ];

        return $meses[$mes] ?? '';
    }

    /**
     * Formatea documento con puntos
     */
    private function formatearDocumento(string $documento): string
    {
        // Remover puntos y espacios existentes
        $doc = preg_replace('/[.\s]/', '', $documento);

        // Si es numérico, formatear con puntos
        if (is_numeric($doc)) {
            return number_format((int) $doc, 0, '', '.');
        }

        return $documento;
    }

    /**
     * Genera nombre de archivo
     */
    private function generarNombreArchivo(array $afiliadoData): string
    {
        $documento = preg_replace('/[^0-9]/', '', $afiliadoData['afiliado']['documento'] ?? 'sin_doc');
        $fecha = now()->format('Ymd');

        return "certificado_convenio_{$documento}_{$fecha}.docx";
    }

    /**
     * Genera consecutivo
     */
    private function generarConsecutivo(): string
    {
        // Implementar lógica de consecutivos (puede ser desde BD)
        // Por ahora, usar fecha + número aleatorio
        return now()->format('Ymd') . rand(1000, 9999);
    }

    /**
     * Obtiene la ruta de la plantilla
     */
    private function obtenerRutaPlantilla(): string
    {
        // La plantilla está en resources/templates (bajo control de versiones)
        $templatePath = base_path(self::TEMPLATE_PATH);
        
        if (!file_exists($templatePath)) {
            throw new \Exception("Plantilla no encontrada en: {$templatePath}. Por favor, coloca la plantilla en resources/templates/certificado_convenio_template.docx");
        }

        return $templatePath;
    }


    /**
     * Normaliza un valor (similar al método en AfiliadoService)
     */
    private function normalizeValue($value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Normaliza un documento removiendo puntos, espacios y caracteres especiales
     */
    private function normalizeDocumento($value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        // Convertir a string, remover puntos, espacios y guiones
        $normalized = preg_replace('/[.\s-]/', '', (string) $value);
        
        return trim($normalized);
    }

    /**
     * Normaliza una fecha (similar al método en AfiliadoService)
     */
    private function normalizeDate($value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            // Intentar parsear como fecha Excel
            if (is_numeric($value)) {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);

                return $date->format('Y-m-d');
            }

            // Intentar parsear como string de fecha
            $carbon = Carbon::parse($value);

            return $carbon->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }
}

