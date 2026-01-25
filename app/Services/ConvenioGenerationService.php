<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\{Log, Storage};
use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpSpreadsheet\{IOFactory, Cell\Coordinate};

class ConvenioGenerationService
{
    private const TEMPLATE_PATH = 'resources/templates/Plantilla_convenios.docx';
    private const OUTPUT_DIR = 'resources/convenios';
    private const AFILIADOS_FILE_PATH = 'data/PROSANET_INFORMACION_AFILIADOS.xlsx';

    public function __construct(
        private AfiliadoService $afiliadoService
    ) {
    }

    /**
     * Genera un convenio desde datos proporcionados
     *
     * @param array $data Datos del convenio
     * @return array Resultado con ruta y nombre del archivo generado
     */
    public function generarConvenio(array $data): array
    {
        $documento = $this->normalizeDocumento($data['numero_documento'] ?? '');
        $nombreCompleto = trim(($data['apellidos'] ?? '') . ' ' . ($data['nombres'] ?? ''));
        
        Log::info('[CONVENIO GENERATION] Iniciando generación de convenio', [
            'documento' => $documento,
            'nombre_completo' => $nombreCompleto,
            'proceso' => $data['proceso'] ?? null,
            'ciudad' => $data['ciudad'] ?? null,
        ]);

        // Validar que existe la plantilla
        $templatePath = base_path(self::TEMPLATE_PATH);
        if (!file_exists($templatePath)) {
            Log::error('[CONVENIO GENERATION] Plantilla no encontrada', [
                'template_path' => $templatePath,
                'documento' => $documento,
            ]);
            throw new \Exception("Plantilla no encontrada en: {$templatePath}");
        }

        Log::debug('[CONVENIO GENERATION] Plantilla encontrada', [
            'template_path' => $templatePath,
            'documento' => $documento,
        ]);

        // Obtener datos faltantes del archivo de afiliados si es necesario
        if (!empty($documento)) {
            Log::debug('[CONVENIO GENERATION] Buscando datos faltantes en archivo de afiliados', [
                'documento' => $documento,
                'direccion_vacio' => empty(trim($data['direccion'] ?? '')),
                'telefono_vacio' => empty(trim($data['telefono'] ?? '')),
                'celular_vacio' => empty(trim($data['celular'] ?? '')),
            ]);

            $afiliadoData = $this->obtenerDatosAfiliado($documento);
            
            if ($afiliadoData) {
                Log::info('[CONVENIO GENERATION] Datos de afiliado encontrados', [
                    'documento' => $documento,
                    'tiene_direccion' => !empty($afiliadoData['direccion']),
                    'tiene_telefono' => !empty($afiliadoData['telefono']),
                    'tiene_celular' => !empty($afiliadoData['celular']),
                ]);
            } else {
                Log::warning('[CONVENIO GENERATION] Afiliado no encontrado en archivo de afiliados', [
                    'documento' => $documento,
                ]);
            }
            
            // Completar datos faltantes (solo si están vacíos en los datos originales)
            if (empty(trim($data['direccion'] ?? ''))) {
                $data['direccion'] = !empty($afiliadoData['direccion']) ? trim($afiliadoData['direccion']) : '';
                if (!empty($data['direccion'])) {
                    Log::debug('[CONVENIO GENERATION] Dirección completada desde afiliado', [
                        'documento' => $documento,
                    ]);
                }
            }
            if (empty(trim($data['telefono'] ?? ''))) {
                $data['telefono'] = !empty($afiliadoData['telefono']) ? trim($afiliadoData['telefono']) : '';
                if (!empty($data['telefono'])) {
                    Log::debug('[CONVENIO GENERATION] Teléfono completado desde afiliado', [
                        'documento' => $documento,
                    ]);
                }
            }
            if (empty(trim($data['celular'] ?? ''))) {
                $data['celular'] = !empty($afiliadoData['celular']) ? trim($afiliadoData['celular']) : '';
                if (!empty($data['celular'])) {
                    Log::debug('[CONVENIO GENERATION] Celular completado desde afiliado', [
                        'documento' => $documento,
                    ]);
                }
            }
        } else {
            Log::warning('[CONVENIO GENERATION] Documento vacío, no se buscará en archivo de afiliados', [
                'numero_documento_original' => $data['numero_documento'] ?? null,
            ]);
        }

        // Preparar datos para la plantilla
        $templateData = $this->prepararDatosPlantilla($data);

        Log::debug('[CONVENIO GENERATION] Datos preparados para plantilla', [
            'documento' => $documento,
            'variables' => array_keys($templateData),
            'fecha_inicio_raw' => $data['fecha_inicio'] ?? null,
            'fecha_finalizacion_raw' => $data['fecha_finalizacion'] ?? null,
            'tiene_fecha_inicio' => !empty($data['fecha_inicio'] ?? null),
            'tiene_fecha_finalizacion' => !empty($data['fecha_finalizacion'] ?? null),
            'duracion_generada' => !empty($templateData['DURACION'] ?? null),
            'duracion_preview' => !empty($templateData['DURACION'] ?? null) ? substr($templateData['DURACION'], 0, 100) . '...' : 'vacía',
        ]);

        // Crear procesador de plantilla
        $templateProcessor = new TemplateProcessor($templatePath);

        // Reemplazar placeholders
        foreach ($templateData as $key => $value) {
            $templateProcessor->setValue($key, $value ?? '');
        }

        Log::debug('[CONVENIO GENERATION] Placeholders reemplazados en plantilla', [
            'documento' => $documento,
            'total_variables' => count($templateData),
        ]);

        // Generar nombre de archivo
        $nombreArchivo = $this->generarNombreArchivo($data);
        $rutaSalida = base_path(self::OUTPUT_DIR . '/' . $nombreArchivo);

        Log::debug('[CONVENIO GENERATION] Nombre de archivo generado', [
            'documento' => $documento,
            'nombre_archivo' => $nombreArchivo,
            'ruta_salida' => $rutaSalida,
        ]);

        // Crear directorio si no existe
        $outputDir = base_path(self::OUTPUT_DIR);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
            Log::info('[CONVENIO GENERATION] Directorio de salida creado', [
                'output_dir' => $outputDir,
            ]);
        }

