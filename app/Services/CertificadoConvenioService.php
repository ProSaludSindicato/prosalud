<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\{Log, Storage};
use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpSpreadsheet\{IOFactory, Cell\Coordinate};
use App\Models\CertificadoConvenioRecord;

class CertificadoConvenioService
{
    private const TEMPLATE_PATH = 'resources/templates/certificado_convenio_template.docx';
    private const TEMP_DIR = 'temp';

    public function __construct(
        private readonly AfiliadoService $afiliadoService
    ) {
    }

    /**
     * Genera un certificado de convenio en formato PDF
     * Primero genera el Word desde la plantilla, luego lo convierte a PDF usando CloudConvert
     * Guarda el PDF en el bucket privado y crea un registro en la base de datos
     *
     * @param string $documento Número de documento del afiliado
     * @param string|null $dirigidoAEntidad Nombre de la entidad destinataria (opcional)
     * @return array
     */
    public function generarCertificadoPDF(string $documento, ?string $dirigidoAEntidad = null): array
    {
        $startTime = microtime(true);
        
        // Obtener información del afiliado primero para tener los datos necesarios
        $afiliadoData = $this->obtenerDatosAfiliado($documento);
        if (!$afiliadoData) {
            throw new \Exception("Afiliado con documento {$documento} no encontrado");
        }

        // Capturar la fecha una sola vez para usar consistentemente
        $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        $consecutivo = $this->generarConsecutivo($fechaCertificado);

        // Primero generar el Word desde la plantilla (pasar el consecutivo y destinatario para mantener consistencia)
        $resultadoWord = $this->generarCertificadoWord($documento, $consecutivo, $dirigidoAEntidad);

        try {
            // Convertir Word a PDF usando CloudConvert
            $converterService = app(\App\Services\DocxToPdfCloudConvertService::class);
            $resultadoPDF = $converterService->convert($resultadoWord['ruta'], true);

            // Limpiar archivo Word temporal
            if (file_exists($resultadoWord['ruta'])) {
                @unlink($resultadoWord['ruta']);
            }

            // Cambiar extensión del nombre de archivo
            $nombrePDF = str_replace('.docx', '.pdf', $resultadoWord['nombre']);

            // Obtener ruta absoluta del PDF
            $rutaPDF = storage_path('app/' . $resultadoPDF['path']);

            // Guardar PDF en bucket privado y crear registro en BD
            $bucketPath = $this->guardarCertificadoEnBucket($rutaPDF, $documento, $consecutivo, $fechaCertificado, $afiliadoData);

            // Crear registro en base de datos
            $record = $this->crearRegistroCertificado(
                $documento,
                $consecutivo,
                $bucketPath,
                $fechaCertificado,
                $afiliadoData
            );

            // Agregar métricas en el procesamiento
            Log::info('Recursos utilizados durante generación de certificado', [
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
                'execution_time' => round(microtime(true) - $startTime, 2) . ' segundos',
                'documento' => $documento,
                'consecutivo' => $consecutivo,
            ]);

            return [
                'ruta' => $rutaPDF,
                'nombre' => $nombrePDF,
                'tipo' => 'pdf',
                'consecutivo' => $consecutivo,
                'bucket_path' => $bucketPath,
                'record_id' => $record->id,
            ];
        } catch (\Exception $e) {
            // Si falla la conversión, limpiar el Word temporal y relanzar el error
            if (file_exists($resultadoWord['ruta'])) {
                @unlink($resultadoWord['ruta']);
            }

            Log::error('Error en generarCertificadoPDF al convertir a PDF con CloudConvert', [
                'documento' => $documento,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Genera un certificado de convenio en formato Word
     *
     * @param string $documento Número de documento del afiliado
     * @param string|null $consecutivo Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @param string|null $dirigidoAEntidad Nombre de la entidad destinataria (opcional)
     * @return array
     */
    public function generarCertificadoWord(string $documento, ?string $consecutivo = null, ?string $dirigidoAEntidad = null): array
    {
        // Capturar la fecha una sola vez para usar consistentemente en todo el certificado
        // Esto evita problemas de zona horaria y cambios de día entre llamadas
        $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));

        // Obtener información del afiliado
        $afiliadoData = $this->obtenerDatosAfiliado($documento);

        if (!$afiliadoData) {
            throw new \Exception("Afiliado con documento {$documento} no encontrado");
        }

        // Si no se proporcionó consecutivo, generarlo
        if ($consecutivo === null) {
            $consecutivo = $this->generarConsecutivo($fechaCertificado);
        }

        // Preparar datos (pasar la fecha capturada, el consecutivo y el destinatario)
        $datos = $this->prepararDatosCertificado($afiliadoData, $fechaCertificado, $consecutivo, $dirigidoAEntidad);

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

        // Generar nombre de archivo (usar la misma fecha)
        $nombreArchivo = $this->generarNombreArchivo($afiliadoData, $fechaCertificado);
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
     * Método público para uso desde otros servicios
     */
    public function obtenerDatosAfiliado(string $documento): ?array
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
                                $convenioActual = null;
                                if ($conveniosSheet) {
                                    // Obtener todos los convenios
                                    $todosLosConvenios = $this->getTodosLosConveniosByDocumento($conveniosSheet, $documento);
                                    // Obtener el convenio actual (más reciente/activo)
                                    $conveniosFull = $this->getConveniosByDocumentoOptimized($conveniosSheet, $documento);
                                    $convenioActual = !empty($conveniosFull) ? $conveniosFull[0] : null;
                                    $conveniosFull = $todosLosConvenios;
                                }

                                // Liberar memoria explícitamente
                                $spreadsheet->disconnectWorksheets();
                                unset($spreadsheet);
                                @unlink($tempPath);

                                return [
                                    'afiliado' => $afiliadoFull,
                                    'convenio' => $convenioActual,
                                    'todos_los_convenios' => $conveniosFull,
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
     * Obtiene TODOS los convenios por documento (sin filtrar)
     */
    private function getTodosLosConveniosByDocumento($sheet, string $documento): array
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
                    'fecha_ingreso' => $this->normalizeDate($sheet->getCell('H' . $rowIndex)->getValue() ?? ''),
                    'fecha_fin' => $this->normalizeDate($sheet->getCell('I' . $rowIndex)->getValue() ?? ''),
                ];
            }
        }

        // Ordenar por fecha_ingreso ascendente (más antiguo primero)
        usort($convenios, function ($a, $b) {
            return strcmp($a['fecha_ingreso'], $b['fecha_ingreso']);
        });

        return $convenios;
    }

