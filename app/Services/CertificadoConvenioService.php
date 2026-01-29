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
    private const TEMPLATE_PATH_BANCOLOMBIA = 'resources/templates/certificado_convenio_cuenta_bancolombia_template.docx';
    private const TEMPLATE_PATH_SUBSIDIO_VIVIENDA = 'resources/templates/certificado_convenio_subsidio_vivienda_template.docx';
    private const TEMPLATE_PATH_SUBSIDIO_DESEMPLEO = 'resources/templates/certificado_convenio_subsidio_desempleo_template.docx';
    private const TEMPLATE_PATH_ACTIVIDADES = 'resources/templates/certificado_convenio_actividades_template.docx';
    private const TEMPLATE_PATH_AFP = 'resources/templates/certificado_convenio_dirigido_afp_template.docx';
    private const TEMP_DIR = 'temp';

    public function __construct(
        private AfiliadoService $afiliadoService,
        private ExcelReaderService $excelReaderService
    ) {
    }

    /**
     * Genera un certificado de convenio en formato PDF
     * Primero genera el Word desde la plantilla, luego lo convierte a PDF usando CloudConvert
     * Guarda el PDF en el bucket privado y crea un registro en la base de datos
     *
     * @param string $documento Número de documento del afiliado
     * @param string|null $dirigidoAEntidad Nombre de la entidad destinataria (opcional)
     * @param array|null $compensaciones Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
     * @param bool $esParaBancolombia Indica si el certificado es para apertura de cuenta en Bancolombia (opcional)
     * @param bool $esParaSubsidioVivienda Indica si el certificado es para subsidio de vivienda (opcional)
     * @param bool $esParaSubsidioDesempleo Indica si el certificado es para subsidio de desempleo (opcional)
     * @param bool $esOtros Indica si el certificado es tipo "otros" (necesidad específica descrita por el usuario) (opcional)
     * @return array
     */
    public function generarCertificadoPDF(string $documento, ?string $dirigidoAEntidad = null, ?array $compensaciones = null, bool $esParaBancolombia = false, bool $esParaSubsidioVivienda = false, bool $esParaSubsidioDesempleo = false, bool $esOtros = false): array
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

        // Primero generar el Word desde la plantilla (pasar el consecutivo, destinatario y compensaciones para mantener consistencia)
        $resultadoWord = $this->generarCertificadoWord($documento, $consecutivo, $dirigidoAEntidad, $compensaciones, $esParaBancolombia, $esParaSubsidioVivienda, $esParaSubsidioDesempleo);

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

            // Determinar tipo de certificado
            $tipoCertificado = 'basico';
            if ($esParaBancolombia) {
                $tipoCertificado = 'bancolombia';
            } elseif ($esParaSubsidioVivienda) {
                $tipoCertificado = 'subsidio_vivienda';
            } elseif ($esParaSubsidioDesempleo) {
                $tipoCertificado = 'subsidio_desempleo';
            } elseif ($esOtros) {
                $tipoCertificado = 'otros';
            }

            // Crear registro en base de datos
            $record = $this->crearRegistroCertificado(
                $documento,
                $consecutivo,
                $bucketPath,
                $fechaCertificado,
                $afiliadoData,
                $tipoCertificado,
                $compensaciones !== null && !empty($compensaciones),
                $dirigidoAEntidad
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
     * @param array|null $compensaciones Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
     * @param bool $esParaBancolombia Indica si el certificado es para apertura de cuenta en Bancolombia (opcional)
     * @param bool $esParaSubsidioVivienda Indica si el certificado es para subsidio de vivienda (opcional)
     * @param bool $esParaSubsidioDesempleo Indica si el certificado es para subsidio de desempleo (opcional)
     * @return array
     */
    public function generarCertificadoWord(string $documento, ?string $consecutivo = null, ?string $dirigidoAEntidad = null, ?array $compensaciones = null, bool $esParaBancolombia = false, bool $esParaSubsidioVivienda = false, bool $esParaSubsidioDesempleo = false): array
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

        // Preparar datos según el tipo de certificado
        if ($esParaBancolombia) {
            $datos = $this->prepararDatosCertificadoBancolombia($afiliadoData, $fechaCertificado, $consecutivo);
        } elseif ($esParaSubsidioVivienda) {
            $datos = $this->prepararDatosCertificadoSubsidioVivienda($afiliadoData, $fechaCertificado, $consecutivo, $compensaciones);
        } elseif ($esParaSubsidioDesempleo) {
            $datos = $this->prepararDatosCertificadoSubsidioDesempleo($afiliadoData, $fechaCertificado, $consecutivo, $compensaciones);
        } else {
            $datos = $this->prepararDatosCertificado($afiliadoData, $fechaCertificado, $consecutivo, $dirigidoAEntidad, $compensaciones);
        }

        // Cargar plantilla según el tipo de certificado
        $templatePath = $this->obtenerRutaPlantilla($esParaBancolombia, $esParaSubsidioVivienda, $esParaSubsidioDesempleo, false);
        if (!file_exists($templatePath)) {
            $templateNombre = $esParaBancolombia
                ? 'certificado_convenio_cuenta_bancolombia_template.docx'
                : ($esParaSubsidioVivienda
                    ? 'certificado_convenio_subsidio_vivienda_template.docx'
                    : ($esParaSubsidioDesempleo
                        ? 'certificado_convenio_subsidio_desempleo_template.docx'
                        : 'certificado_convenio_template.docx'));
            throw new \Exception("Plantilla no encontrada en: {$templatePath}. Por favor, coloca la plantilla en resources/templates/{$templateNombre}");
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
     * Genera un certificado de convenio con actividades en formato Word
     *
     * @param string $documento Número de documento del afiliado
     * @param array $actividades Array de actividades a incluir en el certificado
     * @param string|null $consecutivo Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @param string|null $dirigidoAEntidad Nombre de la entidad destinataria (opcional)
     * @return array
     */
    public function generarCertificadoWordConActividades(string $documento, array $actividades, ?string $consecutivo = null, ?string $dirigidoAEntidad = null): array
    {
        // Capturar la fecha una sola vez para usar consistentemente en todo el certificado
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

        // Preparar datos para certificado con actividades
        $datos = $this->prepararDatosCertificadoActividades($afiliadoData, $fechaCertificado, $consecutivo, $dirigidoAEntidad, $actividades);

        // Cargar plantilla de actividades
        $templatePath = $this->obtenerRutaPlantilla(false, false, false, true);
        if (!file_exists($templatePath)) {
            throw new \Exception("Plantilla no encontrada en: {$templatePath}. Por favor, coloca la plantilla en resources/templates/certificado_convenio_actividades_template.docx");
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
     * Genera un certificado de convenio con actividades en formato PDF
     * Primero genera el Word desde la plantilla, luego lo convierte a PDF usando CloudConvert
     *
     * @param string $documento Número de documento del afiliado
     * @param array $actividades Array de actividades a incluir en el certificado
     * @param string|null $consecutivo Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @param string|null $dirigidoAEntidad Nombre de la entidad destinataria (opcional)
     * @return array
     */
    public function generarCertificadoPDFConActividades(string $documento, array $actividades, ?string $consecutivo = null, ?string $dirigidoAEntidad = null): array
    {
        $startTime = microtime(true);

        // Obtener información del afiliado primero para tener los datos necesarios
        $afiliadoData = $this->obtenerDatosAfiliado($documento);
        if (!$afiliadoData) {
            throw new \Exception("Afiliado con documento {$documento} no encontrado");
        }

        // Capturar la fecha una sola vez para usar consistentemente
        $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        if ($consecutivo === null) {
            $consecutivo = $this->generarConsecutivo($fechaCertificado);
        }

        // Primero generar el Word desde la plantilla
        $resultadoWord = $this->generarCertificadoWordConActividades($documento, $actividades, $consecutivo, $dirigidoAEntidad);

        try {
            // Convertir Word a PDF usando CloudConvert
            $converterService = app(\App\Services\DocxToPdfCloudConvertService::class);
            $resultadoPDF = $converterService->convert($resultadoWord['ruta'], true);

            // Limpiar archivo Word temporal
            if (file_exists($resultadoWord['ruta'])) {
                @unlink($resultadoWord['ruta']);
            }

            // Generar nombre del archivo PDF con el formato estándar
            // Formato: Certificado_Sindicato_ProSalud_{documento}_{consecutivo}.pdf
            $documentoNormalizado = preg_replace('/[^0-9]/', '', $documento);
            $nombrePDF = "Certificado_Sindicato_ProSalud_{$documentoNormalizado}_{$consecutivo}.pdf";

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
                $afiliadoData,
                'con_actividades', // tipo_certificado
                false, // tiene_compensaciones
                $dirigidoAEntidad
            );

            // Agregar métricas en el procesamiento
            Log::info('Recursos utilizados durante generación de certificado con actividades', [
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
                'execution_time' => round(microtime(true) - $startTime, 2) . ' segundos',
                'documento' => $documento,
                'consecutivo' => $consecutivo,
                'actividades_count' => count($actividades),
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

            Log::error('Error en generarCertificadoPDFConActividades al convertir a PDF con CloudConvert', [
                'documento' => $documento,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Genera un certificado de convenio dirigido a fondo de pensiones (AFP) en formato Word
     *
     * @param string $documento Número de documento del afiliado
     * @param string|null $afp Nombre del fondo de pensiones (AFP)
     * @param string|null $consecutivo Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @return array
     */
    public function generarCertificadoWordDirigidoAFP(string $documento, ?string $afp = null, ?string $consecutivo = null): array
    {
        // Capturar la fecha una sola vez para usar consistentemente en todo el certificado
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

        // Preparar datos para certificado dirigido a AFP
        $datos = $this->prepararDatosCertificadoAFP($afiliadoData, $fechaCertificado, $consecutivo, $afp);

        // Cargar plantilla de AFP
        $templatePath = $this->obtenerRutaPlantilla(false, false, false, false, true);
        if (!file_exists($templatePath)) {
            throw new \Exception("Plantilla no encontrada en: {$templatePath}. Por favor, coloca la plantilla en resources/templates/certificado_convenio_dirigido_afp_template.docx");
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
     * Genera un certificado de convenio dirigido a fondo de pensiones (AFP) en formato PDF
     * Primero genera el Word desde la plantilla, luego lo convierte a PDF usando CloudConvert
     *
     * @param string $documento Número de documento del afiliado
     * @param string|null $afp Nombre del fondo de pensiones (AFP)
     * @param string|null $consecutivo Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @return array
     */
    public function generarCertificadoPDFDirigidoAFP(string $documento, ?string $afp = null, ?string $consecutivo = null): array
    {
        $startTime = microtime(true);

        // Obtener información del afiliado primero para tener los datos necesarios
        $afiliadoData = $this->obtenerDatosAfiliado($documento);
        if (!$afiliadoData) {
            throw new \Exception("Afiliado con documento {$documento} no encontrado");
        }

        // Capturar la fecha una sola vez para usar consistentemente
        $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        if ($consecutivo === null) {
            $consecutivo = $this->generarConsecutivo($fechaCertificado);
        }

        // Primero generar el Word desde la plantilla
        $resultadoWord = $this->generarCertificadoWordDirigidoAFP($documento, $afp, $consecutivo);

        try {
            // Convertir Word a PDF usando CloudConvert
            $converterService = app(\App\Services\DocxToPdfCloudConvertService::class);
            $resultadoPDF = $converterService->convert($resultadoWord['ruta'], true);

            // Limpiar archivo Word temporal
            if (file_exists($resultadoWord['ruta'])) {
                @unlink($resultadoWord['ruta']);
            }

            // Generar nombre del archivo PDF con el formato estándar
            // Formato: Certificado_Sindicato_ProSalud_{documento}_{consecutivo}.pdf
            $documentoNormalizado = preg_replace('/[^0-9]/', '', $documento);
            $nombrePDF = "Certificado_Sindicato_ProSalud_{$documentoNormalizado}_{$consecutivo}.pdf";

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
                $afiliadoData,
                'dirigido_afp', // tipo_certificado
                false, // tiene_compensaciones
                null // dirigido_a_entidad
            );

            // Agregar métricas en el procesamiento
            Log::info('Recursos utilizados durante generación de certificado dirigido a AFP', [
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
                'execution_time' => round(microtime(true) - $startTime, 2) . ' segundos',
                'documento' => $documento,
                'consecutivo' => $consecutivo,
                'afp' => $afp,
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

            Log::error('Error en generarCertificadoPDFDirigidoAFP al convertir a PDF con CloudConvert', [
                'documento' => $documento,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
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
                                // Solo leer las columnas que necesitamos: 0,1,2,3,4,8,10,18,20,32 (AFP)
                                $neededColumns = [0, 1, 2, 3, 4, 8, 10, 18, 20, 32];
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
     * 8: sexo, 10: fecha_ingreso, 18: correo_personal, 20: fecha_liquidacion,
     * 32: afp (A.F.P)
     */
    private function extractAfiliadoInfo(array $row): array
    {
        // Normalizar el valor de AFP
        $afpRaw = $this->normalizeValue($row[32] ?? '');
        $afp = trim($afpRaw);
        
        // Si está vacío o es "NINGUNA", dejarlo como string vacío
        if (empty($afp) || strtoupper($afp) === 'NINGUNA') {
            $afp = '';
        }

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
            'afp' => $afp,
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

        // Ordenar los convenios de manera cronológica:
        // 1. Primero por fecha_ingreso ascendente (más antiguo primero) - orden cronológico
        // 2. Si tienen la misma fecha_ingreso, los finalizados (con fecha_fin) aparecen antes que los vigentes (sin fecha_fin)
        // 3. Si ambos tienen fecha_fin y la misma fecha_ingreso, ordenar por fecha_fin descendente (más reciente primero)
        // Esto evita confusión cuando hay convenios con la misma fecha de inicio pero diferentes estados
        usort($convenios, function ($a, $b) {
            // Primero comparar por fecha_ingreso (ascendente - más antiguo primero)
            $comparisonFechaIngreso = strcmp($a['fecha_ingreso'], $b['fecha_ingreso']);
            if ($comparisonFechaIngreso !== 0) {
                return $comparisonFechaIngreso;
            }

            // Si tienen la misma fecha_ingreso, priorizar los finalizados sobre los vigentes
            $aFechaFinVacia = empty($a['fecha_fin']);
            $bFechaFinVacia = empty($b['fecha_fin']);

            // Si uno tiene fecha_fin y el otro no, el finalizado va primero
            if (!$aFechaFinVacia && $bFechaFinVacia) {
                return -1; // $a (finalizado) va primero
            }
            if ($aFechaFinVacia && !$bFechaFinVacia) {
                return 1; // $b (finalizado) va primero
            }

            // Si ambos tienen fecha_fin, ordenar por fecha_fin descendente (más reciente primero)
            if (!$aFechaFinVacia && !$bFechaFinVacia) {
                return strcmp($b['fecha_fin'], $a['fecha_fin']);
            }

            // Si ambos están vigentes (sin fecha_fin), mantener el orden por fecha_ingreso
            return 0;
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
     * @param array|null $compensaciones Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
     * @return array
     */
    private function prepararDatosCertificado(array $afiliadoData, ?Carbon $fechaCertificado = null, ?string $consecutivo = null, ?string $dirigidoAEntidad = null, ?array $compensaciones = null): array
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

        // Generar mensaje de compensaciones si están disponibles (en dos partes separadas)
        $mensajeCompensacionesParte1 = '';
        $mensajeCompensacionesParte2 = '';
        if ($compensaciones && isset($compensaciones['t_basicos']) && isset($compensaciones['t_auxilios']) && isset($compensaciones['t_ingresos'])) {
            Log::info('Compensaciones recibidas en prepararDatosCertificado', [
                'compensaciones' => $compensaciones,
                't_basicos' => $compensaciones['t_basicos'],
                't_auxilios' => $compensaciones['t_auxilios'],
                't_ingresos' => $compensaciones['t_ingresos'],
            ]);

            $mensajeCompensaciones = $this->generarMensajeCompensaciones(
                (int) $compensaciones['t_basicos'],
                (int) $compensaciones['t_auxilios'],
                (int) $compensaciones['t_ingresos']
            );

            $mensajeCompensacionesParte1 = $mensajeCompensaciones['parte1'] ?? '';
            $mensajeCompensacionesParte2 = $mensajeCompensaciones['parte2'] ?? '';
        } else {
            Log::warning('Compensaciones no disponibles o incompletas en prepararDatosCertificado', [
                'compensaciones' => $compensaciones,
                'tiene_compensaciones' => !empty($compensaciones),
            ]);
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
            'MENSAJE_COMPENSACIONES_PARTE1' => $mensajeCompensacionesParte1, // Primera parte: "Con una compensación Básica mensual variable de $X, auxilios por $Y, para un"
            'MENSAJE_COMPENSACIONES_PARTE2' => $mensajeCompensacionesParte2, // Segunda parte: "Total de $X. En Letras: [número en letras] pesos."
        ];
    }

    /**
     * Prepara los datos del certificado específico para Bancolombia
     * Esta plantilla usa placeholders más simples: FECHA_CERTIFICADO, NOMBRE_COMPLETO, DOCUMENTO, CONSECUTIVO
     *
     * @param array $afiliadoData
     * @param Carbon|null $fechaCertificado Instancia de Carbon con la fecha del certificado
     * @param string|null $consecutivo Número consecutivo
     * @return array
     */
    private function prepararDatosCertificadoBancolombia(array $afiliadoData, ?Carbon $fechaCertificado = null, ?string $consecutivo = null): array
    {
        $afiliado = $afiliadoData['afiliado'];

        // Usar la fecha proporcionada o capturar una nueva si no se proporciona
        if ($fechaCertificado === null) {
            $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        }

        // Formatear fecha del certificado
        $fechaCertificadoFormateada = $this->formatearFechaEspanol($fechaCertificado);

        // Nombre completo en mayúsculas
        $nombreCompleto = strtoupper(
            trim(($afiliado['nombres'] ?? '') . ' ' . ($afiliado['apellidos'] ?? ''))
        );

        // Documento formateado con puntos
        $documento = $this->formatearDocumento($afiliado['documento'] ?? '');

        // Si no se proporcionó consecutivo, generarlo
        if ($consecutivo === null) {
            $consecutivo = $this->generarConsecutivo($fechaCertificado);
        }

        Log::info('Preparando datos para certificado de Bancolombia', [
            'nombre_completo' => $nombreCompleto,
            'documento' => $documento,
            'fecha_certificado' => $fechaCertificadoFormateada,
            'consecutivo' => $consecutivo,
        ]);

        return [
            'FECHA_CERTIFICADO' => $fechaCertificadoFormateada,
            'NOMBRE_COMPLETO' => $nombreCompleto,
            'DOCUMENTO' => $documento,
            'CONSECUTIVO' => $consecutivo,
        ];
    }

    /**
     * Prepara los datos del certificado específico para Subsidio de Vivienda
     * Similar al certificado de convenio simple pero con formato especial para compensaciones
     *
     * @param array $afiliadoData
     * @param Carbon|null $fechaCertificado Instancia de Carbon con la fecha del certificado
     * @param string|null $consecutivo Número consecutivo
     * @param array|null $compensaciones Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
     * @return array
     */
    private function prepararDatosCertificadoSubsidioVivienda(array $afiliadoData, ?Carbon $fechaCertificado = null, ?string $consecutivo = null, ?array $compensaciones = null): array
    {
        $afiliado = $afiliadoData['afiliado'];
        $convenio = $afiliadoData['convenio'] ?? null;
        $todosLosConvenios = $afiliadoData['todos_los_convenios'] ?? [];

        // Usar la fecha proporcionada o capturar una nueva si no se proporciona
        if ($fechaCertificado === null) {
            $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        }

        // Formatear fechas
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

        // Campos adicionales
        $sexo = $afiliado['sexo'] ?? '';
        $estado = $afiliado['estado'] ?? '';

        // Procesar condicionales basados en ESTADO
        $estadoNormalizado = strtoupper(trim($estado));
        $estaActivo = ($estadoNormalizado === 'ACTIVO' || $estadoNormalizado === 'ACTIVE');
        $atiendeAtendio = $estaActivo ? 'atiende' : 'atendió';
        $seEncuentraEstuvo = $estaActivo ? 'se encuentra' : 'estuvo';
        $desarrollaActualmenteDesarrollo = $estaActivo ? 'desarrolla actualmente' : 'desarrolló';

        // Generar lista de convenios formateada
        $listaConvenios = $this->generarListaConvenios($todosLosConvenios, $convenio);

        // Determinar si hay un convenio o varios
        $cantidadConvenios = count($todosLosConvenios);
        $unConvenioVariosConvenios = $cantidadConvenios === 1 ? 'un Convenio' : 'varios Convenios';

        // Si no se proporcionó consecutivo, generarlo
        if ($consecutivo === null) {
            $consecutivo = $this->generarConsecutivo($fechaCertificado);
        }

        // Generar mensaje de compensaciones si están disponibles (en formato especial para subsidio de vivienda)
        $mensajeCompensacionesParte1 = '';
        $mensajeCompensacionesParte2 = '';
        if ($compensaciones && isset($compensaciones['t_basicos']) && isset($compensaciones['t_auxilios']) && isset($compensaciones['t_ingresos'])) {
            Log::info('Compensaciones recibidas en prepararDatosCertificadoSubsidioVivienda', [
                'compensaciones' => $compensaciones,
                't_basicos' => $compensaciones['t_basicos'],
                't_auxilios' => $compensaciones['t_auxilios'],
                't_ingresos' => $compensaciones['t_ingresos'],
            ]);

            $mensajeCompensaciones = $this->generarMensajeCompensacionesSubsidioVivienda(
                (int) $compensaciones['t_basicos'],
                (int) $compensaciones['t_auxilios'],
                (int) $compensaciones['t_ingresos']
            );

            $mensajeCompensacionesParte1 = $mensajeCompensaciones['parte1'] ?? '';
            $mensajeCompensacionesParte2 = $mensajeCompensaciones['parte2'] ?? '';
        } else {
            Log::warning('Compensaciones no disponibles o incompletas en prepararDatosCertificadoSubsidioVivienda', [
                'compensaciones' => $compensaciones,
                'tiene_compensaciones' => !empty($compensaciones),
            ]);
        }

        Log::info('Preparando datos para certificado de Subsidio de Vivienda', [
            'nombre_completo' => $nombreCompleto,
            'documento' => $documento,
            'fecha_certificado' => $fechaCertificadoFormateada,
            'consecutivo' => $consecutivo,
        ]);

        return [
            'NOMBRE_COMPLETO' => $nombreCompleto,
            'DOCUMENTO' => $documento,
            'HOSPITAL' => $hospital,
            'PROCESO' => strtoupper($proceso),
            'FECHA_CERTIFICADO' => $fechaCertificadoFormateada,
            'DIA_CERTIFICADO' => $fechaCertificado->day,
            'MES_CERTIFICADO' => $this->obtenerMesEspanol($fechaCertificado->month),
            'ANIO_CERTIFICADO' => $fechaCertificado->year,
            'CONSECUTIVO' => $consecutivo,
            'SE_ENCUENTRA_ESTUVO' => $seEncuentraEstuvo,
            'UN_CONVENIO_VARIOS_CONVENIOS' => $unConvenioVariosConvenios,
            'DESARROLLA_ACTUALMENTE_DESARROLLO' => $desarrollaActualmenteDesarrollo,
            'ATIENDE_ATENDIO' => $atiendeAtendio,
            'LISTA_CONVENIOS' => $listaConvenios,
            'MENSAJE_COMPENSACIONES_PARTE1' => $mensajeCompensacionesParte1,
            'MENSAJE_COMPENSACIONES_PARTE2' => $mensajeCompensacionesParte2,
        ];
    }

    /**
     * Prepara los datos del certificado específico para Subsidio de Desempleo
     * Similar al certificado de subsidio de vivienda pero con FECHA_HASTA y MOTIVO_RETIRO
     *
     * @param array $afiliadoData
     * @param Carbon|null $fechaCertificado Instancia de Carbon con la fecha del certificado
     * @param string|null $consecutivo Número consecutivo
     * @param array|null $compensaciones Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
     * @return array
     */
    private function prepararDatosCertificadoSubsidioDesempleo(array $afiliadoData, ?Carbon $fechaCertificado = null, ?string $consecutivo = null, ?array $compensaciones = null): array
    {
        $afiliado = $afiliadoData['afiliado'];
        $convenio = $afiliadoData['convenio'] ?? null;
        $todosLosConvenios = $afiliadoData['todos_los_convenios'] ?? [];

        // Usar la fecha proporcionada o capturar una nueva si no se proporciona
        if ($fechaCertificado === null) {
            $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        }

        // Formatear fechas
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

        // FECHA_HASTA: fecha de fin del convenio más actual/reciente (el que se usa para conocer el proceso)
        $fechaHasta = '';
        if ($convenio && !empty($convenio['fecha_fin'])) {
            $fechaHasta = $this->formatearFechaEspanol($convenio['fecha_fin']);
        }

        // Campos adicionales
        $sexo = $afiliado['sexo'] ?? '';
        $estado = $afiliado['estado'] ?? '';

        // Procesar condicionales basados en ESTADO
        $estadoNormalizado = strtoupper(trim($estado));
        $estaActivo = ($estadoNormalizado === 'ACTIVO' || $estadoNormalizado === 'ACTIVE');
        $atiendeAtendio = $estaActivo ? 'atiende' : 'atendió';
        $seEncuentraEstuvo = $estaActivo ? 'se encuentra' : 'estuvo';
        $desarrollaActualmenteDesarrollo = $estaActivo ? 'desarrolla actualmente' : 'desarrolló';

        // Generar lista de convenios formateada
        $listaConvenios = $this->generarListaConvenios($todosLosConvenios, $convenio);

        // Determinar si hay un convenio o varios
        $cantidadConvenios = count($todosLosConvenios);
        $unConvenioVariosConvenios = $cantidadConvenios === 1 ? 'un Convenio' : 'varios Convenios';

        // Si no se proporcionó consecutivo, generarlo
        if ($consecutivo === null) {
            $consecutivo = $this->generarConsecutivo($fechaCertificado);
        }

        // Generar mensaje de compensaciones si están disponibles (en formato especial para subsidio de desempleo, igual que subsidio de vivienda)
        $mensajeCompensacionesParte1 = '';
        $mensajeCompensacionesParte2 = '';
        if ($compensaciones && isset($compensaciones['t_basicos']) && isset($compensaciones['t_auxilios']) && isset($compensaciones['t_ingresos'])) {
            Log::info('Compensaciones recibidas en prepararDatosCertificadoSubsidioDesempleo', [
                'compensaciones' => $compensaciones,
                't_basicos' => $compensaciones['t_basicos'],
                't_auxilios' => $compensaciones['t_auxilios'],
                't_ingresos' => $compensaciones['t_ingresos'],
            ]);

            $mensajeCompensaciones = $this->generarMensajeCompensacionesSubsidioVivienda(
                (int) $compensaciones['t_basicos'],
                (int) $compensaciones['t_auxilios'],
                (int) $compensaciones['t_ingresos']
            );

            $mensajeCompensacionesParte1 = $mensajeCompensaciones['parte1'] ?? '';
            $mensajeCompensacionesParte2 = $mensajeCompensaciones['parte2'] ?? '';
        } else {
            Log::warning('Compensaciones no disponibles o incompletas en prepararDatosCertificadoSubsidioDesempleo', [
                'compensaciones' => $compensaciones,
                'tiene_compensaciones' => !empty($compensaciones),
            ]);
        }

        Log::info('Preparando datos para certificado de Subsidio de Desempleo', [
            'nombre_completo' => $nombreCompleto,
            'documento' => $documento,
            'fecha_certificado' => $fechaCertificadoFormateada,
            'fecha_hasta' => $fechaHasta,
            'consecutivo' => $consecutivo,
        ]);

        return [
            'NOMBRE_COMPLETO' => $nombreCompleto,
            'DOCUMENTO' => $documento,
            'HOSPITAL' => $hospital,
            'PROCESO' => strtoupper($proceso),
            'FECHA_CERTIFICADO' => $fechaCertificadoFormateada,
            'FECHA_HASTA' => $fechaHasta,
            'DIA_CERTIFICADO' => $fechaCertificado->day,
            'MES_CERTIFICADO' => $this->obtenerMesEspanol($fechaCertificado->month),
            'ANIO_CERTIFICADO' => $fechaCertificado->year,
            'CONSECUTIVO' => $consecutivo,
            'SE_ENCUENTRA_ESTUVO' => $seEncuentraEstuvo,
            'UN_CONVENIO_VARIOS_CONVENIOS' => $unConvenioVariosConvenios,
            'DESARROLLA_ACTUALMENTE_DESARROLLO' => $desarrollaActualmenteDesarrollo,
            'ATIENDE_ATENDIO' => $atiendeAtendio,
            'LISTA_CONVENIOS' => $listaConvenios,
            'MENSAJE_COMPENSACIONES_PARTE1' => $mensajeCompensacionesParte1,
            'MENSAJE_COMPENSACIONES_PARTE2' => $mensajeCompensacionesParte2,
        ];
    }

    /**
     * Prepara los datos del certificado con actividades
     * Similar al certificado de convenio simple pero incluye lista de actividades
     *
     * @param array $afiliadoData
     * @param Carbon|null $fechaCertificado Instancia de Carbon con la fecha del certificado
     * @param string|null $consecutivo Número consecutivo
     * @param string|null $dirigidoAEntidad Nombre de la entidad destinataria (opcional)
     * @param array $actividades Array de actividades a incluir en el certificado
     * @return array
     */
    private function prepararDatosCertificadoActividades(array $afiliadoData, ?Carbon $fechaCertificado = null, ?string $consecutivo = null, ?string $dirigidoAEntidad = null, array $actividades = []): array
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
        $sexoNormalizado = strtoupper(trim($sexo));
        $esMasculino = ($sexoNormalizado === 'M' || $sexoNormalizado === 'MASCULINO' || $sexoNormalizado === 'MALE');
        $tituloPersona = $esMasculino ? 'el señor' : 'la señora';
        $identificadoIdentificada = $esMasculino ? 'identificado' : 'identificada';
        $afiliadoAfiliada = $esMasculino ? 'afiliado' : 'afiliada';
        $delInteresadoDeLaInteresada = $esMasculino ? 'del interesado' : 'de la interesada';

        // Procesar condicionales basados en ESTADO
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

        // Generar lista de actividades formateada
        $listaActividades = $this->formatearListaActividades($actividades);

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
            'CORREO_PERSONAL' => $correoPersonal,
            'SEXO' => strtoupper($sexo),
            'ESTADO' => strtoupper($estado),
            'TITULO_PERSONA' => $tituloPersona,
            'IDENTIFICADO_IDENTIFICADA' => $identificadoIdentificada,
            'AFILIADO_AFILIADA' => $afiliadoAfiliada,
            'DEL_INTERESADO_DE_LA_INTERESADA' => $delInteresadoDeLaInteresada,
            'ATIENDE_ATENDIO' => $atiendeAtendio,
            'SE_ENCUENTRA_ESTUVO' => $seEncuentraEstuvo,
            'DESARROLLA_ACTUALMENTE_DESARROLLO' => $desarrollaActualmenteDesarrollo,
            'DESTINATARIO_COMPLETO' => $destinatarioCompleto,
            'LISTA_CONVENIOS' => $listaConvenios,
            'UN_CONVENIO_VARIOS_CONVENIOS' => $unConvenioVariosConvenios,
            'LISTA_ACTIVIDADES' => $listaActividades,
        ];
    }

    /**
     * Formatea la lista de actividades para el placeholder LISTA_ACTIVIDADES
     * Genera una lista con viñetas de actividades con saltos de línea entre cada item
     *
     * @param array $actividades Array de strings con las actividades
     * @return string Lista formateada con viñetas y saltos de línea
     */
    private function formatearListaActividades(array $actividades): string
    {
        if (empty($actividades)) {
            return '';
        }

        // Filtrar actividades vacías y limpiar espacios
        $actividadesLimpias = array_filter(
            array_map('trim', $actividades),
            fn($actividad) => !empty($actividad)
        );

        if (empty($actividadesLimpias)) {
            return '';
        }

        // Generar lista con viñetas y saltos de línea entre cada item
        $lista = [];
        foreach ($actividadesLimpias as $actividad) {
            $lista[] = "• {$actividad}";
        }

        // Unir con doble salto de línea para que haya espacio entre cada actividad
        return implode("\n\n", $lista);
    }

    /**
     * Prepara los datos del certificado dirigido a fondo de pensiones (AFP)
     * Similar al certificado de convenio simple pero incluye placeholder AFP
     *
     * @param array $afiliadoData
     * @param Carbon|null $fechaCertificado Instancia de Carbon con la fecha del certificado
     * @param string|null $consecutivo Número consecutivo
     * @param string|null $afp Nombre del fondo de pensiones (AFP)
     * @return array
     */
    private function prepararDatosCertificadoAFP(array $afiliadoData, ?Carbon $fechaCertificado = null, ?string $consecutivo = null, ?string $afp = null): array
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
        $sexoNormalizado = strtoupper(trim($sexo));
        $esMasculino = ($sexoNormalizado === 'M' || $sexoNormalizado === 'MASCULINO' || $sexoNormalizado === 'MALE');
        $tituloPersona = $esMasculino ? 'el señor' : 'la señora';
        $identificadoIdentificada = $esMasculino ? 'identificado' : 'identificada';
        $afiliadoAfiliada = $esMasculino ? 'afiliado' : 'afiliada';
        $delInteresadoDeLaInteresada = $esMasculino ? 'del interesado' : 'de la interesada';

        // Procesar condicionales basados en ESTADO
        $estadoNormalizado = strtoupper(trim($estado));
        $estaActivo = ($estadoNormalizado === 'ACTIVO' || $estadoNormalizado === 'ACTIVE');
        $atiendeAtendio = $estaActivo ? 'atiende' : 'atendió';
        $seEncuentraEstuvo = $estaActivo ? 'se encuentra' : 'estuvo';
        $desarrollaActualmenteDesarrollo = $estaActivo ? 'desarrolla actualmente' : 'desarrolló';

        // Procesar DESTINATARIO - Si no se proporciona AFP, usar valor genérico
        // La plantilla Word ya tiene un valor genérico definido, por lo que no se personaliza
        $destinatarioCompleto = 'A quien corresponda.';
        if (!empty($afp)) {
            $destinatarioCompleto = "Señores\n{$afp}";
        }

        // Generar lista de convenios formateada
        $listaConvenios = $this->generarListaConvenios($todosLosConvenios, $convenio);

        // Determinar si hay un convenio o varios
        $cantidadConvenios = count($todosLosConvenios);
        $unConvenioVariosConvenios = $cantidadConvenios === 1 ? 'un Convenio' : 'varios Convenios';

        // Si no se proporcionó consecutivo, generarlo
        if ($consecutivo === null) {
            $consecutivo = $this->generarConsecutivo($fechaCertificado);
        }

        // AFP - usar el valor proporcionado o vacío
        // La plantilla Word ya tiene un valor genérico definido, por lo que no se personaliza
        $afpValue = !empty($afp) ? trim($afp) : '';

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
            'CORREO_PERSONAL' => $correoPersonal,
            'SEXO' => strtoupper($sexo),
            'ESTADO' => strtoupper($estado),
            'TITULO_PERSONA' => $tituloPersona,
            'IDENTIFICADO_IDENTIFICADA' => $identificadoIdentificada,
            'AFILIADO_AFILIADA' => $afiliadoAfiliada,
            'DEL_INTERESADO_DE_LA_INTERESADA' => $delInteresadoDeLaInteresada,
            'ATIENDE_ATENDIO' => $atiendeAtendio,
            'SE_ENCUENTRA_ESTUVO' => $seEncuentraEstuvo,
            'DESARROLLA_ACTUALMENTE_DESARROLLO' => $desarrollaActualmenteDesarrollo,
            'DESTINATARIO_COMPLETO' => $destinatarioCompleto,
            'LISTA_CONVENIOS' => $listaConvenios,
            'UN_CONVENIO_VARIOS_CONVENIOS' => $unConvenioVariosConvenios,
            'AFP' => $afpValue,
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
        $lineasUnicas = []; // Array asociativo para rastrear líneas únicas
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

            // Solo agregar si la línea no existe ya (deduplicación)
            // Usar la línea completa como clave para detectar duplicados
            if (!isset($lineasUnicas[$linea])) {
                $lineasUnicas[$linea] = true;
                $lineas[] = $linea;
            }
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
     * Obtiene la ruta de la plantilla según el tipo de certificado
     *
     * @param bool $esParaBancolombia Indica si es para certificado de Bancolombia
     * @param bool $esParaSubsidioVivienda Indica si es para certificado de subsidio de vivienda
     * @param bool $esParaSubsidioDesempleo Indica si es para certificado de subsidio de desempleo
     * @param bool $esConActividades Indica si es para certificado con actividades
     * @return string
     */
    private function obtenerRutaPlantilla(bool $esParaBancolombia = false, bool $esParaSubsidioVivienda = false, bool $esParaSubsidioDesempleo = false, bool $esConActividades = false, bool $esParaAFP = false): string
    {
        // Seleccionar la plantilla según el tipo de certificado
        if ($esParaAFP) {
            return base_path(self::TEMPLATE_PATH_AFP);
        } elseif ($esConActividades) {
            return base_path(self::TEMPLATE_PATH_ACTIVIDADES);
        } elseif ($esParaBancolombia) {
            return base_path(self::TEMPLATE_PATH_BANCOLOMBIA);
        } elseif ($esParaSubsidioVivienda) {
            return base_path(self::TEMPLATE_PATH_SUBSIDIO_VIVIENDA);
        } elseif ($esParaSubsidioDesempleo) {
            return base_path(self::TEMPLATE_PATH_SUBSIDIO_DESEMPLEO);
        } else {
            return base_path(self::TEMPLATE_PATH);
        }
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
     * @param string|null $tipoCertificado Tipo de certificado: 'basico', 'con_actividades', 'dirigido_afp', 'bancolombia', 'subsidio_vivienda', 'subsidio_desempleo', 'otros'
     * @param bool $tieneCompensaciones Indica si el certificado tiene valores de compensaciones
     * @param string|null $dirigidoAEntidad Entidad a la que está dirigido el certificado
     * @return CertificadoConvenioRecord
     */
    private function crearRegistroCertificado(
        string $documento,
        string $consecutivo,
        string $bucketPath,
        Carbon $fechaCertificado,
        array $afiliadoData,
        ?string $tipoCertificado = null,
        bool $tieneCompensaciones = false,
        ?string $dirigidoAEntidad = null
    ): CertificadoConvenioRecord {
        $record = CertificadoConvenioRecord::create([
            'document_number' => $documento,
            'consecutivo' => $consecutivo,
            'storage_path' => $bucketPath,
            'generated_at' => $fechaCertificado,
            'tipo_certificado' => $tipoCertificado,
            'tiene_compensaciones' => $tieneCompensaciones,
            'dirigido_a_entidad' => $dirigidoAEntidad,
        ]);

        Log::info('Registro de certificado creado en BD', [
            'document_number' => $documento,
            'consecutivo' => $consecutivo,
            'record_id' => $record->id,
            'tipo_certificado' => $tipoCertificado,
            'tiene_compensaciones' => $tieneCompensaciones,
        ]);

        return $record;
    }

    /**
     * Genera el mensaje de compensaciones para el certificado
     * Retorna un array con dos partes separadas para usar en diferentes placeholders
     *
     * @param int $tBasicos Valor de T. Basicos
     * @param int $tAuxilios Valor de T. Auxilios
     * @param int $tIngresos Valor de T. Ingresos (Total)
     * @return array ['parte1' => string, 'parte2' => string]
     */
    private function generarMensajeCompensaciones(int $tBasicos, int $tAuxilios, int $tIngresos): array
    {
        Log::info('Generando mensaje de compensaciones', [
            't_basicos' => $tBasicos,
            't_auxilios' => $tAuxilios,
            't_ingresos' => $tIngresos,
        ]);

        // Formatear valores con separadores de miles (puntos)
        $tBasicosFormateado = number_format($tBasicos, 0, ',', '.');
        $tAuxiliosFormateado = number_format($tAuxilios, 0, ',', '.');
        $tIngresosFormateado = number_format($tIngresos, 0, ',', '.');

        Log::info('Valores formateados para mensaje', [
            't_basicos_formateado' => $tBasicosFormateado,
            't_auxilios_formateado' => $tAuxiliosFormateado,
            't_ingresos_formateado' => $tIngresosFormateado,
        ]);

        // Convertir total a letras
        $totalEnLetras = $this->numeroALetras($tIngresos);

        Log::info('Total en letras generado', [
            'total_numerico' => $tIngresos,
            'total_letras' => $totalEnLetras,
        ]);

        // Construir primera parte: "Con una compensación Básica mensual variable de $X, auxilios por $Y, para un"
        $parte1 = "Con una compensación Básica mensual variable de \${$tBasicosFormateado}";

        if ($tAuxilios > 0) {
            $parte1 .= ", auxilios por \${$tAuxiliosFormateado}";
        }

        $parte1 .= ", para un";

        // Construir segunda parte: "Total de $X. En letras: [número en letras] pesos."
        $parte2 = "Total de \${$tIngresosFormateado}. En letras: {$totalEnLetras} pesos.";

        Log::info('Mensaje de compensaciones generado (dos partes)', [
            'parte1' => $parte1,
            'parte2' => $parte2,
        ]);

        return [
            'parte1' => $parte1,
            'parte2' => $parte2,
        ];
    }

    /**
     * Genera el mensaje de compensaciones para el certificado de subsidio de vivienda
     * Formato especial: "Con una compensación Básica mensual variable de $X, más los beneficios económicos que no hacen parte integral de la compensación Básica por $Y, para un"
     * Retorna un array con dos partes separadas para usar en diferentes placeholders
     *
     * @param int $tBasicos Valor de T. Basicos
     * @param int $tAuxilios Valor de T. Auxilios (beneficios económicos)
     * @param int $tIngresos Valor de T. Ingresos (Total)
     * @return array ['parte1' => string, 'parte2' => string]
     */
    private function generarMensajeCompensacionesSubsidioVivienda(int $tBasicos, int $tAuxilios, int $tIngresos): array
    {
        Log::info('Generando mensaje de compensaciones para subsidio de vivienda', [
            't_basicos' => $tBasicos,
            't_auxilios' => $tAuxilios,
            't_ingresos' => $tIngresos,
        ]);

        // Formatear valores con separadores de miles (puntos)
        $tBasicosFormateado = number_format($tBasicos, 0, ',', '.');
        $tAuxiliosFormateado = number_format($tAuxilios, 0, ',', '.');
        $tIngresosFormateado = number_format($tIngresos, 0, ',', '.');

        Log::info('Valores formateados para mensaje de subsidio de vivienda', [
            't_basicos_formateado' => $tBasicosFormateado,
            't_auxilios_formateado' => $tAuxiliosFormateado,
            't_ingresos_formateado' => $tIngresosFormateado,
        ]);

        // Convertir total a letras
        $totalEnLetras = $this->numeroALetras($tIngresos);

        Log::info('Total en letras generado para subsidio de vivienda', [
            'total_numerico' => $tIngresos,
            'total_letras' => $totalEnLetras,
        ]);

        // Construir primera parte con formato específico para subsidio de vivienda
        // "Con una compensación Básica mensual variable de $X, más los beneficios económicos que no hacen parte integral de la compensación Básica por $Y, para un"
        $parte1 = "Con una compensación Básica mensual variable de \${$tBasicosFormateado}";

        if ($tAuxilios > 0) {
            $parte1 .= ", más los beneficios económicos que no hacen parte integral de la compensación Básica por \${$tAuxiliosFormateado}";
        }

        $parte1 .= ", para un";

        // Construir segunda parte: "Total de $X. En letras: [número en letras] pesos."
        $parte2 = "Total de \${$tIngresosFormateado}. En letras: {$totalEnLetras} pesos.";

        Log::info('Mensaje de compensaciones para subsidio de vivienda generado (dos partes)', [
            'parte1' => $parte1,
            'parte2' => $parte2,
        ]);

        return [
            'parte1' => $parte1,
            'parte2' => $parte2,
        ];
    }

    /**
     * Convierte un número a letras en español
     *
     * @param int $numero Número a convertir
     * @return string Número en letras
     */
    private function numeroALetras(int $numero): string
    {
        if ($numero == 0) {
            return 'cero';
        }

        $millones = intval($numero / 1000000);
        $miles = intval(($numero % 1000000) / 1000);
        $unidades = $numero % 1000;

        $resultado = '';

        // Millones
        if ($millones > 0) {
            if ($millones == 1) {
                $resultado .= 'un millón';
            } else {
                $resultado .= $this->convertirUnidades($millones) . ' millones';
            }
            if ($miles > 0 || $unidades > 0) {
                $resultado .= ' ';
            }
        }

        // Miles
        if ($miles > 0) {
            if ($miles == 1) {
                $resultado .= 'mil';
            } else {
                $resultado .= $this->convertirUnidades($miles) . ' mil';
            }
            if ($unidades > 0) {
                $resultado .= ' ';
            }
        }

        // Unidades
        if ($unidades > 0) {
            $resultado .= $this->convertirUnidades($unidades);
        }

        return trim($resultado);
    }

    /**
     * Convierte un número de 0 a 999 a letras
     *
     * @param int $numero Número entre 0 y 999
     * @return string Número en letras
     */
    private function convertirUnidades(int $numero): string
    {
        if ($numero == 0) {
            return '';
        }

        $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
        $especiales = [
            10 => 'diez', 11 => 'once', 12 => 'doce', 13 => 'trece', 14 => 'catorce',
            15 => 'quince', 16 => 'dieciséis', 17 => 'diecisiete', 18 => 'dieciocho',
            19 => 'diecinueve', 20 => 'veinte', 21 => 'veintiuno', 22 => 'veintidós',
            23 => 'veintitrés', 24 => 'veinticuatro', 25 => 'veinticinco', 26 => 'veintiséis',
            27 => 'veintisiete', 28 => 'veintiocho', 29 => 'veintinueve'
        ];
        $decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        $resultado = '';

        // Centenas
        $centena = intval($numero / 100);
        $resto = $numero % 100;

        if ($centena > 0) {
            if ($centena == 1 && $resto == 0) {
                $resultado = 'cien';
            } else {
                $resultado = $centenas[$centena];
            }
            if ($resto > 0) {
                $resultado .= ' ';
            }
        }

        // Decenas y unidades
        if ($resto > 0) {
            if (isset($especiales[$resto])) {
                $resultado .= $especiales[$resto];
            } else {
                $decena = intval($resto / 10);
                $unidad = $resto % 10;

                if ($decena > 0) {
                    $resultado .= $decenas[$decena];
                    if ($unidad > 0) {
                        $resultado .= ' y ' . $unidades[$unidad];
                    }
                } else {
                    $resultado .= $unidades[$unidad];
                }
            }
        }

        return trim($resultado);
    }

    /**
     * Valida las reglas de negocio para el certificado de convenio
     *
     * @param array $data Datos de la solicitud (payload completo)
     * @param string|null $estadoAfiliado Estado del afiliado ('activo' o 'retirado')
     * @return array Array de mensajes de error (vacío si no hay errores)
     */
    public function validarCertificadoConvenio(array $data, ?string $estadoAfiliado = null): array
    {
        $errors = [];
        $info = $data['infoCertificado'] ?? [];

        // Parsear infoCertificado si viene como string JSON
        if (is_string($info)) {
            $info = json_decode($info, true);
            if (!is_array($info)) {
                $info = [];
            }
        }

        // Helper para parsear valores booleanos (pueden venir como string "true"/"false", boolean, o int 1/0)
        $parseBoolean = function ($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
            }
            if (is_numeric($value)) {
                return (bool) $value;
            }
            return false;
        };

        // Extraer valores booleanos
        $fechaIngresoRetiro = $parseBoolean($info['fechaIngresoRetiro'] ?? false);
        $valorCompensaciones = $parseBoolean($info['valorCompensaciones'] ?? false);
        $paraSubsidioVivienda = $parseBoolean($info['paraSubsidioVivienda'] ?? false);
        $paraSubsidioDesempleo = $parseBoolean($info['paraSubsidioDesempleo'] ?? false);
        $dirigidoAEntidad = $parseBoolean($info['dirigidoAEntidad'] ?? false);
        $dirigidoFondoPensiones = $parseBoolean($info['dirigidoFondoPensiones'] ?? false);
        $dirigidoBancolombia = $parseBoolean($info['dirigidoBancolombia'] ?? false);
        $adicionarActividades = $parseBoolean($info['adicionarActividades'] ?? false);
        $otros = $parseBoolean($info['otros'] ?? false);

        // 1. Validación obligatoria: fechaIngresoRetiro siempre debe ser true
        if (!$fechaIngresoRetiro) {
            $errors[] = 'fechaIngresoRetiro debe estar siempre marcado';
        }

        // 2. Validaciones según estado del afiliado
        $estado = $estadoAfiliado ? strtolower(trim($estadoAfiliado)) : null;
        if ($estado === 'activo' && $paraSubsidioDesempleo) {
            $errors[] = 'Para subsidio de desempleo no está disponible para afiliados activos';
        }
        if ($estado === 'retirado' && $paraSubsidioVivienda) {
            $errors[] = 'Para subsidio de vivienda no está disponible para afiliados retirados';
        }

        // 3. Validación: Si hay subsidio, valorCompensaciones es obligatorio
        $tieneSubsidio = $paraSubsidioVivienda || $paraSubsidioDesempleo;
        if ($tieneSubsidio && !$valorCompensaciones) {
            $errors[] = 'Valor de compensaciones es obligatorio cuando se selecciona un subsidio';
        }

        // 4. Validación: Subsidios son mutuamente excluyentes
        if ($paraSubsidioVivienda && $paraSubsidioDesempleo) {
            $errors[] = 'Para subsidio de vivienda y Para subsidio de desempleo son mutuamente excluyentes';
        }

        // 5. Validación: Dirigido a Bancolombia y Dirigido a entidad son excluyentes
        if ($dirigidoBancolombia && $dirigidoAEntidad) {
            $errors[] = 'Dirigido a Bancolombia y Dirigido a una entidad en particular son mutuamente excluyentes';
        }

        // 6. Validación: Si hay subsidio, no puede haber dirigidoAEntidad
        if ($tieneSubsidio && $dirigidoAEntidad) {
            $errors[] = 'Dirigido a una entidad en particular no puede seleccionarse con subsidios';
        }

        // 7. Validación: Si hay subsidio, no puede haber dirigidoBancolombia
        if ($tieneSubsidio && $dirigidoBancolombia) {
            $errors[] = 'Dirigido a Bancolombia no puede seleccionarse con subsidios';
        }

        // 8. Validación: Si hay subsidio, no puede haber dirigidoFondoPensiones
        if ($tieneSubsidio && $dirigidoFondoPensiones) {
            $errors[] = 'Dirigido al Fondo de Pensiones no puede seleccionarse con subsidios';
        }

        // 9. Validación: Si hay subsidio, no puede haber otros
        if ($tieneSubsidio && $otros) {
            $errors[] = 'Otros no puede seleccionarse con subsidios';
        }

        // 10. Validación: Si hay subsidio, no puede haber adicionarActividades
        if ($tieneSubsidio && $adicionarActividades) {
            $errors[] = 'Adicionar actividades no puede seleccionarse con subsidios';
        }

        // 11. Validación: valorCompensaciones sin subsidios no puede estar con dirigidoFondoPensiones
        if ($valorCompensaciones && !$tieneSubsidio && $dirigidoFondoPensiones) {
            $errors[] = 'Valor de compensaciones no puede seleccionarse con Dirigido al Fondo de Pensiones cuando no hay subsidios';
        }

        // 12. Validación: valorCompensaciones sin subsidios no puede estar con dirigidoBancolombia
        if ($valorCompensaciones && !$tieneSubsidio && $dirigidoBancolombia) {
            $errors[] = 'Valor de compensaciones no puede seleccionarse con Dirigido a Bancolombia cuando no hay subsidios';
        }

        // 13. Validación: dirigidoBancolombia no puede estar con otros
        if ($dirigidoBancolombia && $otros) {
            $errors[] = 'Dirigido a Bancolombia no puede seleccionarse con Otros';
        }

        // 14. Validación: dirigidoBancolombia no puede estar con adicionarActividades
        if ($dirigidoBancolombia && $adicionarActividades) {
            $errors[] = 'Dirigido a Bancolombia no puede seleccionarse con Adicionar actividades';
        }

        // 15. Validación: dirigidoFondoPensiones no puede estar con otros
        if ($dirigidoFondoPensiones && $otros) {
            $errors[] = 'Dirigido al Fondo de Pensiones no puede seleccionarse con Otros';
        }

        // 16. Validación: dirigidoFondoPensiones no puede estar con adicionarActividades
        if ($dirigidoFondoPensiones && $adicionarActividades) {
            $errors[] = 'Dirigido al Fondo de Pensiones no puede seleccionarse con Adicionar actividades';
        }

        // 17. Validación: dirigidoFondoPensiones no puede estar con valorCompensaciones (sin subsidios)
        if ($dirigidoFondoPensiones && $valorCompensaciones && !$tieneSubsidio) {
            $errors[] = 'Dirigido al Fondo de Pensiones no puede seleccionarse con Valor de compensaciones cuando no hay subsidios';
        }

        // 18. Validación: Campos dependientes - dirigidoAQuien es obligatorio si dirigidoAEntidad es true
        if ($dirigidoAEntidad) {
            $dirigidoAQuien = $data['dirigidoAQuien'] ?? '';
            if (empty(trim($dirigidoAQuien))) {
                $errors[] = 'dirigidoAQuien es obligatorio cuando se selecciona Dirigido a una entidad en particular';
            }
        }

        // 19. Validación: Campos dependientes - actividadesPdf es obligatorio si adicionarActividades es true
        if ($adicionarActividades) {
            $actividadesPdf = $data['actividadesPdf'] ?? null;
            // Verificar si viene en files o directamente
            $hasActividadesPdf = false;
            if (isset($data['files']['actividadesPdf'])) {
                $hasActividadesPdf = true;
            } elseif (isset($data['actividadesPdf'])) {
                $hasActividadesPdf = true;
            }
            if (!$hasActividadesPdf) {
                $errors[] = 'actividadesPdf es obligatorio cuando se selecciona Adicionar actividades';
            }
        }

        // 20. Validación: Campos dependientes - otrosDescripcion es obligatorio si otros es true
        if ($otros) {
            $otrosDescripcion = $data['otrosDescripcion'] ?? '';
            if (empty(trim($otrosDescripcion))) {
                $errors[] = 'otrosDescripcion es obligatorio cuando se selecciona Otros';
            }
        }

        return $errors;
    }

    /**
     * Obtiene estadísticas y métricas de certificados de convenio generados
     *
     * @param string|null $fechaDesde Fecha desde (formato Y-m-d)
     * @param string|null $fechaHasta Fecha hasta (formato Y-m-d)
     * @return array Estadísticas agrupadas por tipo de certificado y características
     */
    public function obtenerEstadisticasCertificados(?string $fechaDesde = null, ?string $fechaHasta = null): array
    {
        // Función helper para crear una nueva consulta base con los filtros aplicados
        $baseQuery = function () use ($fechaDesde, $fechaHasta) {
            $query = CertificadoConvenioRecord::query();
            if ($fechaDesde || $fechaHasta) {
                $query->byFechaRango($fechaDesde, $fechaHasta);
            }
            return $query;
        };

        $totalCertificados = $baseQuery()->count();

        // Estadísticas por tipo de certificado
        $porTipo = $baseQuery()
            ->selectRaw('tipo_certificado, COUNT(*) as cantidad')
            ->groupBy('tipo_certificado')
            ->get()
            ->pluck('cantidad', 'tipo_certificado')
            ->toArray();

        // Certificados con compensaciones
        $conCompensaciones = $baseQuery()
            ->where('tiene_compensaciones', true)
            ->count();

        // Certificados con actividades
        $conActividades = $baseQuery()
            ->where('tipo_certificado', 'con_actividades')
            ->count();

        // Certificados dirigidos a AFP
        $dirigidosAFP = $baseQuery()
            ->where('tipo_certificado', 'dirigido_afp')
            ->count();

        // Certificados para subsidio de vivienda
        $subsidioVivienda = $baseQuery()
            ->where('tipo_certificado', 'subsidio_vivienda')
            ->count();

        // Certificados para Bancolombia
        $bancolombia = $baseQuery()
            ->where('tipo_certificado', 'bancolombia')
            ->count();

        // Certificados para subsidio de desempleo
        $subsidioDesempleo = $baseQuery()
            ->where('tipo_certificado', 'subsidio_desempleo')
            ->count();

        // Certificados básicos
        $basicos = $baseQuery()
            ->where('tipo_certificado', 'basico')
            ->count();

        // Certificados tipo "otros"
        $otros = $baseQuery()
            ->where('tipo_certificado', 'otros')
            ->count();

        // Top entidades a las que se dirigen los certificados
        // Temporalmente comentado - no se comparte este dato por ahora
        /*
        $topEntidades = $baseQuery()
            ->whereNotNull('dirigido_a_entidad')
            ->selectRaw('dirigido_a_entidad, COUNT(*) as cantidad')
            ->groupBy('dirigido_a_entidad')
            ->orderByDesc('cantidad')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'entidad' => $item->dirigido_a_entidad,
                    'cantidad' => $item->cantidad,
                ];
            })
            ->toArray();
        */
        $topEntidades = [];

        // Distribución por mes (últimos 12 meses)
        $distribucionMensual = $baseQuery()
            ->selectRaw('
                DATE_FORMAT(generated_at, "%Y-%m") as mes,
                COUNT(*) as cantidad
            ')
            ->where('generated_at', '>=', now()->subMonths(12))
            ->groupBy('mes')
            ->orderBy('mes')
            ->get()
            ->map(function ($item) {
                return [
                    'mes' => $item->mes,
                    'cantidad' => $item->cantidad,
                ];
            })
            ->toArray();

        return [
            'resumen' => [
                'total_certificados' => $totalCertificados,
                'con_compensaciones' => $conCompensaciones,
                'con_actividades' => $conActividades,
                'dirigidos_afp' => $dirigidosAFP,
                'subsidio_vivienda' => $subsidioVivienda,
                'bancolombia' => $bancolombia,
                'subsidio_desempleo' => $subsidioDesempleo,
                'basicos' => $basicos,
                'otros' => $otros,
            ],
            'por_tipo' => $porTipo,
            // 'top_entidades' => $topEntidades, // Temporalmente comentado - no se comparte este dato por ahora
            'distribucion_mensual' => $distribucionMensual,
            'filtros_aplicados' => [
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
            ],
        ];
    }
}