        // Guardar documento
        try {
            $templateProcessor->saveAs($rutaSalida);
            
            Log::info('[CONVENIO GENERATION] Convenio generado exitosamente', [
                'documento' => $documento,
                'nombre_completo' => $nombreCompleto,
                'nombre_archivo' => $nombreArchivo,
                'ruta' => $rutaSalida,
                'tamaño_bytes' => file_exists($rutaSalida) ? filesize($rutaSalida) : null,
            ]);
        } catch (\Exception $e) {
            Log::error('[CONVENIO GENERATION] Error al guardar convenio', [
                'documento' => $documento,
                'nombre_archivo' => $nombreArchivo,
                'ruta_salida' => $rutaSalida,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return [
            'ruta' => $rutaSalida,
            'nombre' => $nombreArchivo,
            'tipo' => 'docx',
        ];
    }

    /**
     * Prepara los datos para la plantilla Word
     *
     * @param array $data Datos del convenio
     * @return array Datos formateados para la plantilla
     */
    private function prepararDatosPlantilla(array $data): array
    {
        // Formatear número de documento con comas
        $numeroDocumento = $this->formatearDocumentoConComas($data['numero_documento'] ?? '');

        // Formatear fecha de inicio
        $fechaInicio = $this->formatearFechaEspanol($data['fecha_inicio'] ?? null);

        // Calcular duración
        $duracion = $this->calcularDuracion(
            $data['fecha_inicio'] ?? null,
            $data['fecha_finalizacion'] ?? null
        );

        return [
            'PROCESO' => strtoupper($data['proceso'] ?? ''),
            'CIUDAD' => $data['ciudad'] ?? '',
            'SEDE' => strtoupper($data['sede'] ?? ''),
            'FECHA' => $fechaInicio,
            'APELLIDOS' => strtoupper($data['apellidos'] ?? ''),
            'NOMBRES' => strtoupper($data['nombres'] ?? ''),
            'NUMERO_DOCUMENTO' => $numeroDocumento,
            'COMPENSACION_BASICA_REDACTADA' => $data['compensacion_basica_redactada'] ?? '',
            'DURACION' => $duracion,
            'DIRECCION' => !empty(trim($data['direccion'] ?? '')) ? $data['direccion'] : '__________________________',
            'TELEFONO' => !empty(trim($data['telefono'] ?? '')) ? $data['telefono'] : '__________________________',
            'CELULAR' => !empty(trim($data['celular'] ?? '')) ? $data['celular'] : '__________________________',
        ];
    }

    /**
     * Obtiene datos del afiliado por documento
     *
     * @param string $documento Número de documento normalizado
     * @return array|null Datos del afiliado o null si no se encuentra
     */
    private function obtenerDatosAfiliado(string $documento): ?array
    {
        Log::debug('[CONVENIO GENERATION] Obteniendo datos completos de afiliado', [
            'documento' => $documento,
        ]);

        try {
            $afiliadoData = $this->afiliadoService->getAfiliadoByDocumentoOnly($documento);
            
            if (!$afiliadoData) {
                Log::debug('[CONVENIO GENERATION] Afiliado no encontrado en servicio', [
                    'documento' => $documento,
                ]);
                return null;
            }

            // Obtener datos completos del afiliado para dirección, teléfono y celular
            $excelPath = 'data/PROSANET_INFORMACION_AFILIADOS.xlsx';
            $disks = ['prosalud-private', 'local'];

            Log::debug('[CONVENIO GENERATION] Buscando datos de contacto en archivo de afiliados', [
                'documento' => $documento,
                'excel_path' => $excelPath,
                'disks' => $disks,
            ]);

            foreach ($disks as $disk) {
                try {
                    Log::debug('[CONVENIO GENERATION] Intentando acceder a archivo de afiliados', [
                        'documento' => $documento,
                        'disk' => $disk,
                        'excel_path' => $excelPath,
                    ]);

                    if (!Storage::disk($disk)->exists($excelPath)) {
                        Log::debug('[CONVENIO GENERATION] Archivo no existe en disco', [
                            'documento' => $documento,
                            'disk' => $disk,
                            'excel_path' => $excelPath,
                        ]);
                        continue;
                    }

                    $stream = Storage::disk($disk)->readStream($excelPath);
                    if (false === $stream) {
                        Log::warning('[CONVENIO GENERATION] No se pudo leer stream del archivo', [
                            'documento' => $documento,
                            'disk' => $disk,
                        ]);
                        continue;
                    }

                    $tempPath = tempnam(sys_get_temp_dir(), 'prosanet_convenio_') . '.xlsx';
                    $destination = fopen($tempPath, 'w+b');
                    if (false === $destination) {
                        fclose($stream);
                        Log::warning('[CONVENIO GENERATION] No se pudo crear archivo temporal', [
                            'documento' => $documento,
                            'temp_path' => $tempPath,
                        ]);
                        continue;
                    }

                    stream_copy_to_stream($stream, $destination);
                    fclose($stream);
                    fclose($destination);

                    Log::debug('[CONVENIO GENERATION] Archivo temporal creado', [
                        'documento' => $documento,
                        'temp_path' => $tempPath,
                    ]);

                    try {
                        $reader = IOFactory::createReader('Xlsx');
                        if (method_exists($reader, 'setReadDataOnly')) {
                            $reader->setReadDataOnly(true);
                        }

                        if (method_exists($reader, 'setLoadSheetsOnly')) {
                            $reader->setLoadSheetsOnly(['INFORMACIÓN GENERAL']);
                        }

                        $spreadsheet = $reader->load($tempPath);
                        $informacionSheet = $spreadsheet->getSheetByName('INFORMACIÓN GENERAL');

                        if (!$informacionSheet) {
                            @unlink($tempPath);
                            Log::warning('[CONVENIO GENERATION] Hoja INFORMACIÓN GENERAL no encontrada', [
                                'documento' => $documento,
                            ]);
                            continue;
                        }

                        $highestRow = $informacionSheet->getHighestRow();
                        $normalizedDocumento = $this->normalizeDocumento($documento);

                        Log::debug('[CONVENIO GENERATION] Buscando documento en archivo de afiliados', [
                            'documento' => $documento,
                            'documento_normalizado' => $normalizedDocumento,
                            'total_filas' => $highestRow,
                        ]);

                        // Buscar por documento
                        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
                            $cell = $informacionSheet->getCell('B' . $rowIndex);
                            $rowDocumento = $this->normalizeDocumento($cell->getValue());

                            if ($rowDocumento === $normalizedDocumento) {
                                // Leer columnas: DIRECCION (13), TELEFONO (16), CELULAR (17)
                                $direccion = $this->getCellValue($informacionSheet->getCell('N' . $rowIndex));
                                $telefono = $this->getCellValue($informacionSheet->getCell('Q' . $rowIndex));
                                $celular = $this->getCellValue($informacionSheet->getCell('R' . $rowIndex));

                                Log::info('[CONVENIO GENERATION] Datos de contacto encontrados en archivo de afiliados', [
                                    'documento' => $documento,
                                    'fila' => $rowIndex,
                                    'tiene_direccion' => !empty(trim($direccion)),
                                    'tiene_telefono' => !empty(trim($telefono)),
                                    'tiene_celular' => !empty(trim($celular)),
                                ]);

                                $spreadsheet->disconnectWorksheets();
                                unset($spreadsheet);
                                @unlink($tempPath);

                                return [
                                    'direccion' => trim($direccion),
                                    'telefono' => trim($telefono),
                                    'celular' => trim($celular),
                                ];
                            }
                        }

                        Log::debug('[CONVENIO GENERATION] Documento no encontrado en archivo de afiliados', [
                            'documento' => $documento,
                            'documento_normalizado' => $normalizedDocumento,
                            'filas_revisadas' => $highestRow - 1,
                        ]);

                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);
                        @unlink($tempPath);
                    } catch (\Exception $e) {
                        @unlink($tempPath);
                        Log::error('[CONVENIO GENERATION] Error leyendo archivo de afiliados', [
                            'error' => $e->getMessage(),
                            'documento' => $documento,
                            'disk' => $disk,
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('[CONVENIO GENERATION] Error accediendo a archivo de afiliados', [
                        'error' => $e->getMessage(),
                        'disk' => $disk,
                        'documento' => $documento,
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::debug('[CONVENIO GENERATION] No se encontraron datos de contacto en ningún disco', [
                'documento' => $documento,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('[CONVENIO GENERATION] Error obteniendo datos de afiliado para convenio', [
                'error' => $e->getMessage(),
                'documento' => $documento,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Formatea un documento agregando comas cada 3 dígitos
     *
     * @param string $documento Número de documento
     * @return string Documento formateado
     */
    private function formatearDocumentoConComas(string $documento): string
    {
        // Remover caracteres no numéricos
        $documento = preg_replace('/[^0-9]/', '', $documento);
        
        if (empty($documento)) {
            return '';
        }

        // Agregar comas cada 3 dígitos desde la derecha
        return number_format((int) $documento, 0, '', ',');
    }

    /**
     * Formatea fecha en español
     *
     * @param mixed $fecha Fecha a formatear
     * @return string Fecha formateada
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
            Log::warning('Error formateando fecha para convenio', [
                'fecha' => $fecha,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Obtiene el nombre del mes en español
     *
     * @param int $mes Número del mes (1-12)
     * @return string Nombre del mes
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
     * Genera el texto de duración según si existe fecha de finalización o no
     *
     * @param mixed $fechaInicio Fecha de inicio
     * @param mixed $fechaFinalizacion Fecha de finalización (puede estar vacía)
     * @return string Texto de duración formateado
     */
    private function calcularDuracion($fechaInicio, $fechaFinalizacion): string
    {
        Log::debug('[CONVENIO GENERATION] Calculando duración', [
            'fecha_inicio' => $fechaInicio,
            'fecha_finalizacion' => $fechaFinalizacion,
            'tiene_fecha_fin' => !empty($fechaFinalizacion),
        ]);

        // Si hay fecha de finalización definida
        if (!empty($fechaFinalizacion)) {
            try {
                $fechaInicioFormateada = $this->formatearFechaEspanol($fechaInicio);
                $fechaFinFormateada = $this->formatearFechaEspanol($fechaFinalizacion);

                if (empty($fechaInicioFormateada) || empty($fechaFinFormateada)) {
                    // Si no se pueden formatear las fechas, usar mensaje por defecto
                    Log::warning('[CONVENIO GENERATION] No se pudieron formatear fechas, usando mensaje por defecto', [
                        'fecha_inicio' => $fechaInicio,
                        'fecha_finalizacion' => $fechaFinalizacion,
                        'fecha_inicio_formateada' => $fechaInicioFormateada,
                        'fecha_fin_formateada' => $fechaFinFormateada,
                    ]);
                    return $this->obtenerMensajeDuracionSinFechaFin();
                }

                Log::debug('[CONVENIO GENERATION] Duración generada con fechas definidas', [
                    'fecha_inicio_formateada' => $fechaInicioFormateada,
                    'fecha_fin_formateada' => $fechaFinFormateada,
                ]);

                return "Desde el {$fechaInicioFormateada} al {$fechaFinFormateada}; debido a que su vigencia depende directamente de la permanencia del vínculo estatutario que el AFILIADO PARTICIPE tenga con PROSALUD, imponiéndole el cumplimiento de obligaciones y el disfrute de derechos mientras permanezca afiliado. Este vínculo también estará supeditado a lo dispuesto en el reglamento del contrato sindical";
            } catch (\Exception $e) {
                Log::error('[CONVENIO GENERATION] Error formateando fechas para duración con fecha fin', [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_finalizacion' => $fechaFinalizacion,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return $this->obtenerMensajeDuracionSinFechaFin();
            }
        }

        // Si NO hay fecha de finalización (caso por defecto)
        Log::debug('[CONVENIO GENERATION] Usando mensaje de duración por defecto (sin fecha fin)', [
            'fecha_inicio' => $fechaInicio,
        ]);
        return $this->obtenerMensajeDuracionSinFechaFin();
    }

    /**
     * Obtiene el mensaje de duración cuando no hay fecha de finalización
     *
     * @return string Mensaje por defecto
     */
    private function obtenerMensajeDuracionSinFechaFin(): string
    {
        return "La duración será definida por el tiempo de duración del CONTRATO SINDICAL que suscriban la Entidad contratante y el Sindicato, además de la necesidad del servicio que tenga la Entidad contratante, dependiendo así mismo del cumplimiento de obligaciones por parte del AFILIADO PARTICIPE. Este vínculo también estará supeditado a lo dispuesto en el reglamento del contrato sindical";
    }

    /**
     * Genera el nombre del archivo para el convenio
     *
     * @param array $data Datos del convenio
     * @return string Nombre del archivo
     */
    private function generarNombreArchivo(array $data): string
    {
        $documento = preg_replace('/[^0-9]/', '', $data['numero_documento'] ?? '');
        $apellidos = preg_replace('/[^a-zA-Z0-9]/', '_', $data['apellidos'] ?? '');
        $nombres = preg_replace('/[^a-zA-Z0-9]/', '_', $data['nombres'] ?? '');

        $nombreBase = "Convenio_{$documento}_{$apellidos}_{$nombres}";
        
        // Si hay nombre de archivo especificado en los datos, usarlo
        if (!empty($data['nombre_archivo'])) {
            $nombreBase = $data['nombre_archivo'];
        }

        return $nombreBase . '.docx';
    }

    /**
     * Normaliza un documento removiendo puntos, espacios y caracteres especiales
     *
     * @param mixed $value Valor a normalizar
     * @return string Documento normalizado
     */
    private function normalizeDocumento($value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return preg_replace('/[.\s-]/', '', (string) $value);
    }

    /**
     * Obtiene el valor de una celda de Excel
     *
     * @param mixed $cell Celda de Excel
     * @return string Valor de la celda
     */
    private function getCellValue($cell): string
    {
        $value = $cell->getValue();
        
        if ($value === null) {
            return '';
        }

        // Si es una fórmula calculada, obtener el valor calculado
        if ($cell->getDataType() === 'f') {
            $value = $cell->getCalculatedValue();
        }

        return trim((string) $value);
    }

    /**
     * Convierte un convenio Word a PDF (comentado para implementación futura)
     * 
     * @param string $rutaWord Ruta del archivo Word
     * @return array Resultado con ruta del PDF
     */
    /*
    public function convertirConvenioAPDF(string $rutaWord): array
    {
        // TODO: Implementar conversión a PDF usando CloudConvert
        // Similar a como se hace en CertificadoConvenioService
        // 
        // Ejemplo:
        // $converterService = app(\App\Services\DocxToPdfCloudConvertService::class);
        // $resultadoPDF = $converterService->convert($rutaWord, true);
        // 
        // return $resultadoPDF;
        
        throw new \Exception('Conversión a PDF no implementada aún');
    }
    */
}

