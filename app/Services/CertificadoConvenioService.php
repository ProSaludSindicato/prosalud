<?php

namespace App\Services;

use App\Models\CertificadoConvenioRecord;
use App\Support\HospitalCatalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;

class CertificadoConvenioService
{
    private const TEMPLATE_PATH = 'resources/templates/certificado_convenio_template.docx';

    private const TEMPLATE_PATH_BANCOLOMBIA = 'resources/templates/certificado_convenio_cuenta_bancolombia_template.docx';

    private const TEMPLATE_PATH_SUBSIDIO_VIVIENDA = 'resources/templates/certificado_convenio_subsidio_vivienda_template.docx';

    private const TEMPLATE_PATH_SUBSIDIO_DESEMPLEO = 'resources/templates/certificado_convenio_subsidio_desempleo_template.docx';

    private const TEMPLATE_PATH_ACTIVIDADES = 'resources/templates/certificado_convenio_actividades_template.docx';

    private const TEMPLATE_PATH_AFP = 'resources/templates/certificado_convenio_dirigido_afp_template.docx';

    private const TEMP_DIR = 'temp';

    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Espaciado máximo entre párrafos (twips). 720 ≈ 36pt. */
    private const MAX_PARAGRAPH_SPACING_TWIPS = 720;

    /** Desplazamiento vertical del ancla de firma (EMU). Negativo sube la imagen respecto al nombre. */
    private const SIGNATURE_ANCHOR_V_OFFSET_EMU = -280000;

    private const CERTIFICATE_BODY_FONT = 'Calibri';

    public function __construct(
        private AfiliadoService $afiliadoService,
        private ExcelReaderService $excelReaderService
    ) {}

