<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class AfiliadoService
{
    private const EXCEL_FILE_PATH = 'data/PROSANET_INFORMACION_AFILIADOS.xlsx';

    /**
     * Bumped when the payload stored in auth cache changes (e.g. hospital enrichment) so stale entries are not reused.
     */
    private const AUTH_CACHE_KEY_FORMAT = 'afiliado:auth:v2:%s:%s:%s';

    private const STORAGE_PRIMARY_DISK = 'prosalud-private';

    private const STORAGE_FALLBACK_DISK = 'local';

    private const SHEET_INFORMACION_GENERAL = 'INFORMACIÓN GENERAL';

    private const SHEET_CONVENIOS = 'CONVENIOS';

    private const SHEET_BENEFICIARIOS = 'BENEFICIARIOS';

    // Column indexes for INFORMACIÓN GENERAL sheet
    private const COL_TIPO_DOCUMENTO = 0;

    private const COL_DOCUMENTO = 1;

    private const COL_NOMBRES = 2;

    private const COL_APELLIDOS = 3;

    private const COL_ESTADO = 4;

    private const COL_FECHA_EXPEDICION = 5;

    private const COL_FECHA_NACIMIENTO = 6;

    private const COL_LUGAR_NACIMIENTO = 7;

    private const COL_SEXO = 8;

    private const COL_RH = 9;

    private const COL_FECHA_INGRESO = 10;

    private const COL_ESTADO_CIVIL = 11;

    private const COL_CARNET = 12;

    private const COL_DIRECCION = 13;

    private const COL_DEPARTAMENTO = 14;

    private const COL_MUNICIPIO = 15;

    private const COL_TELEFONO = 16;

    private const COL_CELULAR = 17;

    private const COL_CORREO_PERSONAL = 18;

    private const COL_ARCHIVO_LIQUIDADO = 19;

    private const COL_FECHA_LIQUIDACION = 20;

    private const COL_TALLA_UNIFORME = 21;

    private const COL_TALLA_CALZADO = 22;

    private const COL_NIVEL_EDUCACION = 23;

    private const COL_OTROS_ESTUDIOS = 24;

    private const COL_NUMERO_CUENTA = 26;

    private const COL_TIPO_CUENTA = 27;

    private const COL_BANCO = 28;

    private const COL_FECHA_RETHUS = 29;

    private const COL_COMPENSACION_BASICA = 30;

    private const COL_TIPO_AFILIACION = 31;

    private const COL_EPS = 32;

    private const COL_AFP = 33;

    private const COL_ARL = 34;

    private const COL_CAJA_COMPENSACION = 35;

    private const COL_NIVEL_RIESGO = 36;

    private const COL_FECHA_VENCIMIENTO_POLIZA = 37;

    private const COL_EMISOR_POLIZA = 38;

    private const COL_DETALLES = 39;

    // Column indexes for CONVENIOS sheet
    private const COL_CONV_DOCUMENTO_AFILIADO = 0;

    private const COL_CONV_NOMBRE_AFILIADO = 1;

    private const COL_CONV_APELLIDOS_AFILIADO = 2;

    private const COL_CONV_CLIENTE = 3;

    private const COL_CONV_SUCURSAL = 4;

    private const COL_CONV_PROCESO = 5;

    private const COL_CONV_ESTADO = 6;

    private const COL_CONV_FECHA_INGRESO = 7;

    private const COL_CONV_FECHA_FIN = 8;

    private const COL_CONV_NOTAS = 9;

    // Column indexes for BENEFICIARIOS sheet
    // Order: Documento afiliado, Tipo documento, Documento, Nombres, Apellidos, Fecha nacimiento, Sexo, Notas (parentesco)
    private const COL_BEN_DOCUMENTO_AFILIADO = 0;

    private const COL_BEN_TIPO_DOCUMENTO = 1;

    private const COL_BEN_DOCUMENTO = 2;

    private const COL_BEN_NOMBRES = 3;

    private const COL_BEN_APELLIDOS = 4;

    private const COL_BEN_FECHA_NACIMIENTO = 5;

    private const COL_BEN_SEXO = 6;

    private const COL_BEN_NOTAS_PARENTESCO = 7;

    // Mapeo de nombres de columna esperados (normalizados)
    // Permite múltiples variaciones de nombres para hacer matching flexible
    private const COLUMN_NAME_MAPPINGS = [
        'tipo_documento' => ['tipo docume', 'tipo documento', 'tipo doc'],
        'documento' => ['documento'],
        'nombres' => ['nombres'],
        'apellidos' => ['apellidos'],
        'estado' => ['estado', 'status', 'estado del afiliado', 'estado afiliado'],
        'hospital' => ['hospital', 'sede', 'centro de trabajo', 'centro trabajo', 'lugar de trabajo'],
        'fecha_expedicion' => ['fecha exped', 'fecha expedicion'],
        'fecha_nacimiento' => ['fecha nacim', 'fecha nacimiento'],
        'lugar_nacimiento' => ['lugar nacim', 'lugar nacimiento'],
        'sexo' => ['sexo'],
        'rh' => ['rh'],
        'fecha_ingreso' => ['fecha de ing', 'fecha ingreso', 'fecha ing'],
        'estado_civil' => ['estado civil'],
        'carnet' => ['carnet'],
        'direccion' => ['direccion'],
        'departamento' => ['departamen', 'departamento'],
        'municipio' => ['municipio'],
        'telefono' => ['telefono'],
        'celular' => ['celular'],
        'correo_personal' => ['correo perso', 'correo personal', 'correo'],
        // Nombres exactos del Excel: "Contacto de emergencia" (completo)
        'contacto_emergencia' => ['contacto de emergencia', 'contacto de emer', 'contacto emergencia', 'contacto emer', 'contacto de emergen'],
        'archivo_liquidado' => ['archivo liqui', 'archivo liquidado'],
        'fecha_liquidacion' => ['fecha liquid', 'fecha liquidacion'],
        // Nombres exactos del Excel: "Talla de Uniforme", "Talla de Calzado"
        'talla_uniforme' => ['talla de uniforme', 'talla de unif', 'talla uniforme', 'talla unif'],
        'talla_calzado' => ['talla de calzado', 'talla de calz', 'talla calzado', 'talla calz'],
        // Nombre exacto del Excel: "Nivel de educacion" (sin tilde, completo)
        'nivel_educacion' => ['nivel de educacion', 'nivel de edu', 'nivel educacion', 'nivel edu', 'nivel de educ'],
        'otros_estudios' => ['otros estudi', 'otros estudios'],
        'numero_cuenta' => ['numero cue', 'numero cuenta'],
        // Nombre exacto del Excel: "Tipo de cuenta" (completo con "de")
        'tipo_cuenta' => ['tipo de cuenta', 'tipo de cuen', 'tipo cuenta', 'tipo cuen'],
        'banco' => ['banco'],
        'fecha_rethus' => ['fecha rethu', 'fecha rethus'],
        'compensacion_basica' => ['compensaci', 'compensacion', 'compensacion basica'],
        'tipo_afiliacion' => ['tipo de afili', 'tipo afiliacion', 'tipo afili'],
        'eps' => ['e.p.s', 'eps'],
        'afp' => ['a.f.p', 'afp'],
        'arl' => ['a.r.l', 'arl'],
        'caja_compensacion' => ['caja de com', 'caja compensacion', 'caja com'],
        'nivel_riesgo' => ['nivel de ries', 'nivel riesgo', 'nivel ries'],
        'fecha_vencimiento_poliza' => ['fecha vencim', 'fecha vencimiento', 'fecha vencimiento poliza'],
        'emisor_poliza' => ['emisor poliz', 'emisor poliza'],
        'detalles' => ['detalles'],
    ];

    /**
     * Authenticate and get affiliate information (optimized - reads only necessary rows).
     */
    public function authenticateAndGetAfiliado(
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion,
    ): ?array {
        // Log de entrada al método
        Log::info('[AFILIADO SERVICE] Iniciando autenticación de afiliado', [
            'documento' => $documento,
            'tipo_documento' => $tipoDocumento,
            'fecha_expedicion' => $fechaExpedicion,
        ]);

        // Normalizar valores para la clave de caché
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        $cacheKey = sprintf(
            self::AUTH_CACHE_KEY_FORMAT,
            md5($normalizedTipoDocumento ?? ''),
            md5($normalizedDocumento ?? ''),
            md5($normalizedFechaExpedicion ?? '')
        );

        Log::debug('[AFILIADO SERVICE] Clave de caché generada', [
            'cache_key' => $cacheKey,
            'documento' => $documento,
        ]);

        // Verificar si existe en caché
        $cachedData = Cache::tags(['afiliados'])->get($cacheKey);
        if ($cachedData !== null) {
            Log::info('[CACHE HIT] Afiliado obtenido desde caché', [
                'cache_key' => $cacheKey,
                'documento' => $documento,
                'tipo_documento' => $tipoDocumento,
            ]);

            return $cachedData;
        }

        Log::info('[CACHE MISS] Consultando afiliado desde Excel', [
            'cache_key' => $cacheKey,
            'documento' => $documento,
            'tipo_documento' => $tipoDocumento,
        ]);

        $result = Cache::tags(['afiliados'])->remember($cacheKey, now()->addHours(24), function () use ($tipoDocumento, $documento, $fechaExpedicion) {
            return $this->withExcelFile(function (string $excelPath, string $disk) use ($tipoDocumento, $documento, $fechaExpedicion) {
                $originalMemoryLimit = ini_get('memory_limit');
                $originalMaxExecutionTime = ini_get('max_execution_time');

                try {
                    // Aumentar memoria temporalmente
                    ini_set('memory_limit', '512M');
                    set_time_limit(60);

                    // Usar reader optimizado
                    $reader = IOFactory::createReader('Xlsx');

                    // Leer solo datos, no fórmulas ni formato (ahorra memoria)
                    if (method_exists($reader, 'setReadDataOnly')) {
                        $reader->setReadDataOnly(true);
                    }

                    // Cargar solo las hojas necesarias
                    if (method_exists($reader, 'setLoadSheetsOnly')) {
                        $reader->setLoadSheetsOnly([
                            self::SHEET_INFORMACION_GENERAL,
                            self::SHEET_CONVENIOS,
                        ]);
                    }

                    $spreadsheet = $reader->load($excelPath);

                    $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
                    if (! $informacionSheet) {
                        Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');

                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);

                        return null;
                    }

                    $afiliadoRowResult = $this->findAfiliadoRowOptimized(
                        $informacionSheet,
                        $tipoDocumento,
                        $documento,
                        $fechaExpedicion
                    );

                    if ($afiliadoRowResult === null) {
                        Log::info('Afiliado no encontrado', [
                            'tipo_documento' => $tipoDocumento,
                            'documento' => $documento,
                            'fecha_expedicion' => $fechaExpedicion,
                            'normalized' => [
                                'tipo_documento' => $this->normalizeValue($tipoDocumento),
                                'documento' => $this->normalizeValue($documento),
                                'fecha_expedicion' => $this->normalizeDate($fechaExpedicion),
                            ],
                        ]);

                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);

                        return null;
                    }

                    $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRowResult['data'], $afiliadoRowResult['mapping']);

                    $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
                    $conveniosFull = $conveniosSheet
                        ? $this->getConveniosByDocumentoOptimized($conveniosSheet, $documento)
                        : [];

                    // Liberar memoria explícitamente
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return $this->filterAfiliadoResponse($afiliadoFull, $conveniosFull);
                } catch (SpreadsheetException $e) {
                    Log::error('Error al procesar archivo Excel de afiliados', [
                        'error' => $e->getMessage(),
                        'file_path' => self::EXCEL_FILE_PATH,
                        'disk' => $disk,
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return null;
                } catch (\Throwable $e) {
                    Log::error('Error inesperado al leer archivo Excel de afiliados', [
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'disk' => $disk,
                        'file_path' => self::EXCEL_FILE_PATH,
                        'trace' => $e->getTraceAsString(),
                    ]);

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
            }, null);
        });

        // Log cuando se guarda en caché (solo si se obtuvo resultado)
        if ($result !== null) {
            Log::info('[CACHE STORED] Afiliado guardado en caché', [
                'cache_key' => $cacheKey,
                'documento' => $documento,
                'tipo_documento' => $tipoDocumento,
                'ttl_hours' => 24,
            ]);
        }

        return $result;
    }

    /**
     * Authenticate and get affiliate with detailed failure reason for the frontend.
     * Returns array with:
     * - 'status': 'success' | 'affiliate_not_found' | 'affiliate_data_mismatch'
     * - 'afiliado': array|null (only when status is 'success')
     * So the frontend can tell when the document number does not exist vs when it exists but tipo/fecha are wrong.
     */
    public function authenticateAndGetAfiliadoDetailed(
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion,
    ): array {
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);
        $cacheKey = sprintf(
            self::AUTH_CACHE_KEY_FORMAT,
            md5($normalizedTipoDocumento ?? ''),
            md5($normalizedDocumento ?? ''),
            md5($normalizedFechaExpedicion ?? '')
        );

        $cachedData = Cache::tags(['afiliados'])->get($cacheKey);
        if ($cachedData !== null) {
            return ['status' => 'success', 'afiliado' => $cachedData];
        }

        $result = $this->withExcelFile(function (string $excelPath, string $disk) use ($tipoDocumento, $documento, $fechaExpedicion, $cacheKey) {
            $originalMemoryLimit = ini_get('memory_limit');
            $originalMaxExecutionTime = ini_get('max_execution_time');
            try {
                ini_set('memory_limit', '512M');
                set_time_limit(60);
                $reader = IOFactory::createReader('Xlsx');
                if (method_exists($reader, 'setReadDataOnly')) {
                    $reader->setReadDataOnly(true);
                }
                if (method_exists($reader, 'setLoadSheetsOnly')) {
                    $reader->setLoadSheetsOnly([
                        self::SHEET_INFORMACION_GENERAL,
                        self::SHEET_CONVENIOS,
                    ]);
                }
                $spreadsheet = $reader->load($excelPath);
                $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
                if (! $informacionSheet) {
                    Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return ['status' => 'affiliate_not_found', 'afiliado' => null];
                }

                $matchResult = $this->findAfiliadoRowWithReason(
                    $informacionSheet,
                    $tipoDocumento,
                    $documento,
                    $fechaExpedicion
                );

                if ($matchResult['result'] === 'not_found') {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return ['status' => 'affiliate_not_found', 'afiliado' => null];
                }
                if ($matchResult['result'] === 'data_mismatch') {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return ['status' => 'affiliate_data_mismatch', 'afiliado' => null];
                }

                $afiliadoRowResult = ['data' => $matchResult['data'], 'mapping' => $matchResult['mapping']];
                $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRowResult['data'], $afiliadoRowResult['mapping']);
                $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
                $conveniosFull = $conveniosSheet
                    ? $this->getConveniosByDocumentoOptimized($conveniosSheet, $documento)
                    : [];
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
                $afiliadoData = $this->filterAfiliadoResponse($afiliadoFull, $conveniosFull);
                Cache::tags(['afiliados'])->put($cacheKey, $afiliadoData, now()->addHours(24));

                return ['status' => 'success', 'afiliado' => $afiliadoData];
            } catch (\Throwable $e) {
                Log::error('Error en authenticateAndGetAfiliadoDetailed', [
                    'error' => $e->getMessage(),
                    'documento' => $documento,
                    'trace' => $e->getTraceAsString(),
                ]);

                return ['status' => 'affiliate_not_found', 'afiliado' => null];
            } finally {
                if ($originalMemoryLimit !== false && $originalMemoryLimit !== null) {
                    ini_set('memory_limit', (string) $originalMemoryLimit);
                }
                if ($originalMaxExecutionTime !== false && $originalMaxExecutionTime !== null) {
                    set_time_limit((int) $originalMaxExecutionTime);
                }
            }
        }, null);

        return $result ?? ['status' => 'affiliate_not_found', 'afiliado' => null];
    }

    /**
     * Authenticate and get affiliate information (reads entire file - kept for future use)
     * This method loads the entire Excel file into memory.
     * Use authenticateAndGetAfiliado() for better performance with large files.
     */
    public function authenticateAndGetAfiliadoFull(
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion,
    ): ?array {
        return $this->withExcelFile(function (string $excelPath, string $disk) use ($tipoDocumento, $documento, $fechaExpedicion) {
            $originalMemoryLimit = ini_get('memory_limit');
            $originalMaxExecutionTime = ini_get('max_execution_time');

            try {
                ini_set('memory_limit', '512M');
                set_time_limit(60);

                // Usar reader optimizado
                $reader = IOFactory::createReader('Xlsx');

                // Leer solo datos, no fórmulas ni formato (ahorra memoria)
                if (method_exists($reader, 'setReadDataOnly')) {
                    $reader->setReadDataOnly(true);
                }

                // Cargar solo las hojas necesarias
                if (method_exists($reader, 'setLoadSheetsOnly')) {
                    $reader->setLoadSheetsOnly([
                        self::SHEET_INFORMACION_GENERAL,
                        self::SHEET_CONVENIOS,
                    ]);
                }

                $spreadsheet = $reader->load($excelPath);

                $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
                if (! $informacionSheet) {
                    Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $informacionData = $informacionSheet->toArray();

                $afiliadoRowIndex = $this->findAfiliadoRow(
                    $informacionData,
                    $tipoDocumento,
                    $documento,
                    $fechaExpedicion
                );

                if ($afiliadoRowIndex === null) {
                    Log::info('Afiliado no encontrado', [
                        'tipo_documento' => $tipoDocumento,
                        'documento' => $documento,
                        'fecha_expedicion' => $fechaExpedicion,
                    ]);

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $afiliadoRow = $informacionData[$afiliadoRowIndex];
                // Build column mapping from header row
                $columnMapping = $this->buildColumnMapping($informacionSheet);
                $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRow, $columnMapping);

                $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
                $conveniosFull = [];
                if ($conveniosSheet) {
                    $conveniosData = $conveniosSheet->toArray();
                    $conveniosFull = $this->getConveniosByDocumento($conveniosData, $documento);
                }

                // Liberar memoria explícitamente
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                return $this->filterAfiliadoResponse($afiliadoFull, $conveniosFull);
            } catch (SpreadsheetException $e) {
                Log::error('Error al procesar archivo Excel de afiliados', [
                    'error' => $e->getMessage(),
                    'file_path' => self::EXCEL_FILE_PATH,
                    'disk' => $disk,
                    'trace' => $e->getTraceAsString(),
                ]);

                return null;
            } catch (\Throwable $e) {
                Log::error('Error inesperado al leer archivo Excel de afiliados', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'disk' => $disk,
                    'file_path' => self::EXCEL_FILE_PATH,
                    'trace' => $e->getTraceAsString(),
                ]);

                return null;
            } finally {
                if ($originalMemoryLimit !== false && $originalMemoryLimit !== null) {
                    ini_set('memory_limit', (string) $originalMemoryLimit);
                }
                if ($originalMaxExecutionTime !== false && $originalMaxExecutionTime !== null) {
                    set_time_limit((int) $originalMaxExecutionTime);
                }
            }
        }, null);
    }

    /**
     * Validate credentials and get email for OTP (lightweight validation)
     * Returns array with email and nombre if valid, null otherwise.
     */
    public function validateCredentialsAndGetEmail(
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion,
    ): ?array {
        return $this->withExcelFile(function (string $excelPath, string $disk) use ($tipoDocumento, $documento, $fechaExpedicion) {
            $originalMemoryLimit = ini_get('memory_limit');
            $originalMaxExecutionTime = ini_get('max_execution_time');

            try {
                // Aumentar memoria temporalmente
                ini_set('memory_limit', '512M');
                set_time_limit(60);

                // Usar reader optimizado
                $reader = IOFactory::createReader('Xlsx');

                // Leer solo datos, no fórmulas ni formato (ahorra memoria)
                if (method_exists($reader, 'setReadDataOnly')) {
                    $reader->setReadDataOnly(true);
                }

                // Cargar solo la hoja necesaria
                if (method_exists($reader, 'setLoadSheetsOnly')) {
                    $reader->setLoadSheetsOnly([self::SHEET_INFORMACION_GENERAL]);
                }

                $spreadsheet = $reader->load($excelPath);
                $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);

                if (! $informacionSheet) {
                    Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $afiliadoRowResult = $this->findAfiliadoRowOptimized(
                    $informacionSheet,
                    $tipoDocumento,
                    $documento,
                    $fechaExpedicion
                );

                if ($afiliadoRowResult === null) {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $correo = $this->normalizeValue($this->getValueByColumnName(
                    $afiliadoRowResult['data'],
                    $afiliadoRowResult['mapping'],
                    'correo_personal'
                ));
                $nombres = $this->normalizeValue($this->getValueByColumnName(
                    $afiliadoRowResult['data'],
                    $afiliadoRowResult['mapping'],
                    'nombres'
                ));
                $apellidos = $this->normalizeValue($this->getValueByColumnName(
                    $afiliadoRowResult['data'],
                    $afiliadoRowResult['mapping'],
                    'apellidos'
                ));

                // Liberar memoria explícitamente
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                if (empty($correo)) {
                    Log::warning('Afiliado encontrado pero sin correo electrónico', [
                        'documento' => $documento,
                    ]);

                    return null;
                }

                return [
                    'correo' => $correo,
                    'nombre' => trim(($nombres ?? '').' '.($apellidos ?? '')),
                    'documento' => $this->normalizeValue($documento),
                ];
            } catch (\Throwable $e) {
                Log::error('Error al validar credenciales de afiliado', [
                    'error' => $e->getMessage(),
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'disk' => $disk,
                    'file_path' => self::EXCEL_FILE_PATH,
                    'trace' => $e->getTraceAsString(),
                ]);

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
        }, null);
    }

    /**
     * Get complete affiliate information including all details and convenios
     * This method returns full information without filtering.
     * Uses cache for improved performance (24 hours TTL).
     */
    public function getCompleteAfiliadoInfo(
        string $tipoDocumento,
        string $documento,
        ?string $fechaExpedicion = null,
    ): ?array {
        // Log de entrada al método
        Log::info('[AFILIADO SERVICE] Iniciando obtención de información completa de afiliado', [
            'documento' => $documento,
            'tipo_documento' => $tipoDocumento,
            'fecha_expedicion' => $fechaExpedicion,
        ]);

        // Normalizar valores para la clave de caché
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        $cacheKey = sprintf(
            'afiliado:complete:%s:%s:%s',
            md5($normalizedTipoDocumento ?? ''),
            md5($normalizedDocumento ?? ''),
            md5($normalizedFechaExpedicion ?? '')
        );

        Log::debug('[AFILIADO SERVICE] Clave de caché generada para información completa', [
            'cache_key' => $cacheKey,
            'documento' => $documento,
        ]);

        // Verificar si existe en caché
        $cachedData = Cache::tags(['afiliados'])->get($cacheKey);
        if ($cachedData !== null) {
            Log::info('[CACHE HIT] Información completa de afiliado obtenida desde caché', [
                'cache_key' => $cacheKey,
                'documento' => $documento,
                'tipo_documento' => $tipoDocumento,
            ]);

            return $cachedData;
        }

        Log::info('[CACHE MISS] Consultando información completa de afiliado desde Excel', [
            'cache_key' => $cacheKey,
            'documento' => $documento,
            'tipo_documento' => $tipoDocumento,
        ]);

        $result = Cache::tags(['afiliados'])->remember($cacheKey, now()->addHours(24), function () use ($tipoDocumento, $documento, $fechaExpedicion) {
            return $this->withExcelFile(function (string $excelPath, string $disk) use ($tipoDocumento, $documento, $fechaExpedicion) {
                $originalMemoryLimit = ini_get('memory_limit');
                $originalMaxExecutionTime = ini_get('max_execution_time');

                try {
                    // Aumentar memoria temporalmente
                    ini_set('memory_limit', '512M');
                    set_time_limit(60);

                    // Usar reader optimizado
                    $reader = IOFactory::createReader('Xlsx');

                    // Leer solo datos, no fórmulas ni formato (ahorra memoria)
                    if (method_exists($reader, 'setReadDataOnly')) {
                        $reader->setReadDataOnly(true);
                    }

                    // Cargar solo las hojas necesarias
                    if (method_exists($reader, 'setLoadSheetsOnly')) {
                        $reader->setLoadSheetsOnly([
                            self::SHEET_INFORMACION_GENERAL,
                            self::SHEET_CONVENIOS,
                            self::SHEET_BENEFICIARIOS,
                        ]);
                    }

                    $spreadsheet = $reader->load($excelPath);
                    $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);

                    if (! $informacionSheet) {
                        Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');

                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);

                        return null;
                    }

                    $afiliadoRowResult = $this->findAfiliadoRowOptimized(
                        $informacionSheet,
                        $tipoDocumento,
                        $documento,
                        $fechaExpedicion
                    );

                    if ($afiliadoRowResult === null) {
                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);

                        return null;
                    }

                    $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRowResult['data'], $afiliadoRowResult['mapping']);

                    $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
                    $conveniosFull = $conveniosSheet
                        ? $this->getConveniosByDocumentoOptimized($conveniosSheet, $documento)
                        : [];

                    $beneficiarios = $this->getBeneficiariosByDocumento($spreadsheet, $documento);

                    // Liberar memoria explícitamente
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    $afiliadoFiltered = $this->filterAfiliadoCompleteInfo($afiliadoFull);
                    $beneficiariosFiltered = $this->filterBeneficiariosInfo($beneficiarios);

                    // Seleccionar el convenio más reciente (activo o el más reciente si no hay activos)
                    // Prioridad: Convenios activos con fecha_fin vacía/null > activos con fecha_fin > inactivos
                    $conveniosFiltered = $this->selectMostRecentConvenio($conveniosFull);

                    Log::debug('[AFILIADO SERVICE] Convenios filtrados en getCompleteAfiliadoInfo', [
                        'total_convenios' => count($conveniosFull),
                        'convenios_filtrados' => count($conveniosFiltered),
                        'documento' => $documento,
                    ]);

                    return [
                        'afiliado' => $afiliadoFiltered,
                        'convenios' => $conveniosFiltered,
                        'beneficiarios' => $beneficiariosFiltered,
                    ];
                } catch (\Throwable $e) {
                    Log::error('Error al obtener información completa de afiliado', [
                        'error' => $e->getMessage(),
                        'tipo_documento' => $tipoDocumento,
                        'documento' => $documento,
                        'disk' => $disk,
                        'file_path' => self::EXCEL_FILE_PATH,
                        'trace' => $e->getTraceAsString(),
                    ]);

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
            }, null);
        });

        // Log cuando se guarda en caché (solo si se obtuvo resultado)
        if ($result !== null) {
            Log::info('[CACHE STORED] Información completa de afiliado guardada en caché', [
                'cache_key' => $cacheKey,
                'documento' => $documento,
                'tipo_documento' => $tipoDocumento,
                'ttl_hours' => 24,
            ]);
        }

        return $result;
    }

    /**
     * Get affiliate information by document number only (without authentication).
     * Used for bulk operations where we only have the document number.
     *
     * @param  string  $documento  Document number
     * @return array|null Affiliate information with email, name, etc. or null if not found
     */
    public function getAfiliadoByDocumentoOnly(string $documento): ?array
    {
        $normalizedDocumento = $this->normalizeValue($documento);
        // v3: hospital = convenio activo/reciente (cliente); v2 tenía estado pero hospital desde columna
        $cacheKey = sprintf('afiliado:doc_only:v3:%s', md5($normalizedDocumento));

        // Check cache first
        $cachedData = Cache::tags(['afiliados'])->get($cacheKey);
        if ($cachedData !== null) {
            return $cachedData;
        }

        $result = $this->withExcelFile(function (string $excelPath, string $disk) use ($normalizedDocumento) {
            try {
                $reader = IOFactory::createReader('Xlsx');
                if (method_exists($reader, 'setReadDataOnly')) {
                    $reader->setReadDataOnly(true);
                }

                if (method_exists($reader, 'setLoadSheetsOnly')) {
                    $reader->setLoadSheetsOnly([
                        self::SHEET_INFORMACION_GENERAL,
                        self::SHEET_CONVENIOS,
                    ]);
                }

                $spreadsheet = $reader->load($excelPath);
                $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);

                if (! $informacionSheet) {
                    Log::error('Pestaña INFORMACIÓN GENERAL no encontrada para búsqueda por documento');
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $afiliadoRowResult = $this->findAfiliadoRowByDocumentoOnly($informacionSheet, $normalizedDocumento);

                if ($afiliadoRowResult === null) {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRowResult['data'], $afiliadoRowResult['mapping']);

                // Hospital = convenio activo o más reciente (cliente del convenio), igual que en otros servicios
                $hospital = null;
                $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
                if ($conveniosSheet) {
                    $convenios = $this->getConveniosByDocumentoOptimized($conveniosSheet, $normalizedDocumento);
                    $selected = $this->selectMostRecentConvenio($convenios);
                    if (! empty($selected)) {
                        $cliente = $selected[0]['cliente'] ?? '';
                        $hospital = trim((string) $cliente) !== '' ? trim($cliente) : 'SIN ASIGNAR';
                    }
                }

                // Liberar memoria
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                // Return fields for email sending and wellness lookup (estado, hospital = convenio activo/reciente)
                return [
                    'documento' => $afiliadoFull['documento'] ?? null,
                    'tipo_documento' => $afiliadoFull['tipo_documento'] ?? null,
                    'nombres' => $afiliadoFull['nombres'] ?? '',
                    'apellidos' => $afiliadoFull['apellidos'] ?? '',
                    'correo_personal' => $afiliadoFull['correo_personal'] ?? null,
                    'nombre_completo' => trim(($afiliadoFull['nombres'] ?? '').' '.($afiliadoFull['apellidos'] ?? '')),
                    'estado' => $afiliadoFull['estado'] ?? null,
                    'hospital' => $hospital,
                ];
            } catch (\Throwable $e) {
                Log::error('Error al buscar afiliado por documento', [
                    'error' => $e->getMessage(),
                    'documento' => $normalizedDocumento,
                    'disk' => $disk,
                    'file_path' => self::EXCEL_FILE_PATH,
                    'trace' => $e->getTraceAsString(),
                ]);

                return null;
            }
        }, null);

        // Cache the result for 1 hour
        if ($result !== null) {
            Cache::tags(['afiliados'])->put($cacheKey, $result, now()->addHour());
        }

        return $result;
    }

    /**
     * Check if the Excel file exists and is readable.
     */
    public function isFileAvailable(): bool
    {
        if (Storage::disk(self::STORAGE_PRIMARY_DISK)->exists(self::EXCEL_FILE_PATH)) {
            return true;
        }

        if (Storage::disk(self::STORAGE_FALLBACK_DISK)->exists(self::EXCEL_FILE_PATH)) {
            return true;
        }

        return false;
    }

    /**
     * Get a cached list of affiliates with basic information for dotación/EPP management.
     */
    public function getAllAfiliadosBasic(): array
    {
        return Cache::tags(['afiliados'])->remember('afiliado_service.all_basic', now()->addMinutes(30), function () {
            if (! $this->isFileAvailable()) {
                Log::error('AfiliadoService: no existe archivo de afiliados PROSANET en almacenamiento — listado básico y dotación vacíos hasta cargar data/PROSANET_INFORMACION_AFILIADOS.xlsx', [
                    'dotacion_epp' => true,
                    'afiliado_basic_list' => true,
                    'cause' => 'excel_file_missing',
                    'expected_path' => self::EXCEL_FILE_PATH,
                ]);

                return [];
            }

            return $this->withExcelFile(function (string $excelPath, string $disk) {
                try {
                    $reader = IOFactory::createReader('Xlsx');
                    if (method_exists($reader, 'setReadDataOnly')) {
                        $reader->setReadDataOnly(true);
                    }

                    if (method_exists($reader, 'setLoadSheetsOnly')) {
                        $reader->setLoadSheetsOnly([
                            self::SHEET_INFORMACION_GENERAL,
                            self::SHEET_CONVENIOS,
                        ]);
                    }

                    if (method_exists($reader, 'setReadFilter')) {
                        $reader->setReadFilter(new class implements IReadFilter
                        {
                            private const ALLOWED_COLUMNS = [
                                'A', 'B', 'C', 'D', 'E',
                                'F', 'G', 'H', 'I', 'J',
                            ];

                            public function readCell($column, $row, $worksheetName = ''): bool
                            {
                                if ($row === 1) {
                                    return true;
                                }

                                return in_array($column, self::ALLOWED_COLUMNS, true);
                            }
                        });
                    }

                    $spreadsheet = $reader->load($excelPath);

                    $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
                    if (! $informacionSheet) {
                        Log::error('Pestaña INFORMACIÓN GENERAL no encontrada para listado general (dotación/EPP no tendrá afiliados hasta corregir Excel)', [
                            'dotacion_epp' => true,
                            'afiliado_basic_list' => true,
                            'expected_sheet' => self::SHEET_INFORMACION_GENERAL,
                            'disk' => $disk,
                        ]);

                        return [];
                    }

                    $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
                    $conveniosMap = $conveniosSheet ? $this->buildConveniosSummaryMap($conveniosSheet) : [];

                    $highestRow = $informacionSheet->getHighestRow();
                    $affiliates = [];
                    $skippedRowsMissingTipoOrDocumento = 0;

                    for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
                        $tipoDocumento = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_TIPO_DOCUMENTO + 1).$rowIndex)
                            )
                        );
                        $documento = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_DOCUMENTO + 1).$rowIndex)
                            )
                        );

                        if (empty($tipoDocumento) || empty($documento)) {
                            $skippedRowsMissingTipoOrDocumento++;

                            continue;
                        }

                        $nombres = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_NOMBRES + 1).$rowIndex)
                            )
                        );
                        $apellidos = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_APELLIDOS + 1).$rowIndex)
                            )
                        );
                        $estado = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_ESTADO + 1).$rowIndex)
                            )
                        );

                        $documentKey = $documento;
                        $conveniosArray = $conveniosMap[$documentKey] ?? [];

                        // Los convenios ya vienen como array de arrays desde buildConveniosSummaryMap
                        $convenios = [];
                        foreach ($conveniosArray as $convenioData) {
                            $convenios[] = [
                                'cliente' => $convenioData['cliente'] ?? 'SIN ASIGNAR',
                                'proceso' => $convenioData['proceso'] ?? null,
                                'estado' => $convenioData['estado'] ?? null,
                                'fecha_ingreso' => $convenioData['fecha_ingreso'] ?? null,
                                'fecha_fin' => $convenioData['fecha_fin'] ?? null,
                            ];
                        }

                        $affiliates[] = [
                            'tipo_documento' => $tipoDocumento,
                            'documento' => $documento,
                            'nombres' => $nombres,
                            'apellidos' => $apellidos,
                            'estado' => $estado,
                            'convenios' => $convenios,
                        ];
                    }

                    if (count($affiliates) === 0 && $highestRow >= 2) {
                        Log::warning('AfiliadoService: listado básico (dotación/caché) quedó sin filas pese a filas en hoja INFORMACIÓN GENERAL — suele indicar columnas desplazadas o celdas tipo/doc vacías', [
                            'dotacion_epp' => true,
                            'afiliado_basic_list' => true,
                            'cause' => 'no_valid_rows_after_scan',
                            'highest_row_in_sheet' => $highestRow,
                            'rows_skipped_missing_tipo_or_documento' => $skippedRowsMissingTipoOrDocumento,
                            'cache_key' => 'afiliado_service.all_basic',
                            'cache_ttl_minutes' => 30,
                            'disk' => $disk,
                        ]);
                    }

                    if (! $conveniosSheet) {
                        Log::info('AfiliadoService: pestaña CONVENIOS no encontrada durante listado básico — hospital quedará SIN ASIGNAR en dotación', [
                            'dotacion_epp' => true,
                            'afiliado_basic_list' => true,
                            'rows_in_list' => count($affiliates),
                        ]);
                    }

                    Log::info('Listado general de afiliados generado para dotación/EPP', [
                        'count' => count($affiliates),
                        'disk' => $disk,
                    ]);

                    return $affiliates;
                } catch (SpreadsheetException $e) {
                    Log::error('Error al procesar archivo Excel de afiliados (listado general)', [
                        'dotacion_epp' => true,
                        'afiliado_basic_list' => true,
                        'cause' => 'spreadsheet_exception',
                        'error' => $e->getMessage(),
                        'file_path' => self::EXCEL_FILE_PATH,
                        'disk' => $disk,
                    ]);

                    return [];
                } catch (\Throwable $e) {
                    Log::error('Error inesperado al generar listado general de afiliados', [
                        'dotacion_epp' => true,
                        'afiliado_basic_list' => true,
                        'cause' => 'unexpected_throwable',
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'disk' => $disk,
                        'file_path' => self::EXCEL_FILE_PATH,
                    ]);

                    return [];
                }
            }, []);
        });
    }

    /**
     * Clear cached affiliate listing.
     */
    public function forgetAllAfiliadosBasicCache(): void
    {
        Cache::tags(['afiliados'])->forget('afiliado_service.all_basic');
    }

    /**
     * Clear cached affiliate information for a specific document.
     * Clears both authentication cache and complete info cache.
     */
    public function forgetAfiliadoCache(string $tipoDocumento, string $documento, string $fechaExpedicion): void
    {
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        // Limpiar caché de autenticación básica
        $authCacheKey = sprintf(
            self::AUTH_CACHE_KEY_FORMAT,
            md5($normalizedTipoDocumento ?? ''),
            md5($normalizedDocumento ?? ''),
            md5($normalizedFechaExpedicion ?? '')
        );

        // Limpiar caché de información completa
        $completeCacheKey = sprintf(
            'afiliado:complete:%s:%s:%s',
            md5($normalizedTipoDocumento ?? ''),
            md5($normalizedDocumento ?? ''),
            md5($normalizedFechaExpedicion ?? '')
        );

        Cache::tags(['afiliados'])->forget($authCacheKey);
        Cache::tags(['afiliados'])->forget($completeCacheKey);

        Log::info('Caché de afiliado limpiado', [
            'documento' => $documento,
            'tipo_documento' => $tipoDocumento,
            'auth_cache_key' => $authCacheKey,
            'complete_cache_key' => $completeCacheKey,
        ]);
    }

    /**
     * Clear all cached affiliate information.
     * Uses Redis cache tags to efficiently flush all affiliate-related cache entries.
     */
    public function forgetAllAfiliadoCache(): void
    {
        try {
            // Flush all cache entries tagged with 'afiliados'
            // This includes:
            // - afiliado:auth:* (authentication cache)
            // - afiliado:complete:* (complete info cache)
            // - afiliado:doc_only:* (document-only cache)
            // - afiliado_service.all_basic (basic listing cache)
            Cache::tags(['afiliados'])->flush();

            Log::info('Todos los caches de afiliados limpiados usando tags', [
                'tag' => 'afiliados',
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            // Fallback: try to clear known cache keys individually
            Log::warning('Error al limpiar cache con tags, intentando método alternativo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                Cache::tags(['afiliados'])->forget('afiliado_service.all_basic');
            } catch (\Exception $fallbackError) {
                Log::error('Error al limpiar cache de afiliados con método alternativo', [
                    'error' => $fallbackError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Execute a callback with a local temporary copy of the afiliados Excel file.
     *
     * @template T
     *
     * @param  callable(string, string):T  $callback
     * @param  T|null  $default
     * @return T|null
     */
    private function withExcelFile(callable $callback, $default = null)
    {
        $localCopy = $this->getExcelLocalCopy();
        if ($localCopy === null) {
            return $default;
        }

        try {
            return $callback($localCopy['path'], $localCopy['disk']);
        } finally {
            if (! empty($localCopy['path']) && file_exists($localCopy['path'])) {
                @unlink($localCopy['path']);
            }
        }
    }

    /**
     * Create a local temporary copy of the afiliados Excel file from configured storage disks.
     *
     * @return array{path: string, disk: string}|null
     */
    private function getExcelLocalCopy(): ?array
    {
        $filePath = self::EXCEL_FILE_PATH;
        $disks = [self::STORAGE_PRIMARY_DISK, self::STORAGE_FALLBACK_DISK];

        foreach ($disks as $disk) {
            try {
                if (! Storage::disk($disk)->exists($filePath)) {
                    continue;
                }

                $stream = Storage::disk($disk)->readStream($filePath);
                if ($stream === false) {
                    Log::warning('No se pudo abrir stream del archivo de afiliados', [
                        'disk' => $disk,
                        'file_path' => $filePath,
                    ]);

                    continue;
                }

                $tempBasePath = tempnam(sys_get_temp_dir(), 'prosanet_afiliados_');
                if ($tempBasePath === false) {
                    fclose($stream);
                    Log::error('No se pudo crear archivo temporal para afiliados');

                    return null;
                }

                $tempPath = $tempBasePath.'.xlsx';
                if (@rename($tempBasePath, $tempPath) === false) {
                    $tempPath = $tempBasePath;
                }

                $destination = fopen($tempPath, 'w+b');
                if ($destination === false) {
                    fclose($stream);
                    @unlink($tempPath);
                    Log::error('No se pudo abrir archivo temporal para escribir afiliados', [
                        'file_path' => $tempPath,
                    ]);

                    return null;
                }

                stream_copy_to_stream($stream, $destination);
                fclose($stream);
                fclose($destination);

                return [
                    'path' => $tempPath,
                    'disk' => $disk,
                ];
            } catch (\Throwable $e) {
                Log::error('Error al crear copia local del archivo de afiliados', [
                    'error' => $e->getMessage(),
                    'disk' => $disk,
                    'file_path' => $filePath,
                ]);
            }
        }

        Log::error('Archivo de afiliados no encontrado en los discos configurados', [
            'file_path' => $filePath,
            'disks' => $disks,
        ]);

        return null;
    }

    /**
     * Build column index mapping from sheet headers.
     * Returns array mapping internal column name to Excel column index (0-based).
     */
    private function buildColumnMapping($sheet): array
    {
        $mapping = [];
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        // Read header row (row 1)
        for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $cell = $sheet->getCell($colLetter.'1');
            $headerValue = $this->getCellValue($cell);

            if (empty($headerValue)) {
                continue;
            }

            $normalizedHeader = $this->normalizeColumnName((string) $headerValue);

            // Build list of all potential matches with their specificity (longer = more specific)
            // First pass: collect exact matches only
            $exactMatches = [];
            foreach (self::COLUMN_NAME_MAPPINGS as $internalName => $possibleNames) {
                foreach ($possibleNames as $possibleName) {
                    $normalizedPossible = $this->normalizeColumnName($possibleName);

                    // Check for exact match
                    if ($normalizedHeader === $normalizedPossible) {
                        $exactMatches[] = [
                            'internalName' => $internalName,
                            'length' => strlen($normalizedPossible),
                        ];
                    }
                }
            }

            // If we have exact matches, use the longest one (most specific)
            if (! empty($exactMatches)) {
                usort($exactMatches, function ($a, $b) {
                    return $b['length'] <=> $a['length']; // Longer first
                });
                $mapping[$exactMatches[0]['internalName']] = $colIndex - 1;

                continue; // Move to next column
            }

            // Second pass: if no exact matches, check for prefix matches
            $prefixMatches = [];
            foreach (self::COLUMN_NAME_MAPPINGS as $internalName => $possibleNames) {
                foreach ($possibleNames as $possibleName) {
                    $normalizedPossible = $this->normalizeColumnName($possibleName);

                    // Check for prefix match: header must be longer and start with possible name followed by space
                    // This prevents "estado civil" from matching "estado" incorrectly
                    if (strlen($normalizedHeader) > strlen($normalizedPossible)
                        && strpos($normalizedHeader, $normalizedPossible.' ') === 0) {
                        $prefixMatches[] = [
                            'internalName' => $internalName,
                            'length' => strlen($normalizedPossible),
                        ];
                    }
                }
            }

            // If we have prefix matches, use the longest one (most specific)
            if (! empty($prefixMatches)) {
                usort($prefixMatches, function ($a, $b) {
                    return $b['length'] <=> $a['length']; // Longer first
                });
                $mapping[$prefixMatches[0]['internalName']] = $colIndex - 1;

                continue; // Move to next column
            }

            // Third pass: if no exact or prefix matches, check for word-boundary matches
            // This handles cases where the header contains the possible name as a complete word
            $wordMatches = [];
            foreach (self::COLUMN_NAME_MAPPINGS as $internalName => $possibleNames) {
                foreach ($possibleNames as $possibleName) {
                    $normalizedPossible = $this->normalizeColumnName($possibleName);

                    // Check if header contains the possible name as a complete word (word boundary)
                    // Only match if the possible name is at least 4 characters to avoid false positives
                    if (strlen($normalizedPossible) >= 4) {
                        // Use word boundary regex to match complete words only
                        $pattern = '/\b'.preg_quote($normalizedPossible, '/').'\b/';
                        if (preg_match($pattern, $normalizedHeader)) {
                            $wordMatches[] = [
                                'internalName' => $internalName,
                                'length' => strlen($normalizedPossible),
                            ];
                        }
                    }
                }
            }

            // If we have word matches, use the longest one (most specific)
            // But only if we haven't already mapped this internal name
            if (! empty($wordMatches)) {
                usort($wordMatches, function ($a, $b) {
                    return $b['length'] <=> $a['length']; // Longer first
                });
                $bestMatch = $wordMatches[0];
                // Only add if this internal name hasn't been mapped yet
                if (! isset($mapping[$bestMatch['internalName']])) {
                    $mapping[$bestMatch['internalName']] = $colIndex - 1;
                }
            }
        }

        return $mapping;
    }

    /**
     * Normalize column name for comparison.
     */
    private function normalizeColumnName(string $name): string
    {
        // Remove accents, convert to lowercase, remove extra spaces
        $name = mb_strtolower(trim($name));
        // Replace accented characters (including uppercase variants)
        $name = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n'],
            $name
        );
        // Normalize whitespace (multiple spaces/tabs to single space)
        $name = preg_replace('/\s+/', ' ', $name);

        // Remove any leading/trailing whitespace
        return trim($name);
    }

    /**
     * Check if normalized header matches a possible column name.
     */
    private function matchesColumnName(string $normalizedHeader, string $possibleName): bool
    {
        $normalizedPossible = $this->normalizeColumnName($possibleName);

        // Exact match or starts with
        return $normalizedHeader === $normalizedPossible
            || strpos($normalizedHeader, $normalizedPossible) === 0
            || strpos($normalizedPossible, $normalizedHeader) === 0;
    }

    /**
     * Get cell value by column name from row data array.
     */
    private function getValueByColumnName(array $row, array $columnMapping, string $columnName, $default = null)
    {
        if (! isset($columnMapping[$columnName])) {
            return $default;
        }

        $columnIndex = $columnMapping[$columnName];

        return $row[$columnIndex] ?? $default;
    }

    /**
     * Find the row index of the affiliate matching the authentication criteria.
     */
    private function findAfiliadoRow(
        array $data,
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion,
    ): ?int {
        // Normalize input values once
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        // Search from row 1 onwards (skip header row at index 0)
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Check if row has enough columns
            if (count($row) < 40) {
                continue;
            }

            // Check if this looks like a header row (contains header text)
            $firstCol = $this->normalizeValue($row[0] ?? '');
            if ($firstCol && (
                stripos($firstCol, 'tipo documento') !== false
                || stripos($firstCol, 'documento') === 0
                || stripos($firstCol, 'nuip') !== false
            )) {
                continue;
            }

            $rowTipoDocumento = $this->normalizeValue($row[self::COL_TIPO_DOCUMENTO] ?? '');
            $rowDocumento = $this->normalizeValue($row[self::COL_DOCUMENTO] ?? '');
            $rowFechaExpedicion = $this->normalizeDate($row[self::COL_FECHA_EXPEDICION] ?? '');

            // Match authentication criteria
            if ($rowTipoDocumento === $normalizedTipoDocumento
                && $rowDocumento === $normalizedDocumento
                && $rowFechaExpedicion === $normalizedFechaExpedicion) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find affiliate row and determine match reason in a single pass.
     * Returns:
     * - ['result' => 'match', 'data' => rowData, 'mapping' => columnMapping]
     * - ['result' => 'data_mismatch'] when document number exists but tipo or fecha_expedicion do not match
     * - ['result' => 'not_found'] when no row has that document number
     */
    private function findAfiliadoRowWithReason(
        $sheet,
        string $tipoDocumento,
        string $documento,
        ?string $fechaExpedicion,
    ): array {
        $columnMapping = $this->buildColumnMapping($sheet);
        $requiredColumns = ['tipo_documento', 'documento', 'fecha_expedicion'];
        foreach ($requiredColumns as $col) {
            if (! isset($columnMapping[$col])) {
                Log::error("Columna requerida no encontrada: {$col}", [
                    'available_columns' => array_keys($columnMapping),
                    'documento' => $documento,
                ]);

                return ['result' => 'not_found'];
            }
        }

        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);
        $highestRow = $sheet->getHighestRow();
        $foundDocumentMismatch = false;

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $colIndex = $columnMapping['tipo_documento'];
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $rowTipoDocumento = $this->normalizeValue($this->getCellValue($sheet->getCell($colLetter.$rowIndex)));

            $colIndex = $columnMapping['documento'];
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $rowDocumento = $this->normalizeValue($this->getCellValue($sheet->getCell($colLetter.$rowIndex)));

            $colIndex = $columnMapping['fecha_expedicion'];
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $rowFechaExpedicion = $this->normalizeDate($this->getCellValue($sheet->getCell($colLetter.$rowIndex)));

            if (empty($rowTipoDocumento) && empty($rowDocumento) && empty($rowFechaExpedicion)) {
                continue;
            }
            if ($rowTipoDocumento && (
                stripos($rowTipoDocumento, 'tipo documento') !== false
                || stripos($rowTipoDocumento, 'documento') === 0
                || stripos($rowTipoDocumento, 'nuip') !== false
            )) {
                continue;
            }

            if ($rowDocumento !== $normalizedDocumento) {
                continue;
            }

            if ($rowTipoDocumento === $normalizedTipoDocumento
                && $rowFechaExpedicion === $normalizedFechaExpedicion) {
                $rowData = [];
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
                for ($colIndex = 0; $colIndex < $highestColumnIndex; $colIndex++) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $rowData[] = $this->getCellValue($sheet->getCell($colLetter.$rowIndex));
                }

                return [
                    'result' => 'match',
                    'data' => $rowData,
                    'mapping' => $columnMapping,
                ];
            }

            $foundDocumentMismatch = true;
        }

        return $foundDocumentMismatch
            ? ['result' => 'data_mismatch']
            : ['result' => 'not_found'];
    }

    /**
     * Find affiliate row using optimized iteration (reads only necessary rows).
     * Uses column name mapping instead of fixed column indexes.
     */
    private function findAfiliadoRowOptimized(
        $sheet,
        string $tipoDocumento,
        string $documento,
        ?string $fechaExpedicion = null,
    ): ?array {
        // Build column mapping from headers
        $columnMapping = $this->buildColumnMapping($sheet);

        // Check required columns exist
        $requiredColumns = ['tipo_documento', 'documento', 'fecha_expedicion'];
        foreach ($requiredColumns as $col) {
            if (! isset($columnMapping[$col])) {
                Log::error("Columna requerida no encontrada: {$col}", [
                    'available_columns' => array_keys($columnMapping),
                    'documento' => $documento,
                ]);

                return null;
            }
        }

        // Normalize input values once
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        // Get highest row to know when to stop
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            // Read only necessary columns first for matching (optimization)
            $colIndex = $columnMapping['tipo_documento'];
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $cell = $sheet->getCell($colLetter.$rowIndex);
            $rowTipoDocumentoRaw = $this->getCellValue($cell);

            $colIndex = $columnMapping['documento'];
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $cell = $sheet->getCell($colLetter.$rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);

            $colIndex = $columnMapping['fecha_expedicion'];
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $cell = $sheet->getCell($colLetter.$rowIndex);
            $rowFechaExpedicionRaw = $this->getCellValue($cell);

            // Normalize values for comparison
            $rowTipoDocumento = $this->normalizeValue($rowTipoDocumentoRaw);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);
            $rowFechaExpedicion = $this->normalizeDate($rowFechaExpedicionRaw);

            // Skip if row appears empty
            if (empty($rowTipoDocumento) && empty($rowDocumento) && empty($rowFechaExpedicion)) {
                continue;
            }

            // Check if this looks like a header row
            if ($rowTipoDocumento && (
                stripos($rowTipoDocumento, 'tipo documento') !== false
                || stripos($rowTipoDocumento, 'documento') === 0
                || stripos($rowTipoDocumento, 'nuip') !== false
            )) {
                continue;
            }

            // Match authentication criteria
            if ($rowTipoDocumento === $normalizedTipoDocumento
                && $rowDocumento === $normalizedDocumento
                && $rowFechaExpedicion === $normalizedFechaExpedicion) {
                // Match found! Now read the complete row using column mapping
                $rowData = [];
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

                for ($colIndex = 0; $colIndex < $highestColumnIndex; $colIndex++) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter.$rowIndex);
                    $rowData[] = $this->getCellValue($cell);
                }

                // Return both row data and mapping for later use
                return [
                    'data' => $rowData,
                    'mapping' => $columnMapping,
                ];
            }
        }

        return null;
    }

    /**
     * Find affiliate row by document number only (without tipo_documento or fecha_expedicion).
     * Returns the first match found.
     */
    private function findAfiliadoRowByDocumentoOnly($sheet, string $documento): ?array
    {
        // Build column mapping from headers
        $columnMapping = $this->buildColumnMapping($sheet);

        // Check required columns exist
        if (! isset($columnMapping['documento'])) {
            Log::error("Columna 'documento' no encontrada", [
                'available_columns' => array_keys($columnMapping),
                'documento' => $documento,
            ]);

            return null;
        }

        // Normalize input value
        $normalizedDocumento = $this->normalizeValue($documento);

        // Get highest row to know when to stop
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            // Read only documento column first for matching (optimization)
            $colIndex = $columnMapping['documento'];
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $cell = $sheet->getCell($colLetter.$rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);

            // Skip if row appears empty
            if (empty($rowDocumento)) {
                continue;
            }

            // Check if this looks like a header row
            if (stripos($rowDocumento, 'documento') !== false || stripos($rowDocumento, 'nuip') === 0) {
                continue;
            }

            // Match document number
            if ($rowDocumento === $normalizedDocumento) {
                // Match found! Now read the complete row using column mapping
                $rowData = [];
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

                for ($colIndex = 0; $colIndex < $highestColumnIndex; $colIndex++) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter.$rowIndex);
                    $rowData[] = $this->getCellValue($cell);
                }

                // Return both row data and mapping for later use
                return [
                    'data' => $rowData,
                    'mapping' => $columnMapping,
                ];
            }
        }

        return null;
    }

    /**
     * Get cell value handling different data types (dates, numbers, strings).
     */
    private function getCellValue($cell)
    {
        $value = $cell->getCalculatedValue();

        // Handle DateTime objects from Excel dates
        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d');
        }

        // Handle numeric values (convert to string to maintain consistency)
        if (is_numeric($value) && ! is_string($value)) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * Get convenios by documento using optimized iteration (reads only matching rows).
     */
    private function getConveniosByDocumentoOptimized($sheet, string $documento): array
    {
        $convenios = [];
        $normalizedDocumento = $this->normalizeValue($documento);

        // Get highest row
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            // Read only necessary columns for matching
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_CONV_DOCUMENTO_AFILIADO + 1);
            $cell = $sheet->getCell($colLetter.$rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);

            // If document matches, read the full row
            if ($rowDocumento === $normalizedDocumento) {
                // Read all columns for this row
                $row = [];
                for ($colIndex = 0; $colIndex < 10; $colIndex++) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter.$rowIndex);
                    $row[] = $this->getCellValue($cell);
                }

                // Skip if row appears empty
                if (empty(array_filter($row))) {
                    continue;
                }

                // Check if this looks like a header row
                $firstCol = $this->normalizeValue($row[0] ?? '');
                if ($firstCol && (
                    stripos($firstCol, 'documento afiliado') !== false
                    || stripos($firstCol, 'documento') === 0
                )) {
                    continue;
                }

                $convenios[] = [
                    'documento_afiliado' => $rowDocumento,
                    'nombre_afiliado' => $this->normalizeValue($row[self::COL_CONV_NOMBRE_AFILIADO] ?? ''),
                    'apellidos_afiliado' => $this->normalizeValue($row[self::COL_CONV_APELLIDOS_AFILIADO] ?? ''),
                    'cliente' => $this->normalizeValue($row[self::COL_CONV_CLIENTE] ?? ''),
                    'sucursal' => $this->normalizeValue($row[self::COL_CONV_SUCURSAL] ?? ''),
                    'proceso' => $this->normalizeValue($row[self::COL_CONV_PROCESO] ?? ''),
                    'estado' => $this->normalizeValue($row[self::COL_CONV_ESTADO] ?? ''),
                    'fecha_ingreso' => $this->normalizeDate($row[self::COL_CONV_FECHA_INGRESO] ?? ''),
                    'fecha_fin' => $this->normalizeDate($row[self::COL_CONV_FECHA_FIN] ?? ''),
                    'notas' => $this->normalizeValue($row[self::COL_CONV_NOTAS] ?? ''),
                ];
            }
        }

        return $convenios;
    }

    /**
     * Extract affiliate information from a row using column mapping.
     */
    private function extractAfiliadoInfo(array $rowData, array $columnMapping): array
    {
        // Helper function to get value by column name
        $getValue = function ($colName, $default = null) use ($rowData, $columnMapping) {
            return $this->getValueByColumnName($rowData, $columnMapping, $colName, $default);
        };

        // Handle E.P.S, A.F.P, A.R.L, Caja de Compensacion - they might be concatenated
        $epsRaw = $this->normalizeValue($getValue('eps'));
        $afpRaw = $this->normalizeValue($getValue('afp'));
        $arlRaw = $this->normalizeValue($getValue('arl'));
        $cajaCompensacionRaw = $this->normalizeValue($getValue('caja_compensacion'));

        // Check if EPS contains concatenated values (when other columns are empty)
        // Pattern from images: "NUEVA E.P.S PORVENIR COLMENA COMFENALC 3" or "EPS SURA (A COLPENSION COLMENA COMFENALC 3"
        if (! empty($epsRaw) && (empty($afpRaw) && empty($arlRaw) && empty($cajaCompensacionRaw))) {
            $parsed = $this->parseConcatenatedValues($epsRaw);
            if ($parsed) {
                $eps = $parsed['eps'];
                $afp = $parsed['afp'];
                $arl = $parsed['arl'];
                $cajaCompensacion = $parsed['caja_compensacion'];
            } else {
                $eps = $epsRaw;
                $afp = $afpRaw;
                $arl = $arlRaw;
                $cajaCompensacion = $cajaCompensacionRaw;
            }
        } else {
            // Use separate values if available
            $eps = $epsRaw;
            $afp = $afpRaw;
            $arl = $arlRaw;
            $cajaCompensacion = $cajaCompensacionRaw;
        }

        // Extract date from Banco column if it contains a date (Fecha Rethus might be embedded)
        $banco = $this->normalizeValue($getValue('banco'));
        $fechaRethus = $this->normalizeValue($getValue('fecha_rethus'));

        // If fecha rethus is empty but banco contains a date, extract it
        if (empty($fechaRethus) && ! empty($banco)) {
            $extractedDate = $this->extractDateFromString($banco);
            if ($extractedDate) {
                $fechaRethus = $extractedDate;
                // Remove date from banco
                $banco = preg_replace('/\s+\d{4}-\d{2}-\d{2}/', '', $banco);
            }
        }

        return [
            'tipo_documento' => $this->normalizeValue($getValue('tipo_documento')),
            'documento' => $this->normalizeValue($getValue('documento')),
            'nombres' => $this->normalizeValue($getValue('nombres')),
            'apellidos' => $this->normalizeValue($getValue('apellidos')),
            'estado' => $this->normalizeValue($getValue('estado')),
            'hospital' => $this->normalizeValue($getValue('hospital')),
            'fecha_expedicion' => $this->normalizeDate($getValue('fecha_expedicion')),
            'fecha_nacimiento' => $this->normalizeDate($getValue('fecha_nacimiento')),
            'lugar_nacimiento' => $this->normalizeValue($getValue('lugar_nacimiento')),
            'sexo' => $this->normalizeValue($getValue('sexo')),
            'rh' => $this->normalizeValue($getValue('rh')),
            'fecha_ingreso' => $this->normalizeDate($getValue('fecha_ingreso')),
            'estado_civil' => $this->normalizeValue($getValue('estado_civil')),
            'carnet' => $this->normalizeValue($getValue('carnet')),
            'direccion' => $this->normalizeValue($getValue('direccion')),
            'departamento' => $this->normalizeValue($getValue('departamento')),
            'municipio' => $this->normalizeValue($getValue('municipio')),
            'telefono' => $this->normalizeValue($getValue('telefono')),
            'celular' => $this->normalizeValue($getValue('celular')),
            'correo_personal' => $this->normalizeValue($getValue('correo_personal')),
            'contacto_emergencia' => $this->normalizeValue($getValue('contacto_emergencia')),
            'archivo_liquidado' => $this->normalizeDate($getValue('archivo_liquidado')),
            'fecha_liquidacion' => $this->normalizeDate($getValue('fecha_liquidacion')),
            'talla_uniforme' => $this->normalizeValue($getValue('talla_uniforme')),
            'talla_calzado' => $this->normalizeValue($getValue('talla_calzado')),
            'nivel_educacion' => $this->normalizeNivelEducacion($this->normalizeValue($getValue('nivel_educacion'))),
            'otros_estudios' => $this->normalizeValue($getValue('otros_estudios')),
            'numero_cuenta' => $this->normalizeValue($getValue('numero_cuenta')),
            'tipo_cuenta' => $this->normalizeValue($getValue('tipo_cuenta')),
            'banco' => $banco,
            'fecha_rethus' => $fechaRethus,
            'compensacion_basica' => $this->normalizeNumeric($getValue('compensacion_basica')),
            'tipo_afiliacion' => $this->normalizeValue($getValue('tipo_afiliacion')),
            'eps' => $eps,
            'afp' => $afp,
            'arl' => $arl,
            'caja_compensacion' => $cajaCompensacion,
            'nivel_riesgo' => $this->normalizeValue($getValue('nivel_riesgo')),
            'fecha_vencimiento_poliza' => $this->normalizeDate($getValue('fecha_vencimiento_poliza')),
            'emisor_poliza' => $this->normalizeValue($getValue('emisor_poliza')),
            'detalles' => $this->normalizeValue($getValue('detalles')),
        ];
    }

    /**
     * Get all convenios for a specific documento.
     */
    private function getConveniosByDocumento(array $data, string $documento): array
    {
        $convenios = [];
        $normalizedDocumento = $this->normalizeValue($documento);

        // Skip header row(s)
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Check if row has enough columns
            if (count($row) < 10) {
                continue;
            }

            // Check if this looks like a header row
            $firstCol = $this->normalizeValue($row[0] ?? '');
            if ($firstCol && (
                stripos($firstCol, 'documento afiliado') !== false
                || stripos($firstCol, 'documento') === 0
            )) {
                continue;
            }

            $rowDocumento = $this->normalizeValue($row[self::COL_CONV_DOCUMENTO_AFILIADO] ?? '');

            if ($rowDocumento === $normalizedDocumento) {
                $convenios[] = [
                    'documento_afiliado' => $rowDocumento,
                    'nombre_afiliado' => $this->normalizeValue($row[self::COL_CONV_NOMBRE_AFILIADO] ?? ''),
                    'apellidos_afiliado' => $this->normalizeValue($row[self::COL_CONV_APELLIDOS_AFILIADO] ?? ''),
                    'cliente' => $this->normalizeValue($row[self::COL_CONV_CLIENTE] ?? ''),
                    'sucursal' => $this->normalizeValue($row[self::COL_CONV_SUCURSAL] ?? ''),
                    'proceso' => $this->normalizeValue($row[self::COL_CONV_PROCESO] ?? ''),
                    'estado' => $this->normalizeValue($row[self::COL_CONV_ESTADO] ?? ''),
                    'fecha_ingreso' => $this->normalizeDate($row[self::COL_CONV_FECHA_INGRESO] ?? ''),
                    'fecha_fin' => $this->normalizeDate($row[self::COL_CONV_FECHA_FIN] ?? ''),
                    'notas' => $this->normalizeValue($row[self::COL_CONV_NOTAS] ?? ''),
                ];
            }
        }

        return $convenios;
    }

    /**
     * Filter affiliate response to include only required fields.
     */
    private function filterAfiliadoResponse(array $afiliadoFull, array $conveniosFull): array
    {
        // Fields required for afiliado
        $requiredAfiliadoFields = [
            'tipo_documento',
            'documento',
            'nombres',
            'apellidos',
            'estado',
            'hospital',
            'celular',
            'correo_personal',
        ];

        // Filter afiliado data
        $afiliadoFiltered = [];
        foreach ($requiredAfiliadoFields as $field) {
            $afiliadoFiltered[$field] = $afiliadoFull[$field] ?? null;
        }

        // Filter convenios data with required cliente handling (no transformation)
        $conveniosFiltered = [];
        foreach ($conveniosFull as $convenio) {
            $clienteValue = $convenio['cliente'] ?? null;
            $clienteFinal = ($clienteValue === null || $clienteValue === '') ? 'SIN ASIGNAR' : $clienteValue;

            $convenioFiltered = [
                'cliente' => $clienteFinal,
                'proceso' => $this->normalizeValue($convenio['proceso'] ?? ''),
                'estado' => $this->normalizeValue($convenio['estado'] ?? ''),
                'fecha_ingreso' => $this->normalizeDate($convenio['fecha_ingreso'] ?? ''),
                'fecha_fin' => $this->normalizeDate($convenio['fecha_fin'] ?? ''),
            ];

            $conveniosFiltered[] = $convenioFiltered;
        }

        if (empty($conveniosFiltered)) {
            $afiliadoFiltered['convenios'] = [];

            return $afiliadoFiltered;
        }

        // Seleccionar convenio activo más reciente/actual, o el más reciente si no hay activos
        // Prioridad para activos: fecha_fin vacía > fecha_fin más reciente > fecha_ingreso más reciente
        $conveniosActivos = array_filter($conveniosFiltered, function ($conv) {
            return strcasecmp($conv['estado'], 'Activo') === 0;
        });

        $selectedConvenio = null;

        if (! empty($conveniosActivos)) {
            // Si hay convenios activos, seleccionar el más reciente/actual
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
            usort($conveniosFiltered, function ($a, $b) {
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

            $selectedConvenio = $conveniosFiltered[0];
        }

        $hospitalResolved = $this->normalizeValue($afiliadoFiltered['hospital'] ?? null);
        if (($hospitalResolved === null || $hospitalResolved === '') && $selectedConvenio !== null) {
            $clienteRaw = $selectedConvenio['cliente'] ?? '';
            $clienteTrimmed = is_string($clienteRaw) ? trim($clienteRaw) : '';
            if ($clienteTrimmed !== '' && strcasecmp($clienteTrimmed, 'SIN ASIGNAR') !== 0) {
                $mapped = $this->transformCliente($clienteTrimmed);
                $hospitalResolved = $mapped !== 'SIN ASIGNAR' ? $mapped : $clienteTrimmed;
            }
        }
        $afiliadoFiltered['hospital'] = $hospitalResolved;

        // Devolver arreglo con un solo convenio (o vacío si no hay)
        $afiliadoFiltered['convenios'] = $selectedConvenio ? [$selectedConvenio] : [];

        return $afiliadoFiltered;
    }

    /**
     * Transform cliente name according to mapping rules
     * Only exact matches are allowed to avoid errors with similar names.
     */
    private function transformCliente(?string $cliente): string
    {
        if (empty($cliente)) {
            return 'SIN ASIGNAR';
        }

        $clienteUpper = strtoupper(trim($cliente));

        // Only exact matches - no partial matching to avoid errors
        $exactMappings = [
            'HMFS - BELLO' => 'Bello',
            'HLM - GRUPO 1' => 'La Maria',
            'HLM - GRUPO 3' => 'La Maria',
            'HLM - GRUPO 2' => 'La Maria',
            'HSJDRIONEGRO' => 'Rionegro',
            'LA MARIA - COOSALUD' => 'La Maria',
            'LA MARIA UPAI 245' => 'La Maria',
            'LA MARIA UPAI - 0028 - 2023' => 'La Maria',
            'LA MARIA - ENTERRITORIO' => 'La Maria',
            'LA MARIA - ENTERRITORIO 2' => 'La Maria',
            'LA MARIA - VIH - 1' => 'La Maria',
            'LA MARIA UPAI - 140 - 2023' => 'La Maria',
            'LA MARIA - TRANSMISIBLES 176' => 'La Maria',
            'LA MARIA - TRANSMISIBLES' => 'La Maria',
            'ADMON' => 'ADMON',
        ];

        // Check exact match only
        if (isset($exactMappings[$clienteUpper])) {
            return $exactMappings[$clienteUpper];
        }

        // If no exact match found, return SIN ASIGNAR
        return 'SIN ASIGNAR';
    }

    /**
     * Normalize a value (trim and handle empty values).
     */
    private function normalizeValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize date format for comparison and storage.
     */
    private function normalizeDate($date): ?string
    {
        if (empty($date)) {
            return null;
        }

        $dateStr = trim((string) $date);

        if ($dateStr === '') {
            return null;
        }

        // If already in YYYY-MM-DD format, return as is
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $dateStr;
        }

        // Try to parse various date formats
        try {
            // Try YYYY-MM-DD format
            $parsedDate = \DateTime::createFromFormat('Y-m-d', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            // Try d/m/Y format
            $parsedDate = \DateTime::createFromFormat('d/m/Y', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            // Try m/d/Y format
            $parsedDate = \DateTime::createFromFormat('m/d/Y', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            // Try Excel serial date (numeric)
            if (is_numeric($dateStr)) {
                $excelBaseDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $dateStr);

                return $excelBaseDate->format('Y-m-d');
            }

            // If none work, return original (might be invalid date)
            return $dateStr;
        } catch (\Exception $e) {
            Log::warning('Error al normalizar fecha', [
                'date' => $dateStr,
                'error' => $e->getMessage(),
            ]);

            return $dateStr;
        }
    }

    /**
     * Normalize numeric value.
     */
    private function normalizeNumeric($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        // Remove any non-numeric characters except decimal point
        $normalized = preg_replace('/[^0-9.]/', '', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize nivel educativo value
     * Replaces: Secundaria -> Bachiller, Pregrado -> Profesional, Especialización -> Especialista
     * Keeps: Tecnico and Tecnologo without accents (as they come from Excel).
     */
    private function normalizeNivelEducacion(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        // Case-insensitive replacements
        $replacements = [
            'Secundaria' => 'Bachiller',
            'secundaria' => 'Bachiller',
            'SECUNDARIA' => 'Bachiller',
            'Pregrado' => 'Profesional',
            'pregrado' => 'Profesional',
            'PREGrado' => 'Profesional',
            'Especialización' => 'Especialista',
            'especialización' => 'Especialista',
            'Especializacion' => 'Especialista',
            'especializacion' => 'Especialista',
            'ESPECIALIZACIÓN' => 'Especialista',
        ];

        // Check for exact match (case-insensitive)
        foreach ($replacements as $old => $new) {
            if (strcasecmp($normalized, $old) === 0) {
                return $new;
            }
        }

        // If contains the word, try to replace
        foreach ($replacements as $old => $new) {
            if (stripos($normalized, $old) !== false) {
                return str_ireplace($old, $new, $normalized);
            }
        }

        // Tecnico and Tecnologo come without accents from Excel, so keep as is
        return $normalized;
    }

    /**
     * Parse concatenated values from EPS column (if EPS, AFP, ARL, Caja are in one cell)
     * Examples: "NUEVA E.P.S PORVENIR COLMENA COMFENALC 3"
     *           "EPS SURA (A COLPENSION COLMENA COMFENALC 3"
     *           "FOSYGA COLPENSION COLMENA COMFENALC 3".
     */
    private function parseConcatenatedValues(string $concatenated): ?array
    {
        if (empty($concatenated)) {
            return null;
        }

        $eps = '';
        $afp = '';
        $arl = '';
        $cajaCompensacion = '';

        // Common AFP names
        $afpPatterns = ['PORVENIR', 'COLPENSION', 'COLFONDOS', 'NINGUNA'];

        // Common ARL names
        $arlPatterns = ['COLMENA'];

        // Common Caja de Compensacion names
        $cajaPatterns = ['COMFENALC'];

        // Common EPS patterns (might be multi-word)
        $text = $concatenated;

        // Try to find patterns from end to beginning (Caja, ARL, AFP, then EPS is what's left)

        // Find Caja de Compensacion (usually ends with "COMFENALC 3" or "COMFENALC 4")
        if (preg_match('/\b(COMFENALC\s+\d+)\b/i', $text, $matches)) {
            $cajaCompensacion = trim($matches[1]);
            $text = preg_replace('/\b'.preg_quote($matches[1], '/').'\b/i', '', $text);
        }

        // Find ARL (usually "COLMENA")
        if (preg_match('/\b(COLMENA)\b/i', $text, $matches)) {
            $arl = trim($matches[1]);
            $text = preg_replace('/\b'.preg_quote($matches[1], '/').'\b/i', '', $text);
        }

        // Find AFP (PORVENIR, COLPENSION, COLFONDOS, or NINGUNA)
        foreach ($afpPatterns as $pattern) {
            if (preg_match('/\b('.preg_quote($pattern, '/').')\b/i', $text, $matches)) {
                $afp = trim($matches[1]);
                $text = preg_replace('/\b'.preg_quote($matches[1], '/').'\b/i', '', $text);
                break;
            }
        }

        // What's left should be EPS
        $eps = trim(preg_replace('/\s+/', ' ', $text));

        // If we successfully extracted at least ARL or AFP, return parsed values
        if (! empty($arl) || ! empty($afp) || ! empty($cajaCompensacion)) {
            return [
                'eps' => $eps ?: null,
                'afp' => $afp ?: null,
                'arl' => $arl ?: null,
                'caja_compensacion' => $cajaCompensacion ?: null,
            ];
        }

        return null;
    }

    /**
     * Extract date from a string (e.g., "BANCOLOME 2022-11-11").
     */
    private function extractDateFromString(string $value): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get beneficiarios for a specific documento (if sheet exists).
     */
    private function getBeneficiariosByDocumento($spreadsheet, string $documento): array
    {
        try {
            // Check if BENEFICIARIOS sheet exists
            $beneficiariosSheet = $spreadsheet->getSheetByName(self::SHEET_BENEFICIARIOS);

            if (! $beneficiariosSheet) {
                Log::info('Pestaña BENEFICIARIOS no encontrada en el archivo Excel');

                return [];
            }

            // Get beneficiarios using optimized method
            return $this->getBeneficiariosByDocumentoOptimized($beneficiariosSheet, $documento);
        } catch (\Throwable $e) {
            Log::warning('Error al obtener beneficiarios', [
                'error' => $e->getMessage(),
                'documento' => $documento,
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Get beneficiarios by documento using optimized iteration (reads only matching rows).
     */
    private function getBeneficiariosByDocumentoOptimized($sheet, string $documento): array
    {
        $beneficiarios = [];
        $normalizedDocumento = $this->normalizeValue($documento);

        // Get highest row
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            // Read only necessary columns for matching
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_BEN_DOCUMENTO_AFILIADO + 1);
            $cell = $sheet->getCell($colLetter.$rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);

            // If document matches, read the full row
            if ($rowDocumento === $normalizedDocumento) {
                // Read all columns for this row (8 columns: Documento afiliado, Tipo documento, Documento, Nombres, Apellidos, Fecha nacimiento, Sexo, Notas)
                $row = [];
                for ($colIndex = 0; $colIndex < 8; $colIndex++) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter.$rowIndex);
                    $row[] = $this->getCellValue($cell);
                }

                // Skip if row appears empty
                if (empty(array_filter($row))) {
                    continue;
                }

                // Check if this looks like a header row
                $firstCol = $this->normalizeValue($row[0] ?? '');
                if ($firstCol && (
                    stripos($firstCol, 'documento afiliado') !== false
                    || stripos($firstCol, 'documento') === 0
                    || stripos($firstCol, 'tipo documento') !== false
                )) {
                    continue;
                }

                $beneficiarios[] = [
                    'documento_afiliado' => $rowDocumento,
                    'tipo_documento' => $this->normalizeValue($row[self::COL_BEN_TIPO_DOCUMENTO] ?? ''),
                    'documento' => $this->normalizeValue($row[self::COL_BEN_DOCUMENTO] ?? ''),
                    'nombres' => $this->normalizeValue($row[self::COL_BEN_NOMBRES] ?? ''),
                    'apellidos' => $this->normalizeValue($row[self::COL_BEN_APELLIDOS] ?? ''),
                    'fecha_nacimiento' => $this->normalizeDate($row[self::COL_BEN_FECHA_NACIMIENTO] ?? ''),
                    'sexo' => $this->normalizeValue($row[self::COL_BEN_SEXO] ?? ''),
                    'parentesco' => $this->normalizeValue($row[self::COL_BEN_NOTAS_PARENTESCO] ?? ''),
                ];
            }
        }

        return $beneficiarios;
    }

    /**
     * Filter affiliate information to exclude sensitive/unnecessary fields.
     * Note: fecha_nacimiento and sexo are included in the response as they're needed for data update forms.
     */
    private function filterAfiliadoCompleteInfo(array $afiliadoFull): array
    {
        // Fields to exclude from response
        $excludedFields = [
            'fecha_ingreso',
            'carnet',
            'archivo_liquidado',
            'fecha_liquidacion',
            'otros_estudios',
            'fecha_rethus',
            'compensacion_basica',
            'tipo_afiliacion',
            'arl',
            'caja_compensacion',
            'nivel_riesgo',
            'fecha_vencimiento_poliza',
            'emisor_poliza',
            'detalles',
        ];

        $filtered = [];
        foreach ($afiliadoFull as $key => $value) {
            if (! in_array($key, $excludedFields)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Select the most recent convenio from an array of convenios.
     * Priority: Active convenios first, then by fecha_fin (most recent), then by fecha_ingreso.
     *
     * @param  array  $convenios  Array of convenio arrays
     * @return array Array containing only the most recent convenio (or empty array if no convenios)
     */
    private function selectMostRecentConvenio(array $convenios): array
    {
        if (empty($convenios)) {
            return [];
        }

        // Si solo hay un convenio, retornarlo directamente
        if (count($convenios) === 1) {
            return $convenios;
        }

        // Filtrar convenios activos
        $conveniosActivos = array_filter($convenios, function ($conv) {
            $estado = is_string($conv['estado'] ?? null) ? trim($conv['estado']) : '';

            return strcasecmp($estado, 'Activo') === 0;
        });

        $selectedConvenio = null;

        if (! empty($conveniosActivos)) {
            // Si hay convenios activos, seleccionar el más reciente/actual
            // Prioridad: fecha_fin vacía/null > fecha_fin más reciente > fecha_ingreso más reciente
            usort($conveniosActivos, function ($a, $b) {
                // Normalizar valores de fecha_fin (pueden ser null, '', o string con fecha)
                $aFechaFin = $a['fecha_fin'] ?? null;
                $bFechaFin = $b['fecha_fin'] ?? null;

                // Considerar vacío tanto null como string vacío
                $aFechaFinVacia = empty($aFechaFin) || $aFechaFin === null;
                $bFechaFinVacia = empty($bFechaFin) || $bFechaFin === null;

                // Si uno tiene fecha_fin vacía y el otro no, el vacío tiene prioridad (más reciente)
                if ($aFechaFinVacia && ! $bFechaFinVacia) {
                    return -1; // $a tiene prioridad (viene primero)
                }
                if (! $aFechaFinVacia && $bFechaFinVacia) {
                    return 1; // $b tiene prioridad (viene primero)
                }

                // Si ambos tienen fecha_fin, comparar por fecha_fin (más reciente primero)
                if (! $aFechaFinVacia && ! $bFechaFinVacia) {
                    $comparison = strcmp((string) $bFechaFin, (string) $aFechaFin);
                    if ($comparison !== 0) {
                        return $comparison; // Más reciente primero
                    }
                }

                // Si las fechas_fin son iguales o ambas vacías, usar fecha_ingreso como criterio secundario
                $aFechaIngreso = $a['fecha_ingreso'] ?? '';
                $bFechaIngreso = $b['fecha_ingreso'] ?? '';

                return strcmp((string) $bFechaIngreso, (string) $aFechaIngreso); // Más reciente primero
            });

            $selectedConvenio = reset($conveniosActivos);
        } else {
            // Si no hay activos, seleccionar el más reciente por fecha_fin
            usort($convenios, function ($a, $b) {
                // Normalizar valores de fecha_fin
                $aFechaFin = $a['fecha_fin'] ?? null;
                $bFechaFin = $b['fecha_fin'] ?? null;

                $aFechaFinVacia = empty($aFechaFin) || $aFechaFin === null;
                $bFechaFinVacia = empty($bFechaFin) || $bFechaFin === null;

                // Fecha_fin vacía tiene menor prioridad cuando no hay activos
                if ($aFechaFinVacia && ! $bFechaFinVacia) {
                    return 1; // $b tiene prioridad
                }
                if (! $aFechaFinVacia && $bFechaFinVacia) {
                    return -1; // $a tiene prioridad
                }

                // Comparar por fecha_fin (más reciente primero)
                if (! $aFechaFinVacia && ! $bFechaFinVacia) {
                    $comparison = strcmp((string) $bFechaFin, (string) $aFechaFin);
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                // Si las fechas_fin son iguales, usar fecha_ingreso
                $aFechaIngreso = $a['fecha_ingreso'] ?? '';
                $bFechaIngreso = $b['fecha_ingreso'] ?? '';

                return strcmp((string) $bFechaIngreso, (string) $aFechaIngreso);
            });

            $selectedConvenio = $convenios[0];
        }

        // Devolver arreglo con un solo convenio (o vacío si no hay)
        return $selectedConvenio ? [$selectedConvenio] : [];
    }

    /**
     * Filter beneficiarios information to exclude sensitive fields.
     * Note: fecha_nacimiento is included in the response as it's needed for data update forms.
     */
    private function filterBeneficiariosInfo(array $beneficiarios): array
    {
        // No fields are excluded - all beneficiario fields are needed for data updates
        // fecha_nacimiento is required for the frontend form
        return $beneficiarios;
    }

    private function buildConveniosSummaryMap($sheet): array
    {
        $summary = [];
        $highestRow = $sheet->getHighestRow();

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $documento = $this->normalizeValue(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_DOCUMENTO_AFILIADO + 1).$rowIndex)
                )
            );

            if (empty($documento)) {
                continue;
            }

            $cliente = $this->normalizeValue(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_CLIENTE + 1).$rowIndex)
                )
            );
            $proceso = $this->normalizeValue(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_PROCESO + 1).$rowIndex)
                )
            );
            $estado = $this->normalizeValue(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_ESTADO + 1).$rowIndex)
                )
            );
            $fechaIngreso = $this->normalizeDate(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_FECHA_INGRESO + 1).$rowIndex)
                )
            );
            $fechaFin = $this->normalizeDate(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_FECHA_FIN + 1).$rowIndex)
                )
            );

            $convenio = [
                'cliente' => $cliente ?? 'SIN ASIGNAR',
                'proceso' => $proceso,
                'estado' => $estado,
                'fecha_ingreso' => $fechaIngreso,
                'fecha_fin' => $fechaFin,
            ];

            // Agregar el convenio al array de convenios del documento
            if (! isset($summary[$documento])) {
                $summary[$documento] = [];
            }
            $summary[$documento][] = $convenio;
        }

        return $summary;
    }
}