    /**
     * Obtiene convenios por documento (versión optimizada)
     * Selecciona el convenio activo más reciente/actual, o el más reciente si no hay activos
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
                    'fecha_ingreso' => $this->normalizeDate($sheet->getCell('H' . $rowIndex)->getValue() ?? ''),
                    'fecha_fin' => $this->normalizeDate($sheet->getCell('I' . $rowIndex)->getValue() ?? ''),
                ];
            }
        }

        if (empty($convenios)) {
            return [];
        }

        // Filtrar convenios activos
        $conveniosActivos = array_filter($convenios, function ($conv) {
            return strcasecmp($conv['estado'], 'Activo') === 0;
        });

        $selectedConvenio = null;

        if (!empty($conveniosActivos)) {
            // Si hay convenios activos, seleccionar el más reciente/actual
            // Prioridad: fecha_fin vacía > fecha_fin más reciente > fecha_ingreso más reciente
            usort($conveniosActivos, function ($a, $b) {
                // Si uno tiene fecha_fin vacía y el otro no, el vacío tiene prioridad
                $aFechaFinVacia = empty($a['fecha_fin']);
                $bFechaFinVacia = empty($b['fecha_fin']);

                if ($aFechaFinVacia && !$bFechaFinVacia) {
                    return -1; // $a tiene prioridad
                }
                if (!$aFechaFinVacia && $bFechaFinVacia) {
                    return 1; // $b tiene prioridad
                }

                // Si ambos tienen fecha_fin o ambos están vacíos, comparar por fecha_fin
                if (!$aFechaFinVacia && !$bFechaFinVacia) {
                    $comparison = strcmp($b['fecha_fin'], $a['fecha_fin']);
                    if ($comparison !== 0) {
                        return $comparison; // Más reciente primero
                    }
                }

                // Si las fechas_fin son iguales o ambas vacías, usar fecha_ingreso como criterio secundario
                return strcmp($b['fecha_ingreso'], $a['fecha_ingreso']); // Más reciente primero
            });

            $selectedConvenio = reset($conveniosActivos);
        } else {
            // Si no hay activos, seleccionar el más reciente por fecha_fin
            usort($convenios, function ($a, $b) {
                // Fecha_fin vacía tiene menor prioridad cuando no hay activos
                $aFechaFinVacia = empty($a['fecha_fin']);
                $bFechaFinVacia = empty($b['fecha_fin']);

                if ($aFechaFinVacia && !$bFechaFinVacia) {
                    return 1; // $b tiene prioridad
                }
                if (!$aFechaFinVacia && $bFechaFinVacia) {
                    return -1; // $a tiene prioridad
                }

                // Comparar por fecha_fin (más reciente primero)
                $comparison = strcmp($b['fecha_fin'], $a['fecha_fin']);
                if ($comparison !== 0) {
                    return $comparison;
                }

                // Si las fechas_fin son iguales, usar fecha_ingreso
                return strcmp($b['fecha_ingreso'], $a['fecha_ingreso']);
            });

            $selectedConvenio = $convenios[0];
        }

        return $selectedConvenio ? [$selectedConvenio] : [];
    }

    /**
     * Prepara los datos del certificado
     *
     * @param array $afiliadoData
     * @param Carbon|null $fechaCertificado Instancia de Carbon con la fecha del certificado (opcional, usa now() si no se proporciona)
     * @param string|null $consecutivo Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @param string|null $dirigidoAEntidad Nombre de la entidad destinataria (opcional)
     * @return array
     */
    private function prepararDatosCertificado(array $afiliadoData, ?Carbon $fechaCertificado = null, ?string $consecutivo = null, ?string $dirigidoAEntidad = null): array
    {
        $afiliado = $afiliadoData['afiliado'];
        $convenio = $afiliadoData['convenio'] ?? null;
        $todosLosConvenios = $afiliadoData['todos_los_convenios'] ?? [];

        // Usar la fecha proporcionada o capturar una nueva si no se proporciona
        if ($fechaCertificado === null) {
            $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        }

        // Formatear fechas
        $fechaIngreso = $this->formatearFechaEspanol($afiliado['fecha_ingreso'] ?? null);
        $fechaRetiro = $this->formatearFechaEspanol($afiliado['fecha_liquidacion'] ?? null);
        $fechaCertificadoFormateada = $this->formatearFechaEspanol($fechaCertificado);

        // Nombre completo en mayúsculas
        $nombreCompleto = strtoupper(
            trim(($afiliado['nombres'] ?? '') . ' ' . ($afiliado['apellidos'] ?? ''))
        );

        // Documento formateado con puntos
        $documento = $this->formatearDocumento($afiliado['documento'] ?? '');

        // Hospital (transformar cliente)
        $clienteRaw = $convenio['cliente'] ?? '';
        $hospital = $this->transformarCliente($clienteRaw);

        // Proceso
        $proceso = $convenio['proceso'] ?? '';

        // Texto de fecha de retiro
        $textoFechaRetiro = $fechaRetiro ? ", hasta {$fechaRetiro}" : '';

        // Campos adicionales
        $correoPersonal = $afiliado['correo_personal'] ?? '';
        $sexo = $afiliado['sexo'] ?? '';
        $estado = $afiliado['estado'] ?? '';

        // Procesar condicionales basados en SEXO
        // Normalizar sexo para comparación (puede venir como "M", "MASCULINO", "F", "FEMENINO", etc.)
        $sexoNormalizado = strtoupper(trim($sexo));
        $esMasculino = ($sexoNormalizado === 'M' || $sexoNormalizado === 'MASCULINO' || $sexoNormalizado === 'MALE');

        // Placeholders procesados para condicionales de género
        $tituloPersona = $esMasculino ? 'el señor' : 'la señora';
        $identificadoIdentificada = $esMasculino ? 'identificado' : 'identificada';
        $afiliadoAfiliada = $esMasculino ? 'afiliado' : 'afiliada';
        $delInteresadoDeLaInteresada = $esMasculino ? 'del interesado' : 'de la interesada';

        // Procesar condicionales basados en ESTADO
        // Verificar si el afiliado está activo
        $estadoNormalizado = strtoupper(trim($estado));
        $estaActivo = ($estadoNormalizado === 'ACTIVO' || $estadoNormalizado === 'ACTIVE');
        $atiendeAtendio = $estaActivo ? 'atiende' : 'atendió';
        $seEncuentraEstuvo = $estaActivo ? 'se encuentra' : 'estuvo';
        $desarrollaActualmenteDesarrollo = $estaActivo ? 'desarrolla actualmente' : 'desarrolló';

        // Procesar DESTINATARIO
        $destinatario = trim($dirigidoAEntidad ?? '');
        $destinatarioCompleto = empty($destinatario)
            ? 'A quien corresponda.'
            : "Señores\n{$destinatario}";

        // Generar lista de convenios formateada
        $listaConvenios = $this->generarListaConvenios($todosLosConvenios, $convenio);

        // Determinar si hay un convenio o varios
        $cantidadConvenios = count($todosLosConvenios);
        $unConvenioVariosConvenios = $cantidadConvenios === 1 ? 'un Convenio' : 'varios Convenios';

        // Si no se proporcionó consecutivo, generarlo
        if ($consecutivo === null) {
            $consecutivo = $this->generarConsecutivo($fechaCertificado);
        }

        return [
            'NOMBRE_COMPLETO' => $nombreCompleto,
            'DOCUMENTO' => $documento,
            'FECHA_INGRESO' => $fechaIngreso,
            'FECHA_RETIRO' => $textoFechaRetiro,
            'HOSPITAL' => $hospital,
            'PROCESO' => strtoupper($proceso),
            'FECHA_CERTIFICADO' => $fechaCertificadoFormateada,
            'DIA_CERTIFICADO' => $fechaCertificado->day,
            'MES_CERTIFICADO' => $this->obtenerMesEspanol($fechaCertificado->month),
            'ANIO_CERTIFICADO' => $fechaCertificado->year,
            'CONSECUTIVO' => $consecutivo,
            // Campos adicionales
            'CORREO_PERSONAL' => $correoPersonal,
            'SEXO' => strtoupper($sexo),
            'ESTADO' => strtoupper($estado),
            // Placeholders procesados para condicionales
            'TITULO_PERSONA' => $tituloPersona, // "el señor" o "la señora"
            'IDENTIFICADO_IDENTIFICADA' => $identificadoIdentificada, // "identificado" o "identificada"
            'AFILIADO_AFILIADA' => $afiliadoAfiliada, // "afiliado" o "afiliada"
            'DEL_INTERESADO_DE_LA_INTERESADA' => $delInteresadoDeLaInteresada, // "del interesado" o "de la interesada"
            'ATIENDE_ATENDIO' => $atiendeAtendio, // "atiende" o "atendió" (según estado activo)
            'SE_ENCUENTRA_ESTUVO' => $seEncuentraEstuvo, // "Se encuentra" o "estuvo" (según estado activo)
            'DESARROLLA_ACTUALMENTE_DESARROLLO' => $desarrollaActualmenteDesarrollo, // "desarrolla actualmente" o "desarrolló" (según estado activo)
            'DESTINATARIO_COMPLETO' => $destinatarioCompleto, // "A quien corresponda." o "Señores [nombre]"
            'LISTA_CONVENIOS' => $listaConvenios, // Lista formateada de todos los convenios
            'UN_CONVENIO_VARIOS_CONVENIOS' => $unConvenioVariosConvenios, // "un Convenio" o "varios Convenios" (según cantidad)
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
     * Obtiene el nombre del mes abreviado en español (ej: "ene.", "feb.")
     */
    private function obtenerMesAbreviadoEspanol(int $mes): string
    {
        $meses = [
            1 => 'ene.', 2 => 'feb.', 3 => 'mar.', 4 => 'abr.',
            5 => 'may.', 6 => 'jun.', 7 => 'jul.', 8 => 'ago.',
            9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'dic.'
        ];

        return $meses[$mes] ?? '';
    }

    /**
     * Formatea fecha en formato corto (ej: "ene. 01/2019")
     */
    private function formatearFechaCorta($fecha): string
    {
        if (!$fecha) {
            return '';
        }

        try {
            $carbon = Carbon::parse($fecha);
            $mesAbrev = $this->obtenerMesAbreviadoEspanol($carbon->month);
            $dia = str_pad($carbon->day, 2, '0', STR_PAD_LEFT);

            return "{$mesAbrev} {$dia}/{$carbon->year}";
        } catch (\Exception $e) {
            Log::warning('Error formateando fecha corta', [
                'fecha' => $fecha,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Genera la lista formateada de convenios para el certificado
     */
    private function generarListaConvenios(array $todosLosConvenios, ?array $convenioActual): string
    {
        if (empty($todosLosConvenios)) {
            return '';
        }

        $lineas = [];
        $convenioActualCliente = $convenioActual['cliente'] ?? null;
        $convenioActualFechaFin = $convenioActual['fecha_fin'] ?? null;
        $convenioActualFechaIngreso = $convenioActual['fecha_ingreso'] ?? null;
        $convenioActualEstado = $convenioActual['estado'] ?? null;
        $esConvenioActualActivo = strcasecmp($convenioActualEstado ?? '', 'Activo') === 0;

        foreach ($todosLosConvenios as $convenio) {
            $clienteRaw = $convenio['cliente'] ?? '';
            $cliente = $this->transformarCliente($clienteRaw);
            $fechaIngreso = $convenio['fecha_ingreso'] ?? '';
            $fechaFin = $convenio['fecha_fin'] ?? '';
            $estado = $convenio['estado'] ?? '';

            // Determinar si es el convenio actual/vigente
            // Comparar por cliente original (sin transformar), fecha_ingreso, fecha_fin y estado
            $esActual = ($clienteRaw === $convenioActualCliente
                && $fechaIngreso === $convenioActualFechaIngreso
                && $fechaFin === $convenioActualFechaFin
                && strcasecmp($estado, $convenioActualEstado ?? '') === 0);

            $fechaIngresoFormateada = $this->formatearFechaCorta($fechaIngreso);

            // Formatear fecha de fin
            // Si es el convenio actual y está activo sin fecha_fin, mostrar "hasta la fecha, se encuentra vigente"
            if ($esActual && $esConvenioActualActivo && empty($fechaFin)) {
                $fechaFinFormateada = 'hasta la fecha, se encuentra vigente';
            } else {
                $fechaFinFormateada = $this->formatearFechaCorta($fechaFin);
                if (!empty($fechaFinFormateada)) {
                    $fechaFinFormateada = "hasta {$fechaFinFormateada}";
                }
            }

            $linea = "❖ {$cliente}";
            if (!empty($fechaIngresoFormateada)) {
                $linea .= " , desde {$fechaIngresoFormateada}";
            }
            if (!empty($fechaFinFormateada)) {
                $linea .= " {$fechaFinFormateada}";
            }

            $lineas[] = $linea;
        }

        return implode("\n", $lineas);
    }

    /**
     * Transforma el nombre del cliente del Excel al formato legible para el certificado
     */
    private function transformarCliente(?string $cliente): string
    {
        if (empty($cliente)) {
            return 'No asignado';
        }

        $clienteNormalizado = trim($cliente);

        // Mapeo de clientes del Excel a formato legible
        $mapeoClientes = [
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
            'COPACABANA' => 'E.S.E. Hospital Santa Margarita',
            'COPACABANA ' => 'E.S.E. Hospital Santa Margarita',
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

        // Buscar coincidencia exacta (case-insensitive)
        $clienteUpper = strtoupper($clienteNormalizado);
        foreach ($mapeoClientes as $key => $value) {
            if (strtoupper($key) === $clienteUpper) {
                return $value;
            }
        }

        // Si no hay coincidencia, retornar el valor original
        return $clienteNormalizado;
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
     *
     * @param array $afiliadoData
     * @param Carbon|null $fechaCertificado Instancia de Carbon con la fecha del certificado (opcional, usa now() si no se proporciona)
     * @return string
     */
    private function generarNombreArchivo(array $afiliadoData, ?Carbon $fechaCertificado = null): string
    {
        $documento = preg_replace('/[^0-9]/', '', $afiliadoData['afiliado']['documento'] ?? 'sin_doc');

        // Usar la misma fecha que se usa en el contenido del certificado
        if ($fechaCertificado === null) {
            $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        }

        $fecha = $fechaCertificado->format('Ymd');

        return "certificado_convenio_{$documento}_{$fecha}.docx";
    }

    /**
     * Genera consecutivo único
     * Formato: YYYYMMDD + número secuencial del día (4 dígitos, con padding ceros)
     *
     * @param Carbon|null $fechaCertificado Instancia de Carbon con la fecha del certificado (opcional, usa now() si no se proporciona)
     * @return string
     */
    private function generarConsecutivo(?Carbon $fechaCertificado = null): string
    {
        // Usar la misma fecha que se usa en el contenido del certificado
        if ($fechaCertificado === null) {
            $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        }

        $fechaFormato = $fechaCertificado->format('Ymd');

        // Buscar el último consecutivo del día
        $ultimoConsecutivo = CertificadoConvenioRecord::where('consecutivo', 'like', $fechaFormato . '%')
            ->orderBy('consecutivo', 'desc')
            ->value('consecutivo');

        $numeroSecuencial = 1;
        if ($ultimoConsecutivo) {
            // Extraer el número secuencial (últimos 4 dígitos)
            $numeroSecuencial = (int) substr($ultimoConsecutivo, -4) + 1;
        }

        // Limitar a 4 dígitos (máximo 9999 certificados por día)
        if ($numeroSecuencial > 9999) {
            throw new \Exception('Se ha alcanzado el límite máximo de certificados para el día');
        }

        // Formatear con padding de ceros a la izquierda
        $numeroFormateado = str_pad((string) $numeroSecuencial, 4, '0', STR_PAD_LEFT);

        return $fechaFormato . $numeroFormateado;
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

    /**
     * Guarda el certificado PDF en el bucket privado con estructura organizada
     * Estructura: certificados/convenio/YYYY/MM/documento_consecutivo.pdf
     *
     * @param string $rutaPDF Ruta local del archivo PDF
     * @param string $documento Número de documento del afiliado
     * @param string $consecutivo Número consecutivo del certificado
     * @param Carbon $fechaCertificado Fecha de generación
     * @param array $afiliadoData Datos del afiliado
     * @return string Ruta del archivo en el bucket
     */
    private function guardarCertificadoEnBucket(
        string $rutaPDF,
        string $documento,
        string $consecutivo,
        Carbon $fechaCertificado,
        array $afiliadoData
    ): string {
        $disk = 'prosalud-private';
        $documentoNormalizado = preg_replace('/[^0-9]/', '', $documento);
        $anio = $fechaCertificado->format('Y');
        $mes = $fechaCertificado->format('m');

        // Estructura: certificados/convenio/YYYY/MM/documento_consecutivo.pdf
        $directorio = "certificados/convenio/{$anio}/{$mes}";
        $nombreArchivo = "{$documentoNormalizado}_{$consecutivo}.pdf";
        $bucketPath = "{$directorio}/{$nombreArchivo}";

        try {
            // Intentar guardar en bucket privado
            $storage = Storage::disk($disk);

            // Leer el contenido del archivo
            $contenidoPDF = file_get_contents($rutaPDF);
            if ($contenidoPDF === false) {
                throw new \Exception("No se pudo leer el archivo PDF desde: {$rutaPDF}");
            }

            // Guardar en el bucket
            $guardado = $storage->put($bucketPath, $contenidoPDF);

            if (!$guardado) {
                throw new \Exception("No se pudo guardar el certificado en el bucket");
            }

            Log::info('Certificado guardado en bucket privado', [
                'documento' => $documento,
                'consecutivo' => $consecutivo,
                'bucket_path' => $bucketPath,
                'disk' => $disk,
            ]);

            return $bucketPath;
        } catch (\Exception $e) {
            Log::error('Error al guardar certificado en bucket privado', [
                'documento' => $documento,
                'consecutivo' => $consecutivo,
                'bucket_path' => $bucketPath,
                'error' => $e->getMessage(),
            ]);

            // Intentar con disco local como fallback
            try {
                // Leer el contenido del archivo nuevamente para el fallback
                $contenidoPDFFallback = file_get_contents($rutaPDF);
                if ($contenidoPDFFallback === false) {
                    throw new \Exception("No se pudo leer el archivo PDF desde: {$rutaPDF}");
                }

                $fallbackDisk = 'local';
                $storage = Storage::disk($fallbackDisk);
                $directorioLocal = "certificados/convenio/{$anio}/{$mes}";
                $guardado = $storage->put("{$directorioLocal}/{$nombreArchivo}", $contenidoPDFFallback);

                if ($guardado) {
                    Log::warning('Certificado guardado en disco local (fallback)', [
                        'documento' => $documento,
                        'consecutivo' => $consecutivo,
                        'path' => "{$directorioLocal}/{$nombreArchivo}",
                    ]);
                    return "{$directorioLocal}/{$nombreArchivo}";
                }
            } catch (\Exception $fallbackError) {
                Log::error('Error al guardar certificado en disco local (fallback)', [
                    'error' => $fallbackError->getMessage(),
                ]);
            }

            throw new \Exception("Error al guardar certificado en almacenamiento: " . $e->getMessage());
        }
    }

    /**
     * Crea un registro del certificado en la base de datos
     *
     * @param string $documento Número de documento del afiliado
     * @param string $consecutivo Número consecutivo del certificado
     * @param string $bucketPath Ruta del archivo en el bucket
     * @param Carbon $fechaCertificado Fecha de generación
     * @param array $afiliadoData Datos del afiliado
     * @return CertificadoConvenioRecord
     */
    private function crearRegistroCertificado(
        string $documento,
        string $consecutivo,
        string $bucketPath,
        Carbon $fechaCertificado,
        array $afiliadoData
    ): CertificadoConvenioRecord {
        $record = CertificadoConvenioRecord::create([
            'document_number' => $documento,
            'consecutivo' => $consecutivo,
            'storage_path' => $bucketPath,
            'generated_at' => $fechaCertificado,
        ]);

        Log::info('Registro de certificado creado en BD', [
            'document_number' => $documento,
            'consecutivo' => $consecutivo,
            'record_id' => $record->id,
        ]);

        return $record;
    }
}