    /**
     * Genera un certificado de convenio en formato PDF
     * Primero genera el Word desde la plantilla, luego lo convierte a PDF usando CloudConvert
     * Guarda el PDF en el bucket privado y crea un registro en la base de datos
     *
     * @param  string  $documento  Número de documento del afiliado
     * @param  string|null  $dirigidoAEntidad  Nombre de la entidad destinataria (opcional)
     * @param  array|null  $compensaciones  ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int] o ['mensaje_compensaciones_parte1' => string, 'mensaje_compensaciones_parte2' => string]
     * @param  bool  $esParaBancolombia  Indica si el certificado es para apertura de cuenta en Bancolombia (opcional)
     * @param  bool  $esParaSubsidioVivienda  Indica si el certificado es para subsidio de vivienda (opcional)
     * @param  bool  $esParaSubsidioDesempleo  Indica si el certificado es para subsidio de desempleo (opcional)
     * @param  bool  $esOtros  Indica si el certificado es tipo "otros" (necesidad específica descrita por el usuario) (opcional)
     */
    public function generarCertificadoPDF(string $documento, ?string $dirigidoAEntidad = null, ?array $compensaciones = null, bool $esParaBancolombia = false, bool $esParaSubsidioVivienda = false, bool $esParaSubsidioDesempleo = false, bool $esOtros = false): array
    {
        $startTime = microtime(true);

        // Obtener información del afiliado primero para tener los datos necesarios
        $afiliadoData = $this->obtenerDatosAfiliado($documento);
        if (! $afiliadoData) {
            throw new \Exception("Afiliado con documento {$documento} no encontrado");
        }

        // Capturar la fecha una sola vez para usar consistentemente
        $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        $consecutivo = $this->generarConsecutivo($fechaCertificado);

        // Primero generar el Word desde la plantilla (pasar el consecutivo, destinatario y compensaciones para mantener consistencia)
        $resultadoWord = $this->generarCertificadoWord($documento, $consecutivo, $dirigidoAEntidad, $compensaciones, $esParaBancolombia, $esParaSubsidioVivienda, $esParaSubsidioDesempleo);

        try {
            // Convertir Word a PDF usando CloudConvert
            $converterService = app(\App\Services\DocxToPdfService::class);
            $resultadoPDF = $converterService->convert($resultadoWord['ruta'], true);

            // Limpiar archivo Word temporal
            if (file_exists($resultadoWord['ruta'])) {
                @unlink($resultadoWord['ruta']);
            }

            // Cambiar extensión del nombre de archivo
            $nombrePDF = str_replace('.docx', '.pdf', $resultadoWord['nombre']);

            // Obtener ruta absoluta del PDF
            $rutaPDF = storage_path('app/'.$resultadoPDF['path']);

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
                $compensaciones !== null && ! empty($compensaciones),
                $dirigidoAEntidad
            );

            // Agregar métricas en el procesamiento
            Log::info('Recursos utilizados durante generación de certificado', [
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB',
                'execution_time' => round(microtime(true) - $startTime, 2).' segundos',
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
     * @param  string  $documento  Número de documento del afiliado
     * @param  string|null  $consecutivo  Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @param  string|null  $dirigidoAEntidad  Nombre de la entidad destinataria (opcional)
     * @param  array|null  $compensaciones  ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int] o ['mensaje_compensaciones_parte1' => string, 'mensaje_compensaciones_parte2' => string]
     * @param  bool  $esParaBancolombia  Indica si el certificado es para apertura de cuenta en Bancolombia (opcional)
     * @param  bool  $esParaSubsidioVivienda  Indica si el certificado es para subsidio de vivienda (opcional)
     * @param  bool  $esParaSubsidioDesempleo  Indica si el certificado es para subsidio de desempleo (opcional)
     */
    public function generarCertificadoWord(string $documento, ?string $consecutivo = null, ?string $dirigidoAEntidad = null, ?array $compensaciones = null, bool $esParaBancolombia = false, bool $esParaSubsidioVivienda = false, bool $esParaSubsidioDesempleo = false): array
    {
        // Capturar la fecha una sola vez para usar consistentemente en todo el certificado
        // Esto evita problemas de zona horaria y cambios de día entre llamadas
        $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));

        // Obtener información del afiliado
        $afiliadoData = $this->obtenerDatosAfiliado($documento);

        if (! $afiliadoData) {
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
        if (! file_exists($templatePath)) {
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
        $rutaSalida = storage_path('app/'.self::TEMP_DIR."/{$nombreArchivo}");

        // Crear directorio si no existe
        $tempDir = storage_path('app/'.self::TEMP_DIR);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Guardar documento
        $templateProcessor->saveAs($rutaSalida);
        $this->limpiarCamposLegacyWord($rutaSalida);

        return [
            'ruta' => $rutaSalida,
            'nombre' => $nombreArchivo,
            'tipo' => 'docx',
        ];
    }

    /**
     * Genera un certificado de convenio con actividades en formato Word
     *
     * @param  string  $documento  Número de documento del afiliado
     * @param  array  $actividades  Array de actividades a incluir en el certificado
     * @param  string|null  $consecutivo  Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @param  string|null  $dirigidoAEntidad  Nombre de la entidad destinataria (opcional)
     */
    public function generarCertificadoWordConActividades(string $documento, array $actividades, ?string $consecutivo = null, ?string $dirigidoAEntidad = null): array
    {
        // Capturar la fecha una sola vez para usar consistentemente en todo el certificado
        $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));

        // Obtener información del afiliado
        $afiliadoData = $this->obtenerDatosAfiliado($documento);

        if (! $afiliadoData) {
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
        if (! file_exists($templatePath)) {
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
        $rutaSalida = storage_path('app/'.self::TEMP_DIR."/{$nombreArchivo}");

        // Crear directorio si no existe
        $tempDir = storage_path('app/'.self::TEMP_DIR);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Guardar documento
        $templateProcessor->saveAs($rutaSalida);
        $this->limpiarCamposLegacyWord($rutaSalida);

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
     * @param  string  $documento  Número de documento del afiliado
     * @param  array  $actividades  Array de actividades a incluir en el certificado
     * @param  string|null  $consecutivo  Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @param  string|null  $dirigidoAEntidad  Nombre de la entidad destinataria (opcional)
     */
    public function generarCertificadoPDFConActividades(string $documento, array $actividades, ?string $consecutivo = null, ?string $dirigidoAEntidad = null): array
    {
        $startTime = microtime(true);

        // Obtener información del afiliado primero para tener los datos necesarios
        $afiliadoData = $this->obtenerDatosAfiliado($documento);
        if (! $afiliadoData) {
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
            $converterService = app(\App\Services\DocxToPdfService::class);
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
            $rutaPDF = storage_path('app/'.$resultadoPDF['path']);

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
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB',
                'execution_time' => round(microtime(true) - $startTime, 2).' segundos',
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
     * @param  string  $documento  Número de documento del afiliado
     * @param  string|null  $afp  Nombre del fondo de pensiones (AFP)
     * @param  string|null  $consecutivo  Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     */
    public function generarCertificadoWordDirigidoAFP(string $documento, ?string $afp = null, ?string $consecutivo = null): array
    {
        // Capturar la fecha una sola vez para usar consistentemente en todo el certificado
        $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));

        // Obtener información del afiliado
        $afiliadoData = $this->obtenerDatosAfiliado($documento);

        if (! $afiliadoData) {
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
        if (! file_exists($templatePath)) {
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
        $rutaSalida = storage_path('app/'.self::TEMP_DIR."/{$nombreArchivo}");

        // Crear directorio si no existe
        $tempDir = storage_path('app/'.self::TEMP_DIR);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Guardar documento
        $templateProcessor->saveAs($rutaSalida);
        $this->limpiarCamposLegacyWord($rutaSalida);

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
     * @param  string  $documento  Número de documento del afiliado
     * @param  string|null  $afp  Nombre del fondo de pensiones (AFP)
     * @param  string|null  $consecutivo  Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     */
    public function generarCertificadoPDFDirigidoAFP(string $documento, ?string $afp = null, ?string $consecutivo = null): array
    {
        $startTime = microtime(true);

        // Obtener información del afiliado primero para tener los datos necesarios
        $afiliadoData = $this->obtenerDatosAfiliado($documento);
        if (! $afiliadoData) {
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
            $converterService = app(\App\Services\DocxToPdfService::class);
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
            $rutaPDF = storage_path('app/'.$resultadoPDF['path']);

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
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB',
                'execution_time' => round(microtime(true) - $startTime, 2).' segundos',
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
     * Obtiene los datos del afiliado por documento (API ProSanet primero, Excel como respaldo).
     * Método público para uso desde otros servicios
     */
    public function obtenerDatosAfiliado(string $documento): ?array
    {
        $fromApi = $this->afiliadoService->getAfiliadoCertificadoRawByDocumento($documento);
        if ($fromApi !== false) {
            if ($fromApi === null) {
                return null;
            }

            $convenios = $fromApi['convenios'];

            return [
                'afiliado' => $fromApi['afiliado'],
                'convenio' => $this->selectConvenioActualForCertificado($convenios),
                'todos_los_convenios' => $this->sortTodosLosConveniosForCertificado($convenios),
            ];
        }

        return $this->obtenerDatosAfiliadoFromExcel($documento);
    }

    /**
     * Respaldo Excel cuando la API ProSanet no está disponible.
     */
    private function obtenerDatosAfiliadoFromExcel(string $documento): ?array
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
                    if (! Storage::disk($disk)->exists($excelPath)) {
                        continue;
                    }

                    // Crear copia temporal
                    $stream = Storage::disk($disk)->readStream($excelPath);
                    if ($stream === false) {
                        continue;
                    }

                    $tempPath = tempnam(sys_get_temp_dir(), 'prosanet_certificado_').'.xlsx';
                    $destination = fopen($tempPath, 'w+b');
                    if ($destination === false) {
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

                        if (! $informacionSheet) {
                            @unlink($tempPath);

                            continue;
                        }

                        $highestRow = $informacionSheet->getHighestRow();
                        $normalizedDocumento = $this->normalizeDocumento($documento);

                        // Buscar por documento leyendo solo la columna B primero (optimización)
                        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
                            // Leer solo la columna del documento primero
                            $cell = $informacionSheet->getCell('B'.$rowIndex);
                            $rowDocumento = $this->normalizeDocumento($cell->getValue());

                            if ($rowDocumento === $normalizedDocumento) {
                                // Encontrado, leer solo las columnas necesarias
                                $rowData = [];
                                // Solo leer las columnas que necesitamos: 0,1,2,3,4,8,10,18,20,32 (AFP)
                                $neededColumns = [0, 1, 2, 3, 4, 8, 10, 18, 20, 32];
                                foreach ($neededColumns as $colIndex) {
                                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                                    $cell = $informacionSheet->getCell($colLetter.$rowIndex);
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
                                    $convenioActual = ! empty($conveniosFull) ? $conveniosFull[0] : null;
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
                    $isPrivateDiskUnavailable = $disk === 'prosalud-private'
                        && str_contains($e->getMessage(), 'Unable to check existence');

                    if ($isPrivateDiskUnavailable) {
                        Log::warning('Disco privado no disponible al buscar Excel de afiliados para certificado', [
                            'documento' => $documento,
                            'disk' => $disk,
                            'error' => $e->getMessage(),
                        ]);
                    } else {
                        Log::error('Error al acceder al archivo Excel para certificado', [
                            'documento' => $documento,
                            'disk' => $disk,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return null;
        } finally {
            // Restaurar límites originales
            if ($originalMemoryLimit !== false && $originalMemoryLimit !== null) {
                ini_set('memory_limit', (string) $originalMemoryLimit);
            }
            if ($originalMaxExecutionTime !== false && $originalMaxExecutionTime !== null) {
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

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $cell = $sheet->getCell('A'.$rowIndex);
            $rowDocumento = $this->normalizeDocumento($cell->getValue());

            if ($rowDocumento === $normalizedDocumento) {
                $convenios[] = [
                    'cliente' => $this->normalizeValue($sheet->getCell('D'.$rowIndex)->getValue() ?? ''),
                    'proceso' => $this->normalizeValue($sheet->getCell('F'.$rowIndex)->getValue() ?? ''),
                    'estado' => $this->normalizeValue($sheet->getCell('G'.$rowIndex)->getValue() ?? ''),
                    'fecha_ingreso' => $this->normalizeDate($sheet->getCell('H'.$rowIndex)->getValue() ?? ''),
                    'fecha_fin' => $this->normalizeDate($sheet->getCell('I'.$rowIndex)->getValue() ?? ''),
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
            if (! $aFechaFinVacia && $bFechaFinVacia) {
                return -1; // $a (finalizado) va primero
            }
            if ($aFechaFinVacia && ! $bFechaFinVacia) {
                return 1; // $b (finalizado) va primero
            }

            // Si ambos tienen fecha_fin, ordenar por fecha_fin descendente (más reciente primero)
            if (! $aFechaFinVacia && ! $bFechaFinVacia) {
                return strcmp($b['fecha_fin'], $a['fecha_fin']);
            }

            // Si ambos están vigentes (sin fecha_fin), mantener el orden por fecha_ingreso
            return 0;
        });

        return $convenios;
    }

    /**
     * @param  list<array<string, mixed>>  $convenios
     */
    private function selectConvenioActualForCertificado(array $convenios): ?array
    {
        $selected = $this->selectConvenioFromList($convenios);

        return $selected;
    }

    /**
     * @param  list<array<string, mixed>>  $convenios
     * @return list<array<string, mixed>>
     */
    private function sortTodosLosConveniosForCertificado(array $convenios): array
    {
        usort($convenios, function (array $a, array $b): int {
            $comparisonFechaIngreso = strcmp((string) ($a['fecha_ingreso'] ?? ''), (string) ($b['fecha_ingreso'] ?? ''));
            if ($comparisonFechaIngreso !== 0) {
                return $comparisonFechaIngreso;
            }

            $aFechaFinVacia = empty($a['fecha_fin']);
            $bFechaFinVacia = empty($b['fecha_fin']);

            if (! $aFechaFinVacia && $bFechaFinVacia) {
                return -1;
            }
            if ($aFechaFinVacia && ! $bFechaFinVacia) {
                return 1;
            }

            if (! $aFechaFinVacia && ! $bFechaFinVacia) {
                return strcmp((string) $b['fecha_fin'], (string) $a['fecha_fin']);
            }

            return 0;
        });

        return $convenios;
    }

    /**
     * Selecciona el convenio activo más reciente, o el más reciente si no hay activos.
     *
     * @param  list<array<string, mixed>>  $convenios
     */
    private function selectConvenioFromList(array $convenios): ?array
    {
        if ($convenios === []) {
            return null;
        }

        $conveniosActivos = array_filter($convenios, function (array $conv): bool {
            return strcasecmp((string) ($conv['estado'] ?? ''), 'Activo') === 0;
        });

        if (! empty($conveniosActivos)) {
            usort($conveniosActivos, function (array $a, array $b): int {
                $aFechaFinVacia = empty($a['fecha_fin']);
                $bFechaFinVacia = empty($b['fecha_fin']);

                if ($aFechaFinVacia && ! $bFechaFinVacia) {
                    return -1;
                }
                if (! $aFechaFinVacia && $bFechaFinVacia) {
                    return 1;
                }

                if (! $aFechaFinVacia && ! $bFechaFinVacia) {
                    $comparison = strcmp((string) $b['fecha_fin'], (string) $a['fecha_fin']);
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return strcmp((string) $b['fecha_ingreso'], (string) $a['fecha_ingreso']);
            });

            return reset($conveniosActivos) ?: null;
        }

        usort($convenios, function (array $a, array $b): int {
            $aFechaFinVacia = empty($a['fecha_fin']);
            $bFechaFinVacia = empty($b['fecha_fin']);

            if ($aFechaFinVacia && ! $bFechaFinVacia) {
                return 1;
            }
            if (! $aFechaFinVacia && $bFechaFinVacia) {
                return -1;
            }

            $comparison = strcmp((string) $b['fecha_fin'], (string) $a['fecha_fin']);
            if ($comparison !== 0) {
                return $comparison;
            }

            return strcmp((string) $b['fecha_ingreso'], (string) $a['fecha_ingreso']);
        });

        return $convenios[0] ?? null;
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

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $cell = $sheet->getCell('A'.$rowIndex);
            $rowDocumento = $this->normalizeDocumento($cell->getValue());

            if ($rowDocumento === $normalizedDocumento) {
                $convenios[] = [
                    'cliente' => $this->normalizeValue($sheet->getCell('D'.$rowIndex)->getValue() ?? ''),
                    'proceso' => $this->normalizeValue($sheet->getCell('F'.$rowIndex)->getValue() ?? ''),
                    'estado' => $this->normalizeValue($sheet->getCell('G'.$rowIndex)->getValue() ?? ''),
                    'fecha_ingreso' => $this->normalizeDate($sheet->getCell('H'.$rowIndex)->getValue() ?? ''),
                    'fecha_fin' => $this->normalizeDate($sheet->getCell('I'.$rowIndex)->getValue() ?? ''),
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

        if (! empty($conveniosActivos)) {
            // Si hay convenios activos, seleccionar el más reciente/actual
            // Prioridad: fecha_fin vacía > fecha_fin más reciente > fecha_ingreso más reciente
            usort($conveniosActivos, function ($a, $b) {
                // Si uno tiene fecha_fin vacía y el otro no, el vacío tiene prioridad
                $aFechaFinVacia = empty($a['fecha_fin']);
                $bFechaFinVacia = empty($b['fecha_fin']);

                if ($aFechaFinVacia && ! $bFechaFinVacia) {
                    return -1; // $a tiene prioridad
                }
                if (! $aFechaFinVacia && $bFechaFinVacia) {
                    return 1; // $b tiene prioridad
                }

                // Si ambos tienen fecha_fin o ambos están vacíos, comparar por fecha_fin
                if (! $aFechaFinVacia && ! $bFechaFinVacia) {
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

                if ($aFechaFinVacia && ! $bFechaFinVacia) {
                    return 1; // $b tiene prioridad
                }
                if (! $aFechaFinVacia && $bFechaFinVacia) {
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
     * @param  Carbon|null  $fechaCertificado  Instancia de Carbon con la fecha del certificado (opcional, usa now() si no se proporciona)
     * @param  string|null  $consecutivo  Número consecutivo opcional (si no se proporciona, se genera uno nuevo)
     * @param  string|null  $dirigidoAEntidad  Nombre de la entidad destinataria (opcional)
     * @param  array|null  $compensaciones  Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
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
            trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''))
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

        // Generar mensaje de compensaciones: texto redactado (API) o construido desde t_basicos/t_auxilios/t_ingresos
        $mensajeCompensacionesParte1 = '';
        $mensajeCompensacionesParte2 = '';
        if ($compensaciones && isset($compensaciones['mensaje_compensaciones_parte1']) && (string) $compensaciones['mensaje_compensaciones_parte1'] !== '') {
            $mensajeCompensacionesParte1 = (string) $compensaciones['mensaje_compensaciones_parte1'];
            $mensajeCompensacionesParte2 = (string) ($compensaciones['mensaje_compensaciones_parte2'] ?? '');
            Log::info('Usando mensaje de compensaciones redactado en prepararDatosCertificado');
        } elseif ($compensaciones && isset($compensaciones['t_basicos']) && isset($compensaciones['t_auxilios']) && isset($compensaciones['t_ingresos'])) {
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
                'tiene_compensaciones' => ! empty($compensaciones),
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
     * @param  Carbon|null  $fechaCertificado  Instancia de Carbon con la fecha del certificado
     * @param  string|null  $consecutivo  Número consecutivo
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
            trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''))
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
     * @param  Carbon|null  $fechaCertificado  Instancia de Carbon con la fecha del certificado
     * @param  string|null  $consecutivo  Número consecutivo
     * @param  array|null  $compensaciones  Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
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
            trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''))
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

        // Generar mensaje de compensaciones: texto redactado (API) o construido desde valores
        $mensajeCompensacionesParte1 = '';
        $mensajeCompensacionesParte2 = '';
        if ($compensaciones && isset($compensaciones['mensaje_compensaciones_parte1']) && (string) $compensaciones['mensaje_compensaciones_parte1'] !== '') {
            $mensajeCompensacionesParte1 = (string) $compensaciones['mensaje_compensaciones_parte1'];
            $mensajeCompensacionesParte2 = (string) ($compensaciones['mensaje_compensaciones_parte2'] ?? '');
            Log::info('Usando mensaje de compensaciones redactado en prepararDatosCertificadoSubsidioVivienda');
        } elseif ($compensaciones && isset($compensaciones['t_basicos']) && isset($compensaciones['t_auxilios']) && isset($compensaciones['t_ingresos'])) {
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
                'tiene_compensaciones' => ! empty($compensaciones),
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
     * @param  Carbon|null  $fechaCertificado  Instancia de Carbon con la fecha del certificado
     * @param  string|null  $consecutivo  Número consecutivo
     * @param  array|null  $compensaciones  Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
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
            trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''))
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
        if ($convenio && ! empty($convenio['fecha_fin'])) {
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

        // Generar mensaje de compensaciones: texto redactado (API) o construido desde valores
        $mensajeCompensacionesParte1 = '';
        $mensajeCompensacionesParte2 = '';
        if ($compensaciones && isset($compensaciones['mensaje_compensaciones_parte1']) && (string) $compensaciones['mensaje_compensaciones_parte1'] !== '') {
            $mensajeCompensacionesParte1 = (string) $compensaciones['mensaje_compensaciones_parte1'];
            $mensajeCompensacionesParte2 = (string) ($compensaciones['mensaje_compensaciones_parte2'] ?? '');
            Log::info('Usando mensaje de compensaciones redactado en prepararDatosCertificadoSubsidioDesempleo');
        } elseif ($compensaciones && isset($compensaciones['t_basicos']) && isset($compensaciones['t_auxilios']) && isset($compensaciones['t_ingresos'])) {
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
                'tiene_compensaciones' => ! empty($compensaciones),
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
     * @param  Carbon|null  $fechaCertificado  Instancia de Carbon con la fecha del certificado
     * @param  string|null  $consecutivo  Número consecutivo
     * @param  string|null  $dirigidoAEntidad  Nombre de la entidad destinataria (opcional)
     * @param  array  $actividades  Array de actividades a incluir en el certificado
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
            trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''))
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
     * @param  array  $actividades  Array de strings con las actividades
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
            fn ($actividad) => ! empty($actividad)
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
     * @param  Carbon|null  $fechaCertificado  Instancia de Carbon con la fecha del certificado
     * @param  string|null  $consecutivo  Número consecutivo
     * @param  string|null  $afp  Nombre del fondo de pensiones (AFP)
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
            trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''))
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
        if (! empty($afp)) {
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
        $afpValue = ! empty($afp) ? trim($afp) : '';

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
        if (! $fecha) {
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
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
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
            9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'dic.',
        ];

        return $meses[$mes] ?? '';
    }

    /**
     * Formatea fecha en formato corto (ej: "ene. 01/2019")
     */
    private function formatearFechaCorta($fecha): string
    {
        if (! $fecha) {
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
            $clienteRaw = trim((string) ($convenio['cliente'] ?? ''));
            $fechaIngreso = trim((string) ($convenio['fecha_ingreso'] ?? ''));

            if ($clienteRaw === '' && $fechaIngreso === '') {
                continue;
            }

            $cliente = $this->transformarCliente($clienteRaw);
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
                if (! empty($fechaFinFormateada)) {
                    $fechaFinFormateada = "hasta {$fechaFinFormateada}";
                }
            }

            $linea = "❖ {$cliente}";
            if (! empty($fechaIngresoFormateada)) {
                $linea .= " , desde {$fechaIngresoFormateada}";
            }
            if (! empty($fechaFinFormateada)) {
                $linea .= " {$fechaFinFormateada}";
            }

            // Solo agregar si la línea no existe ya (deduplicación)
            // Usar la línea completa como clave para detectar duplicados
            if (! isset($lineasUnicas[$linea])) {
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
        if ($cliente === null || trim($cliente) === '') {
            return 'No asignado';
        }

        return HospitalCatalog::resolve($cliente);
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
     * @param  Carbon|null  $fechaCertificado  Instancia de Carbon con la fecha del certificado (opcional, usa now() si no se proporciona)
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
     * @param  Carbon|null  $fechaCertificado  Instancia de Carbon con la fecha del certificado (opcional, usa now() si no se proporciona)
     */
    private function generarConsecutivo(?Carbon $fechaCertificado = null): string
    {
        // Usar la misma fecha que se usa en el contenido del certificado
        if ($fechaCertificado === null) {
            $fechaCertificado = Carbon::now(config('app.timezone', 'America/Bogota'));
        }

        $fechaFormato = $fechaCertificado->format('Ymd');

        // Buscar el último consecutivo del día
        $ultimoConsecutivo = CertificadoConvenioRecord::where('consecutivo', 'like', $fechaFormato.'%')
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

        return $fechaFormato.$numeroFormateado;
    }

    /**
     * Obtiene la ruta de la plantilla según el tipo de certificado
     *
     * @param  bool  $esParaBancolombia  Indica si es para certificado de Bancolombia
     * @param  bool  $esParaSubsidioVivienda  Indica si es para certificado de subsidio de vivienda
     * @param  bool  $esParaSubsidioDesempleo  Indica si es para certificado de subsidio de desempleo
     * @param  bool  $esConActividades  Indica si es para certificado con actividades
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
     * Elimina campos legacy de Word (MERGEFIELD, IF, fldSimple) del documento generado.
     * PhpWord TemplateProcessor solo reemplaza placeholders ${...}; los campos legacy
     * quedan con valores en caché que ensucian el PDF final.
     */
    public function limpiarCamposLegacyWord(string $docxPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($docxPath) !== true) {
            throw new \RuntimeException("No se pudo abrir el documento Word: {$docxPath}");
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();

            throw new \RuntimeException('No se encontró word/document.xml en el documento Word');
        }

        $cleanedXml = $this->stripLegacyWordFieldsFromDocumentXml($documentXml);
        $cleanedXml = $this->compactDocumentXml($cleanedXml);
        $cleanedXml = $this->adjustSignatureBlockLayout($cleanedXml);
        $cleanedXml = $this->normalizarTipografiaDocumentXml($cleanedXml);

        $zip->addFromString('word/document.xml', $cleanedXml);

        $stylesXml = $zip->getFromName('word/styles.xml');
        if ($stylesXml !== false) {
            $zip->addFromString('word/styles.xml', $this->normalizarTipografiaDocumentXml($stylesXml));
        }

        $zip->close();
    }

    /**
     * @return string XML limpio de word/document.xml
     */
    private function stripLegacyWordFieldsFromDocumentXml(string $documentXml): string
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($documentXml);

        $wordNamespace = self::WORD_NAMESPACE;
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', $wordNamespace);

        $fldSimpleNodes = $xpath->query('//w:fldSimple');
        if ($fldSimpleNodes !== false) {
            $nodesToRemove = [];
            foreach ($fldSimpleNodes as $node) {
                $nodesToRemove[] = $node;
            }
            foreach ($nodesToRemove as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $runs = $xpath->query('//w:r');
        if ($runs === false) {
            return $dom->saveXML() ?: $documentXml;
        }

        $depth = 0;
        $runsToRemove = [];

        foreach ($runs as $run) {
            $shouldRemove = $depth > 0;

            $fldChars = $xpath->query('.//w:fldChar', $run);
            if ($fldChars !== false) {
                foreach ($fldChars as $fldChar) {
                    $shouldRemove = true;
                    $fldCharType = $fldChar->getAttributeNS($wordNamespace, 'fldCharType');

                    if ($fldCharType === 'begin') {
                        $depth++;
                    } elseif ($fldCharType === 'end') {
                        $depth = max(0, $depth - 1);
                    }
                }
            }

            $instrTexts = $xpath->query('.//w:instrText', $run);
            if ($instrTexts !== false && $instrTexts->length > 0) {
                $shouldRemove = true;
            }

            if ($shouldRemove) {
                $runsToRemove[] = $run;
            }
        }

        foreach ($runsToRemove as $run) {
            $run->parentNode?->removeChild($run);
        }

        return $dom->saveXML() ?: $documentXml;
    }

    /**
     * Compacta el documento eliminando párrafos vacíos legacy y normalizando
     * el espaciado entre párrafos con márgenes moderados y consistentes.
     *
     * @return string XML compactado de word/document.xml
     */
    private function compactDocumentXml(string $documentXml): string
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($documentXml);

        $wordNamespace = self::WORD_NAMESPACE;
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', $wordNamespace);

        $paragraphs = $xpath->query('//w:body/w:p');
        if ($paragraphs !== false) {
            $paragraphsToRemove = [];

            foreach ($paragraphs as $paragraph) {
                if (! $this->paragraphHasVisibleContent($paragraph, $xpath)) {
                    $paragraphsToRemove[] = $paragraph;
                }
            }

            foreach ($paragraphsToRemove as $paragraph) {
                $paragraph->parentNode?->removeChild($paragraph);
            }
        }

        $paragraphs = $xpath->query('//w:body/w:p');
        if ($paragraphs !== false) {
            foreach ($paragraphs as $paragraph) {
                if (! $paragraph instanceof \DOMElement) {
                    continue;
                }

                if (! $this->paragraphHasVisibleContent($paragraph, $xpath)) {
                    continue;
                }

                $text = $this->extractParagraphPlainText($paragraph, $xpath);
                $hasDrawing = $xpath->query('.//w:drawing | .//w:pict | .//w:object', $paragraph)->length > 0;
                $profile = $this->resolveParagraphSpacingProfile($text, $hasDrawing);

                $this->applyParagraphSpacing($paragraph, $dom, $wordNamespace, $profile);
            }
        }

        return $dom->saveXML() ?: $documentXml;
    }

    /**
     * @return array{before: int, after: int}
     */
    private function resolveParagraphSpacingProfile(string $text, bool $hasDrawing): array
    {
        if ($hasDrawing && $text === '') {
            return ['before' => 0, 'after' => 0];
        }

        $normalized = mb_strtoupper(trim($text));

        if (str_contains($normalized, 'CERTIFICADO CONVENIO DE EJECUCION')) {
            return ['before' => 280, 'after' => 280];
        }

        if ($normalized === 'CONVENIOS') {
            return ['before' => 520, 'after' => 80];
        }

        if (str_starts_with($normalized, 'HA PARTICIPADO EN LOS SIGUIENTES')) {
            return ['before' => 0, 'after' => 80];
        }

        if (str_starts_with($text, '❖')) {
            return ['before' => 0, 'after' => 240];
        }

        if ($normalized === 'ATENTAMENTE,' || $normalized === 'ATENTAMENTE') {
            return ['before' => 640, 'after' => 80];
        }

        if ($normalized === 'JORGE IVAN ALVAREZ SOTO') {
            return ['before' => 80, 'after' => 0];
        }

        if ($normalized === 'PRESIDENTE') {
            return ['before' => 0, 'after' => 0];
        }

        if (str_starts_with($normalized, 'CONSECUTIVO') || str_starts_with($normalized, 'REALIZADO POR')) {
            return ['before' => 0, 'after' => 0];
        }

        if (preg_match('/^CALDAS,/i', $text)) {
            return ['before' => 0, 'after' => 160];
        }

        if ($normalized === 'A QUIEN CORRESPONDA.' || $normalized === 'A QUIEN CORRESPONDA' || str_starts_with($normalized, 'SEÑORES')) {
            return ['before' => 0, 'after' => 400];
        }

        if (str_contains($normalized, 'SE EXPIDE POR SOLICITUD')) {
            return ['before' => 240, 'after' => 200];
        }

        if (str_contains($normalized, 'VERIFICACIÓN O CONFIRMACIÓN') || str_contains($normalized, 'VERIFICACION O CONFIRMACION')) {
            return ['before' => 0, 'after' => 400];
        }

        return ['before' => 0, 'after' => 240];
    }

    /**
     * @param  array{before: int, after: int}  $profile
     */
    private function applyParagraphSpacing(\DOMElement $paragraph, \DOMDocument $dom, string $wordNamespace, array $profile): void
    {
        $before = min($profile['before'], self::MAX_PARAGRAPH_SPACING_TWIPS);
        $after = min($profile['after'], self::MAX_PARAGRAPH_SPACING_TWIPS);

        $pPr = null;
        foreach ($paragraph->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'pPr') {
                $pPr = $child;
                break;
            }
        }

        if ($pPr === null) {
            $pPr = $dom->createElementNS($wordNamespace, 'w:pPr');
            $paragraph->insertBefore($pPr, $paragraph->firstChild);
        }

        $spacing = null;
        foreach ($pPr->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'spacing') {
                $spacing = $child;
                break;
            }
        }

        if ($spacing === null) {
            $spacing = $dom->createElementNS($wordNamespace, 'w:spacing');
            $pPr->appendChild($spacing);
        }

        if ($spacing->hasAttributeNS($wordNamespace, 'afterLines')) {
            $spacing->removeAttributeNS($wordNamespace, 'afterLines');
        }

        if ($spacing->hasAttributeNS($wordNamespace, 'beforeLines')) {
            $spacing->removeAttributeNS($wordNamespace, 'beforeLines');
        }

        $spacing->setAttributeNS($wordNamespace, 'before', (string) $before);
        $spacing->setAttributeNS($wordNamespace, 'after', (string) $after);
        $spacing->setAttributeNS($wordNamespace, 'line', '240');
        $spacing->setAttributeNS($wordNamespace, 'lineRule', 'auto');
    }

    private function extractParagraphPlainText(\DOMNode $paragraph, \DOMXPath $xpath): string
    {
        $textNodes = $xpath->query('.//w:t', $paragraph);
        if ($textNodes === false) {
            return '';
        }

        $parts = [];
        foreach ($textNodes as $textNode) {
            $parts[] = $textNode->textContent ?? '';
        }

        return trim(implode('', $parts));
    }

    /**
     * Reubica la imagen de firma junto al nombre del presidente y ajusta alineación vertical.
     *
     * @return string XML ajustado de word/document.xml
     */
    private function adjustSignatureBlockLayout(string $documentXml): string
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($documentXml);

        $wordNamespace = self::WORD_NAMESPACE;
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', $wordNamespace);
        $xpath->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');

        $paragraphs = $xpath->query('//w:body/w:p');
        if ($paragraphs === false) {
            return $documentXml;
        }

        $paragraphList = [];
        $nameParagraph = null;

        foreach ($paragraphs as $paragraph) {
            $paragraphList[] = $paragraph;
            $text = mb_strtoupper($this->extractParagraphPlainText($paragraph, $xpath));

            if ($text === 'JORGE IVAN ALVAREZ SOTO') {
                $nameParagraph = $paragraph;
            }
        }

        if (! $nameParagraph instanceof \DOMElement) {
            return $dom->saveXML() ?: $documentXml;
        }

        $nameIndex = array_search($nameParagraph, $paragraphList, true);

        if ($nameIndex === false) {
            return $dom->saveXML() ?: $documentXml;
        }

        $this->setParagraphAlignment($nameParagraph, $dom, $wordNamespace, 'start');
        $this->applyParagraphSpacing($nameParagraph, $dom, $wordNamespace, ['before' => 80, 'after' => 0]);

        for ($index = $nameIndex + 1; $index < count($paragraphList); $index++) {
            $text = mb_strtoupper($this->extractParagraphPlainText($paragraphList[$index], $xpath));

            if ($text === 'PRESIDENTE') {
                $this->setParagraphAlignment($paragraphList[$index], $dom, $wordNamespace, 'start');
                break;
            }
        }

        if ($xpath->query('.//wp:anchor', $nameParagraph)->length > 0) {
            $this->tuneSignatureAnchor($nameParagraph, $xpath);

            return $dom->saveXML() ?: $documentXml;
        }

        $drawingRun = null;
        $drawingParagraph = null;

        for ($index = $nameIndex - 1; $index >= max(0, $nameIndex - 3); $index--) {
            $candidateParagraph = $paragraphList[$index];
            $runs = $xpath->query('.//w:r[.//wp:anchor]', $candidateParagraph);

            if ($runs !== false && $runs->length > 0) {
                $drawingRun = $runs->item(0);
                $drawingParagraph = $candidateParagraph;
                break;
            }
        }

        if (! $drawingRun instanceof \DOMElement) {
            return $dom->saveXML() ?: $documentXml;
        }

        $insertBefore = $xpath->query('./w:r', $nameParagraph)->item(0);
        if ($insertBefore instanceof \DOMNode) {
            $nameParagraph->insertBefore($drawingRun, $insertBefore);
        } else {
            $nameParagraph->appendChild($drawingRun);
        }

        if ($drawingParagraph instanceof \DOMElement && ! $this->paragraphHasVisibleContent($drawingParagraph, $xpath)) {
            $drawingParagraph->parentNode?->removeChild($drawingParagraph);
        }

        $this->tuneSignatureAnchor($nameParagraph, $xpath);

        return $dom->saveXML() ?: $documentXml;
    }

    private function tuneSignatureAnchor(\DOMElement $paragraph, \DOMXPath $xpath): void
    {
        $posOffsets = $xpath->query('.//wp:positionV/wp:posOffset', $paragraph);

        if ($posOffsets !== false) {
            foreach ($posOffsets as $posOffset) {
                $posOffset->nodeValue = (string) self::SIGNATURE_ANCHOR_V_OFFSET_EMU;
            }
        }
    }

    private function setParagraphAlignment(\DOMElement $paragraph, \DOMDocument $dom, string $wordNamespace, string $alignment): void
    {
        $pPr = null;

        foreach ($paragraph->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'pPr') {
                $pPr = $child;
                break;
            }
        }

        if ($pPr === null) {
            $pPr = $dom->createElementNS($wordNamespace, 'w:pPr');
            $paragraph->insertBefore($pPr, $paragraph->firstChild);
        }

        $jc = null;

        foreach ($pPr->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'jc') {
                $jc = $child;
                break;
            }
        }

        if ($jc === null) {
            $jc = $dom->createElementNS($wordNamespace, 'w:jc');
            $pPr->appendChild($jc);
        }

        $jc->setAttributeNS($wordNamespace, 'val', $alignment);
    }

    /**
     * Fuerza Calibri explícita en lugar de referencias a tema (minorHAnsi),
     * que algunos convertidores PDF no resuelven correctamente.
     *
     * @return string XML con tipografía normalizada
     */
    private function normalizarTipografiaDocumentXml(string $documentXml): string
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($documentXml);

        $wordNamespace = self::WORD_NAMESPACE;
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', $wordNamespace);

        $rFontsNodes = $xpath->query('//w:rFonts');
        if ($rFontsNodes === false) {
            return $documentXml;
        }

        $themeAttributes = ['asciiTheme', 'hAnsiTheme', 'eastAsiaTheme', 'csTheme', 'cstheme'];

        foreach ($rFontsNodes as $rFonts) {
            if (! $rFonts instanceof \DOMElement) {
                continue;
            }

            foreach ($themeAttributes as $attribute) {
                if ($rFonts->hasAttributeNS($wordNamespace, $attribute)) {
                    $rFonts->removeAttributeNS($wordNamespace, $attribute);
                }
            }

            $rFonts->setAttributeNS($wordNamespace, 'ascii', self::CERTIFICATE_BODY_FONT);
            $rFonts->setAttributeNS($wordNamespace, 'hAnsi', self::CERTIFICATE_BODY_FONT);
            $rFonts->setAttributeNS($wordNamespace, 'cs', self::CERTIFICATE_BODY_FONT);
        }

        return $dom->saveXML() ?: $documentXml;
    }

    private function paragraphHasVisibleContent(\DOMNode $paragraph, \DOMXPath $xpath): bool
    {
        $drawings = $xpath->query('.//w:drawing | .//w:pict | .//w:object', $paragraph);
        if ($drawings !== false && $drawings->length > 0) {
            return true;
        }

        $textNodes = $xpath->query('.//w:t', $paragraph);
        if ($textNodes === false) {
            return false;
        }

        foreach ($textNodes as $textNode) {
            if (trim($textNode->textContent ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza un valor (similar al método en AfiliadoService)
     */
    private function normalizeValue($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Normaliza un documento removiendo puntos, espacios y caracteres especiales
     */
    private function normalizeDocumento($value): string
    {
        if ($value === null || $value === '') {
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
     * @param  string  $rutaPDF  Ruta local del archivo PDF
     * @param  string  $documento  Número de documento del afiliado
     * @param  string  $consecutivo  Número consecutivo del certificado
     * @param  Carbon  $fechaCertificado  Fecha de generación
     * @param  array  $afiliadoData  Datos del afiliado
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

            if (! $guardado) {
                throw new \Exception('No se pudo guardar el certificado en el bucket');
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

            throw new \Exception('Error al guardar certificado en almacenamiento: '.$e->getMessage());
        }
    }

    /**
     * Crea un registro del certificado en la base de datos
     *
     * @param  string  $documento  Número de documento del afiliado
     * @param  string  $consecutivo  Número consecutivo del certificado
     * @param  string  $bucketPath  Ruta del archivo en el bucket
     * @param  Carbon  $fechaCertificado  Fecha de generación
     * @param  array  $afiliadoData  Datos del afiliado
     * @param  string|null  $tipoCertificado  Tipo de certificado: 'basico', 'con_actividades', 'dirigido_afp', 'bancolombia', 'subsidio_vivienda', 'subsidio_desempleo', 'otros'
     * @param  bool  $tieneCompensaciones  Indica si el certificado tiene valores de compensaciones
     * @param  string|null  $dirigidoAEntidad  Entidad a la que está dirigido el certificado
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
     * @param  int  $tBasicos  Valor de T. Basicos
     * @param  int  $tAuxilios  Valor de T. Auxilios
     * @param  int  $tIngresos  Valor de T. Ingresos (Total)
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

        $parte1 .= ', para un';

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
     * @param  int  $tBasicos  Valor de T. Basicos
     * @param  int  $tAuxilios  Valor de T. Auxilios (beneficios económicos)
     * @param  int  $tIngresos  Valor de T. Ingresos (Total)
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

        $parte1 .= ', para un';

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
     * @param  int  $numero  Número a convertir
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
                $resultado .= $this->convertirUnidades($millones).' millones';
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
                $resultado .= $this->convertirUnidades($miles).' mil';
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
     * @param  int  $numero  Número entre 0 y 999
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
            27 => 'veintisiete', 28 => 'veintiocho', 29 => 'veintinueve',
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
                        $resultado .= ' y '.$unidades[$unidad];
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
     * @param  array  $data  Datos de la solicitud (payload completo)
     * @param  string|null  $estadoAfiliado  Estado del afiliado ('activo' o 'retirado')
     * @return array Array de mensajes de error (vacío si no hay errores)
     */
    public function validarCertificadoConvenio(array $data, ?string $estadoAfiliado = null): array
    {
        $errors = [];
        $info = $data['infoCertificado'] ?? [];

        // Parsear infoCertificado si viene como string JSON
        if (is_string($info)) {
            $info = json_decode($info, true);
            if (! is_array($info)) {
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
        if (! $fechaIngresoRetiro) {
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
        if ($tieneSubsidio && ! $valorCompensaciones) {
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
        if ($valorCompensaciones && ! $tieneSubsidio && $dirigidoFondoPensiones) {
            $errors[] = 'Valor de compensaciones no puede seleccionarse con Dirigido al Fondo de Pensiones cuando no hay subsidios';
        }

        // 12. Validación: valorCompensaciones sin subsidios no puede estar con dirigidoBancolombia
        if ($valorCompensaciones && ! $tieneSubsidio && $dirigidoBancolombia) {
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
        if ($dirigidoFondoPensiones && $valorCompensaciones && ! $tieneSubsidio) {
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
            if (! $hasActividadesPdf) {
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
     * @param  string|null  $fechaDesde  Fecha desde (formato Y-m-d)
     * @param  string|null  $fechaHasta  Fecha hasta (formato Y-m-d)
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
