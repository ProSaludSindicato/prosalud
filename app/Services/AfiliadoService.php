<?php

namespace App\Services;

use Illuminate\Support\Facades\{Cache, Log, Storage};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\{Exception as SpreadsheetException, IOFactory};
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class AfiliadoService
{
    private const EXCEL_FILE_PATH = 'data/PROSANET_INFORMACION_AFILIADOS.xlsx';
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
    private const COL_NUMERO_CUENTA = 25;
    private const COL_TIPO_CUENTA = 26;
    private const COL_BANCO = 27;
    private const COL_FECHA_RETHUS = 28;
    private const COL_COMPENSACION_BASICA = 29;
    private const COL_TIPO_AFILIACION = 30;
    private const COL_EPS = 31;
    private const COL_AFP = 32;
    private const COL_ARL = 33;
    private const COL_CAJA_COMPENSACION = 34;
    private const COL_NIVEL_RIESGO = 35;
    private const COL_FECHA_VENCIMIENTO_POLIZA = 36;
    private const COL_EMISOR_POLIZA = 37;
    private const COL_DETALLES = 38;

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
            'afiliado:auth:%s:%s:%s',
            md5($normalizedTipoDocumento ?? ''),
            md5($normalizedDocumento ?? ''),
            md5($normalizedFechaExpedicion ?? '')
        );

        Log::debug('[AFILIADO SERVICE] Clave de caché generada', [
            'cache_key' => $cacheKey,
            'documento' => $documento,
        ]);

        // Verificar si existe en caché
        $cachedData = Cache::get($cacheKey);
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

        $result = Cache::remember($cacheKey, now()->addHours(24), function () use ($tipoDocumento, $documento, $fechaExpedicion) {
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
                if (!$informacionSheet) {
                    Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $afiliadoRow = $this->findAfiliadoRowOptimized(
                    $informacionSheet,
                    $tipoDocumento,
                    $documento,
                    $fechaExpedicion
                );

                if (null === $afiliadoRow) {
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

                $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRow);

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
                if (false !== $originalMemoryLimit && null !== $originalMemoryLimit) {
                    ini_set('memory_limit', (string) $originalMemoryLimit);
                }
                if (false !== $originalMaxExecutionTime && null !== $originalMaxExecutionTime) {
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
                if (!$informacionSheet) {
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

                if (null === $afiliadoRowIndex) {
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
                $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRow);

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
                if (false !== $originalMemoryLimit && null !== $originalMemoryLimit) {
                    ini_set('memory_limit', (string) $originalMemoryLimit);
                }
                if (false !== $originalMaxExecutionTime && null !== $originalMaxExecutionTime) {
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

                if (!$informacionSheet) {
                    Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $afiliadoRow = $this->findAfiliadoRowOptimized(
                    $informacionSheet,
                    $tipoDocumento,
                    $documento,
                    $fechaExpedicion
                );

                if (null === $afiliadoRow) {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $correo = $this->normalizeValue($afiliadoRow[self::COL_CORREO_PERSONAL] ?? '');
                $nombres = $this->normalizeValue($afiliadoRow[self::COL_NOMBRES] ?? '');
                $apellidos = $this->normalizeValue($afiliadoRow[self::COL_APELLIDOS] ?? '');

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
                    'nombre' => trim(($nombres ?? '') . ' ' . ($apellidos ?? '')),
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
                if (false !== $originalMemoryLimit && null !== $originalMemoryLimit) {
                    ini_set('memory_limit', (string) $originalMemoryLimit);
                }
                if (false !== $originalMaxExecutionTime && null !== $originalMaxExecutionTime) {
                    set_time_limit((int) $originalMaxExecutionTime);
                }
            }
        }, null);
    }

    /**
     * Get complete affiliate information including all details and convenios
     * This method returns full information without filtering.
     */
    public function getCompleteAfiliadoInfo(
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

                if (!$informacionSheet) {
                    Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $afiliadoRow = $this->findAfiliadoRowOptimized(
                    $informacionSheet,
                    $tipoDocumento,
                    $documento,
                    $fechaExpedicion
                );

                if (null === $afiliadoRow) {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return null;
                }

                $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRow);

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

                return [
                    'afiliado' => $afiliadoFiltered,
                    'convenios' => $conveniosFull,
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
                if (false !== $originalMemoryLimit && null !== $originalMemoryLimit) {
                    ini_set('memory_limit', (string) $originalMemoryLimit);
                }
                if (false !== $originalMaxExecutionTime && null !== $originalMaxExecutionTime) {
                    set_time_limit((int) $originalMaxExecutionTime);
                }
            }
        }, null);
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
        return Cache::remember('afiliado_service.all_basic', now()->addMinutes(30), function () {
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
                        $reader->setReadFilter(new class implements IReadFilter {
                            private const ALLOWED_COLUMNS = [
                                'A', 'B', 'C', 'D', 'E',
                                'F', 'G', 'H', 'I', 'J',
                            ];

                            public function readCell($column, $row, $worksheetName = ''): bool
                            {
                                if (1 === $row) {
                                    return true;
                                }

                                return in_array($column, self::ALLOWED_COLUMNS, true);
                            }
                        });
                    }

                    $spreadsheet = $reader->load($excelPath);

                    $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
                    if (!$informacionSheet) {
                        Log::error('Pestaña INFORMACIÓN GENERAL no encontrada para listado general');

                        return [];
                    }

                    $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
                    $conveniosMap = $conveniosSheet ? $this->buildConveniosSummaryMap($conveniosSheet) : [];

                    $highestRow = $informacionSheet->getHighestRow();
                    $affiliates = [];

                    for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
                        $tipoDocumento = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_TIPO_DOCUMENTO + 1) . $rowIndex)
                            )
                        );
                        $documento = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_DOCUMENTO + 1) . $rowIndex)
                            )
                        );

                        if (empty($tipoDocumento) || empty($documento)) {
                            continue;
                        }

                        $nombres = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_NOMBRES + 1) . $rowIndex)
                            )
                        );
                        $apellidos = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_APELLIDOS + 1) . $rowIndex)
                            )
                        );
                        $estado = $this->normalizeValue(
                            $this->getCellValue(
                                $informacionSheet->getCell(Coordinate::stringFromColumnIndex(self::COL_ESTADO + 1) . $rowIndex)
                            )
                        );

                        $documentKey = $documento;
                        $convenioSummary = $conveniosMap[$documentKey] ?? null;

                        $convenios = [];
                        if ($convenioSummary) {
                            $convenios[] = [
                                'cliente' => $convenioSummary['cliente'] ?? 'SIN ASIGNAR',
                                'proceso' => $convenioSummary['proceso'] ?? null,
                                'estado' => $convenioSummary['estado'] ?? null,
                                'fecha_fin' => $convenioSummary['fecha_fin'] ?? null,
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

                    Log::info('Listado general de afiliados generado para dotación/EPP', [
                        'count' => count($affiliates),
                        'disk' => $disk,
                    ]);

                    return $affiliates;
                } catch (SpreadsheetException $e) {
                    Log::error('Error al procesar archivo Excel de afiliados (listado general)', [
                        'error' => $e->getMessage(),
                        'file_path' => self::EXCEL_FILE_PATH,
                        'disk' => $disk,
                    ]);

                    return [];
                } catch (\Throwable $e) {
                    Log::error('Error inesperado al generar listado general de afiliados', [
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
        Cache::forget('afiliado_service.all_basic');
    }

    /**
     * Clear cached affiliate information for a specific document.
     */
    public function forgetAfiliadoCache(string $tipoDocumento, string $documento, string $fechaExpedicion): void
    {
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);
        
        $cacheKey = sprintf(
            'afiliado:auth:%s:%s:%s',
            md5($normalizedTipoDocumento ?? ''),
            md5($normalizedDocumento ?? ''),
            md5($normalizedFechaExpedicion ?? '')
        );

        Cache::forget($cacheKey);
    }

    /**
     * Clear all cached affiliate information.
     */
    public function forgetAllAfiliadoCache(): void
    {
        // Note: This is a simple implementation. For production, consider using cache tags if available
        // Laravel Redis cache tags require Redis >= 2.2
        Cache::forget('afiliado_service.all_basic');
        // Individual cache keys would need to be tracked or use a pattern-based flush
        // For now, we'll rely on TTL expiration
    }

    /**
     * Execute a callback with a local temporary copy of the afiliados Excel file.
     *
     * @template T
     *
     * @param callable(string, string):T $callback
     * @param T|null                     $default
     *
     * @return T|null
     */
    private function withExcelFile(callable $callback, $default = null)
    {
        $localCopy = $this->getExcelLocalCopy();
        if (null === $localCopy) {
            return $default;
        }

        try {
            return $callback($localCopy['path'], $localCopy['disk']);
        } finally {
            if (!empty($localCopy['path']) && file_exists($localCopy['path'])) {
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
                if (!Storage::disk($disk)->exists($filePath)) {
                    continue;
                }

                $stream = Storage::disk($disk)->readStream($filePath);
                if (false === $stream) {
                    Log::warning('No se pudo abrir stream del archivo de afiliados', [
                        'disk' => $disk,
                        'file_path' => $filePath,
                    ]);
                    continue;
                }

                $tempBasePath = tempnam(sys_get_temp_dir(), 'prosanet_afiliados_');
                if (false === $tempBasePath) {
                    fclose($stream);
                    Log::error('No se pudo crear archivo temporal para afiliados');

                    return null;
                }

                $tempPath = $tempBasePath . '.xlsx';
                if (false === @rename($tempBasePath, $tempPath)) {
                    $tempPath = $tempBasePath;
                }

                $destination = fopen($tempPath, 'w+b');
                if (false === $destination) {
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
        for ($i = 1; $i < count($data); ++$i) {
            $row = $data[$i];

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Check if row has enough columns
            if (count($row) < 39) {
                continue;
            }

            // Check if this looks like a header row (contains header text)
            $firstCol = $this->normalizeValue($row[0] ?? '');
            if ($firstCol && (
                false !== stripos($firstCol, 'tipo documento')
                || 0 === stripos($firstCol, 'documento')
                || false !== stripos($firstCol, 'nuip')
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
     * Find affiliate row using optimized iteration (reads only necessary rows).
     */
    private function findAfiliadoRowOptimized(
        $sheet,
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion,
    ): ?array {
        // Normalize input values once
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        // Get highest row to know when to stop
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
            // Read only necessary columns first for matching (optimization)
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_TIPO_DOCUMENTO + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowTipoDocumentoRaw = $this->getCellValue($cell);

            $colLetter = Coordinate::stringFromColumnIndex(self::COL_DOCUMENTO + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);

            $colLetter = Coordinate::stringFromColumnIndex(self::COL_FECHA_EXPEDICION + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
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
                false !== stripos($rowTipoDocumento, 'tipo documento')
                || 0 === stripos($rowTipoDocumento, 'documento')
                || false !== stripos($rowTipoDocumento, 'nuip')
            )) {
                continue;
            }

            // Match authentication criteria
            if ($rowTipoDocumento === $normalizedTipoDocumento
                && $rowDocumento === $normalizedDocumento
                && $rowFechaExpedicion === $normalizedFechaExpedicion) {
                // Match found! Now read the complete row
                $rowData = [];
                for ($colIndex = 0; $colIndex < 39; ++$colIndex) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter . $rowIndex);
                    $rowData[] = $this->getCellValue($cell);
                }

                return $rowData;
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
        if (is_numeric($value) && !is_string($value)) {
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
        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
            // Read only necessary columns for matching
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_CONV_DOCUMENTO_AFILIADO + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);

            // If document matches, read the full row
            if ($rowDocumento === $normalizedDocumento) {
                // Read all columns for this row
                $row = [];
                for ($colIndex = 0; $colIndex < 10; ++$colIndex) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter . $rowIndex);
                    $row[] = $this->getCellValue($cell);
                }

                // Skip if row appears empty
                if (empty(array_filter($row))) {
                    continue;
                }

                // Check if this looks like a header row
                $firstCol = $this->normalizeValue($row[0] ?? '');
                if ($firstCol && (
                    false !== stripos($firstCol, 'documento afiliado')
                    || 0 === stripos($firstCol, 'documento')
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
     * Extract affiliate information from a row.
     */
    private function extractAfiliadoInfo(array $row): array
    {
        // Handle E.P.S, A.F.P, A.R.L, Caja de Compensacion - they might be concatenated
        $epsRaw = $this->normalizeValue($row[self::COL_EPS] ?? '');
        $afpRaw = $this->normalizeValue($row[self::COL_AFP] ?? '');
        $arlRaw = $this->normalizeValue($row[self::COL_ARL] ?? '');
        $cajaCompensacionRaw = $this->normalizeValue($row[self::COL_CAJA_COMPENSACION] ?? '');

        // Check if EPS contains concatenated values (when other columns are empty)
        // Pattern from images: "NUEVA E.P.S PORVENIR COLMENA COMFENALC 3" or "EPS SURA (A COLPENSION COLMENA COMFENALC 3"
        if (!empty($epsRaw) && (empty($afpRaw) && empty($arlRaw) && empty($cajaCompensacionRaw))) {
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
        $banco = $this->normalizeValue($row[self::COL_BANCO] ?? '');
        $fechaRethus = $this->normalizeValue($row[self::COL_FECHA_RETHUS] ?? '');

        // If fecha rethus is empty but banco contains a date, extract it
        if (empty($fechaRethus) && !empty($banco)) {
            $extractedDate = $this->extractDateFromString($banco);
            if ($extractedDate) {
                $fechaRethus = $extractedDate;
                // Remove date from banco
                $banco = preg_replace('/\s+\d{4}-\d{2}-\d{2}/', '', $banco);
            }
        }

        return [
            'tipo_documento' => $this->normalizeValue($row[self::COL_TIPO_DOCUMENTO] ?? ''),
            'documento' => $this->normalizeValue($row[self::COL_DOCUMENTO] ?? ''),
            'nombres' => $this->normalizeValue($row[self::COL_NOMBRES] ?? ''),
            'apellidos' => $this->normalizeValue($row[self::COL_APELLIDOS] ?? ''),
            'estado' => $this->normalizeValue($row[self::COL_ESTADO] ?? ''),
            'fecha_expedicion' => $this->normalizeDate($row[self::COL_FECHA_EXPEDICION] ?? ''),
            'fecha_nacimiento' => $this->normalizeDate($row[self::COL_FECHA_NACIMIENTO] ?? ''),
            'lugar_nacimiento' => $this->normalizeValue($row[self::COL_LUGAR_NACIMIENTO] ?? ''),
            'sexo' => $this->normalizeValue($row[self::COL_SEXO] ?? ''),
            'rh' => $this->normalizeValue($row[self::COL_RH] ?? ''),
            'fecha_ingreso' => $this->normalizeDate($row[self::COL_FECHA_INGRESO] ?? ''),
            'estado_civil' => $this->normalizeValue($row[self::COL_ESTADO_CIVIL] ?? ''),
            'carnet' => $this->normalizeValue($row[self::COL_CARNET] ?? ''),
            'direccion' => $this->normalizeValue($row[self::COL_DIRECCION] ?? ''),
            'departamento' => $this->normalizeValue($row[self::COL_DEPARTAMENTO] ?? ''),
            'municipio' => $this->normalizeValue($row[self::COL_MUNICIPIO] ?? ''),
            'telefono' => $this->normalizeValue($row[self::COL_TELEFONO] ?? ''),
            'celular' => $this->normalizeValue($row[self::COL_CELULAR] ?? ''),
            'correo_personal' => $this->normalizeValue($row[self::COL_CORREO_PERSONAL] ?? ''),
            'archivo_liquidado' => $this->normalizeDate($row[self::COL_ARCHIVO_LIQUIDADO] ?? ''),
            'fecha_liquidacion' => $this->normalizeDate($row[self::COL_FECHA_LIQUIDACION] ?? ''),
            'talla_uniforme' => $this->normalizeValue($row[self::COL_TALLA_UNIFORME] ?? ''),
            'talla_calzado' => $this->normalizeValue($row[self::COL_TALLA_CALZADO] ?? ''),
            'nivel_educacion' => $this->normalizeNivelEducacion($this->normalizeValue($row[self::COL_NIVEL_EDUCACION] ?? '')),
            'otros_estudios' => $this->normalizeValue($row[self::COL_OTROS_ESTUDIOS] ?? ''),
            'numero_cuenta' => $this->normalizeValue($row[self::COL_NUMERO_CUENTA] ?? ''),
            'tipo_cuenta' => $this->normalizeValue($row[self::COL_TIPO_CUENTA] ?? ''),
            'banco' => $banco,
            'fecha_rethus' => $fechaRethus,
            'compensacion_basica' => $this->normalizeNumeric($row[self::COL_COMPENSACION_BASICA] ?? ''),
            'tipo_afiliacion' => $this->normalizeValue($row[self::COL_TIPO_AFILIACION] ?? ''),
            'eps' => $eps,
            'afp' => $afp,
            'arl' => $arl,
            'caja_compensacion' => $cajaCompensacion,
            'nivel_riesgo' => $this->normalizeValue($row[self::COL_NIVEL_RIESGO] ?? ''),
            'fecha_vencimiento_poliza' => $this->normalizeDate($row[self::COL_FECHA_VENCIMIENTO_POLIZA] ?? ''),
            'emisor_poliza' => $this->normalizeValue($row[self::COL_EMISOR_POLIZA] ?? ''),
            'detalles' => $this->normalizeValue($row[self::COL_DETALLES] ?? ''),
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
        for ($i = 1; $i < count($data); ++$i) {
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
                false !== stripos($firstCol, 'documento afiliado')
                || 0 === stripos($firstCol, 'documento')
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
            $clienteFinal = (null === $clienteValue || '' === $clienteValue) ? 'SIN ASIGNAR' : $clienteValue;

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

        if (!empty($conveniosActivos)) {
            // Si hay convenios activos, seleccionar el más reciente/actual
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
            usort($conveniosFiltered, function ($a, $b) {
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

            $selectedConvenio = $conveniosFiltered[0];
        }

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
        if (null === $value || '' === $value) {
            return null;
        }

        $normalized = trim((string) $value);

        return '' === $normalized ? null : $normalized;
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

        if ('' === $dateStr) {
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
        if (null === $value || '' === $value) {
            return null;
        }

        $normalized = trim((string) $value);

        if ('' === $normalized) {
            return null;
        }

        // Remove any non-numeric characters except decimal point
        $normalized = preg_replace('/[^0-9.]/', '', $normalized);

        return '' === $normalized ? null : $normalized;
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

        if ('' === $normalized) {
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
            if (0 === strcasecmp($normalized, $old)) {
                return $new;
            }
        }

        // If contains the word, try to replace
        foreach ($replacements as $old => $new) {
            if (false !== stripos($normalized, $old)) {
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
            $text = preg_replace('/\b' . preg_quote($matches[1], '/') . '\b/i', '', $text);
        }

        // Find ARL (usually "COLMENA")
        if (preg_match('/\b(COLMENA)\b/i', $text, $matches)) {
            $arl = trim($matches[1]);
            $text = preg_replace('/\b' . preg_quote($matches[1], '/') . '\b/i', '', $text);
        }

        // Find AFP (PORVENIR, COLPENSION, COLFONDOS, or NINGUNA)
        foreach ($afpPatterns as $pattern) {
            if (preg_match('/\b(' . preg_quote($pattern, '/') . ')\b/i', $text, $matches)) {
                $afp = trim($matches[1]);
                $text = preg_replace('/\b' . preg_quote($matches[1], '/') . '\b/i', '', $text);
                break;
            }
        }

        // What's left should be EPS
        $eps = trim(preg_replace('/\s+/', ' ', $text));

        // If we successfully extracted at least ARL or AFP, return parsed values
        if (!empty($arl) || !empty($afp) || !empty($cajaCompensacion)) {
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

            if (!$beneficiariosSheet) {
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
        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
            // Read only necessary columns for matching
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_BEN_DOCUMENTO_AFILIADO + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);

            // If document matches, read the full row
            if ($rowDocumento === $normalizedDocumento) {
                // Read all columns for this row (8 columns: Documento afiliado, Tipo documento, Documento, Nombres, Apellidos, Fecha nacimiento, Sexo, Notas)
                $row = [];
                for ($colIndex = 0; $colIndex < 8; ++$colIndex) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter . $rowIndex);
                    $row[] = $this->getCellValue($cell);
                }

                // Skip if row appears empty
                if (empty(array_filter($row))) {
                    continue;
                }

                // Check if this looks like a header row
                $firstCol = $this->normalizeValue($row[0] ?? '');
                if ($firstCol && (
                    false !== stripos($firstCol, 'documento afiliado')
                    || 0 === stripos($firstCol, 'documento')
                    || false !== stripos($firstCol, 'tipo documento')
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
     */
    private function filterAfiliadoCompleteInfo(array $afiliadoFull): array
    {
        // Fields to exclude from response
        $excludedFields = [
            'fecha_nacimiento',
            'lugar_nacimiento',
            'sexo',
            'rh',
            'fecha_ingreso',
            'carnet',
            'departamento',
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
            if (!in_array($key, $excludedFields)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Filter beneficiarios information to exclude sensitive fields.
     */
    private function filterBeneficiariosInfo(array $beneficiarios): array
    {
        $excludedFields = ['fecha_nacimiento'];

        $filtered = [];
        foreach ($beneficiarios as $beneficiario) {
            $beneficiarioFiltered = [];
            foreach ($beneficiario as $key => $value) {
                if (!in_array($key, $excludedFields)) {
                    $beneficiarioFiltered[$key] = $value;
                }
            }
            $filtered[] = $beneficiarioFiltered;
        }

        return $filtered;
    }

    private function buildConveniosSummaryMap($sheet): array
    {
        $summary = [];
        $highestRow = $sheet->getHighestRow();

        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
            $documento = $this->normalizeValue(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_DOCUMENTO_AFILIADO + 1) . $rowIndex)
                )
            );

            if (empty($documento)) {
                continue;
            }

            $cliente = $this->normalizeValue(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_CLIENTE + 1) . $rowIndex)
                )
            );
            $proceso = $this->normalizeValue(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_PROCESO + 1) . $rowIndex)
                )
            );
            $estado = $this->normalizeValue(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_ESTADO + 1) . $rowIndex)
                )
            );
            $fechaFin = $this->normalizeDate(
                $this->getCellValue(
                    $sheet->getCell(Coordinate::stringFromColumnIndex(self::COL_CONV_FECHA_FIN + 1) . $rowIndex)
                )
            );

            $candidate = [
                'cliente' => $cliente ?? 'SIN ASIGNAR',
                'proceso' => $proceso,
                'estado' => $estado,
                'fecha_fin' => $fechaFin,
            ];

            $existing = $summary[$documento] ?? null;
            $summary[$documento] = $this->pickBetterConvenioSummary($existing, $candidate);
        }

        return $summary;
    }

    private function pickBetterConvenioSummary(?array $current, array $candidate): array
    {
        if (null === $current) {
            return $candidate;
        }

        $currentActive = isset($current['estado']) && 0 === strcasecmp($current['estado'], 'Activo');
        $candidateActive = isset($candidate['estado']) && 0 === strcasecmp($candidate['estado'], 'Activo');

        if ($currentActive && !$candidateActive) {
            return $current;
        }

        if (!$currentActive && $candidateActive) {
            return $candidate;
        }

        $currentTs = isset($current['fecha_fin']) ? strtotime($current['fecha_fin']) : null;
        $candidateTs = isset($candidate['fecha_fin']) ? strtotime($candidate['fecha_fin']) : null;

        if (false !== $candidateTs && null !== $candidateTs) {
            if (false === $currentTs || null === $currentTs || $candidateTs > $currentTs) {
                return $candidate;
            }
        }

        return $current;
    }
}
