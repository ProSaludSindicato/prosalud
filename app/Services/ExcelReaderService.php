<?php

namespace App\Services;

use Illuminate\Support\Facades\{Cache, Log, Storage};
use PhpOffice\PhpSpreadsheet\{Exception as SpreadsheetException, IOFactory};

class ExcelReaderService
{
    private const INCAPACIDADES_FILE_PATH = 'data/RELACION_INCAPACIDADES.xlsx';
    private const LIQUIDACIONES_FILE_PATH = 'data/LIQUIDACIONES_PENDIENTES.xlsx';
    private const ACTIVOS_FILE_PATH = 'data/ACTIVOS.xlsx';
    private const DELEGADOS_FILE_PATH = 'data/DELEGADOS.xlsx';
    private const ASAMBLEA_DELEGADOS_FILE_PATH = 'data/ASAMBLEA_DELEGADOS_PROSALUD.xlsx';
    private const COMPENSACIONES_FILE_PATH = 'data/COMPENSACIONES_AFILIADOS_ACTIVOS.xlsx';

    private const PRIMARY_STORAGE_DISK = 'prosalud-private';
    private const FALLBACK_STORAGE_DISK = 'local';
    private const COMPENSACIONES_SHEET_NAME = 'DINAMICA';

    /**
     * Read the incapacidades Excel file.
     */
    public function readIncapacidadesFile(): array
    {
        return $this->withStoredExcel(self::INCAPACIDADES_FILE_PATH, function (string $localPath, string $disk) {
            try {
                $spreadsheet = IOFactory::load($localPath);
                $worksheet = $spreadsheet->getActiveSheet();
                $data = $worksheet->toArray();

                Log::info('Archivo de incapacidades leído exitosamente', [
                    'rows_count' => count($data),
                    'file_path' => self::INCAPACIDADES_FILE_PATH,
                    'disk' => $disk,
                ]);

                return $data;
            } catch (SpreadsheetException $e) {
                Log::error('Error al procesar archivo Excel de incapacidades', [
                    'error' => $e->getMessage(),
                    'file_path' => self::INCAPACIDADES_FILE_PATH,
                    'disk' => $disk,
                ]);

                return [];
            } catch (\Throwable $e) {
                Log::error('Error inesperado al leer archivo de incapacidades', [
                    'error' => $e->getMessage(),
                    'file_path' => self::INCAPACIDADES_FILE_PATH,
                    'disk' => $disk,
                    'trace' => $e->getTraceAsString(),
                ]);

                return [];
            }
        }, []);
    }

    /**
     * Check if the Excel file exists and is readable.
     */
    public function isFileAvailable(): bool
    {
        return $this->storedExcelExists(self::INCAPACIDADES_FILE_PATH);
    }

    /**
     * Read the liquidaciones Excel file.
     */
    public function readLiquidacionesFile(): array
    {
        return $this->withStoredExcel(self::LIQUIDACIONES_FILE_PATH, function (string $localPath, string $disk) {
            try {
                $spreadsheet = IOFactory::load($localPath);
                $worksheet = $spreadsheet->getActiveSheet();
                $data = $worksheet->toArray();

                Log::info('Archivo de liquidaciones leído exitosamente', [
                    'rows_count' => count($data),
                    'file_path' => self::LIQUIDACIONES_FILE_PATH,
                    'disk' => $disk,
                ]);

                return $data;
            } catch (SpreadsheetException $e) {
                Log::error('Error al procesar archivo Excel de liquidaciones', [
                    'error' => $e->getMessage(),
                    'file_path' => self::LIQUIDACIONES_FILE_PATH,
                    'disk' => $disk,
                ]);

                return [];
            } catch (\Throwable $e) {
                Log::error('Error inesperado al leer archivo de liquidaciones', [
                    'error' => $e->getMessage(),
                    'file_path' => self::LIQUIDACIONES_FILE_PATH,
                    'disk' => $disk,
                    'trace' => $e->getTraceAsString(),
                ]);

                return [];
            }
        }, []);
    }

    /**
     * Check if the liquidaciones Excel file exists and is readable.
     */
    public function isLiquidacionesFileAvailable(): bool
    {
        return $this->storedExcelExists(self::LIQUIDACIONES_FILE_PATH);
    }

    /**
     * Get file information.
     */
    public function getFileInfo(): array
    {
        foreach ([self::PRIMARY_STORAGE_DISK, self::FALLBACK_STORAGE_DISK] as $disk) {
            try {
                if (!Storage::disk($disk)->exists(self::INCAPACIDADES_FILE_PATH)) {
                    continue;
                }

                return [
                    'exists' => true,
                    'path' => self::INCAPACIDADES_FILE_PATH,
                    'disk' => $disk,
                    'size' => Storage::disk($disk)->size(self::INCAPACIDADES_FILE_PATH),
                    'modified' => Storage::disk($disk)->lastModified(self::INCAPACIDADES_FILE_PATH),
                    'readable' => true,
                ];
            } catch (\Throwable $e) {
                Log::warning('No se pudo obtener información del archivo de incapacidades', [
                    'disk' => $disk,
                    'file_path' => self::INCAPACIDADES_FILE_PATH,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'exists' => false,
            'path' => self::INCAPACIDADES_FILE_PATH,
        ];
    }

    /**
     * Read the activos Excel file from private bucket (or fallback to local storage).
     */
    public function readActivosFile(): array
    {
        $cacheKey = 'excel:activos:processed';
        
        // Verificar si existe en caché
        $cachedData = Cache::get($cacheKey);
        if ($cachedData !== null) {
            Log::info('[CACHE HIT] Archivo ACTIVOS obtenido desde caché', [
                'cache_key' => $cacheKey,
                'rows_count' => count($cachedData),
            ]);
            return $cachedData;
        }

        Log::info('[CACHE MISS] Leyendo archivo ACTIVOS desde disco', [
            'cache_key' => $cacheKey,
        ]);
        
        $data = Cache::remember($cacheKey, now()->addHours(6), function () {
            return $this->withStoredExcel(self::ACTIVOS_FILE_PATH, function (string $localPath, string $disk) {
            try {
                $spreadsheet = IOFactory::load($localPath);
                $worksheet = $spreadsheet->getActiveSheet();
                $data = $worksheet->toArray();

                Log::info('Archivo de activos leído exitosamente', [
                    'rows_count' => count($data),
                    'file_path' => self::ACTIVOS_FILE_PATH,
                    'disk' => $disk,
                ]);

                return $data;
            } catch (SpreadsheetException $e) {
                Log::error('Error al procesar archivo Excel de activos', [
                    'error' => $e->getMessage(),
                    'file_path' => self::ACTIVOS_FILE_PATH,
                    'disk' => $disk,
                ]);

                return [];
            } catch (\Throwable $e) {
                Log::error('Error inesperado al leer archivo de activos', [
                    'error' => $e->getMessage(),
                    'file_path' => self::ACTIVOS_FILE_PATH,
                    'disk' => $disk,
                    'trace' => $e->getTraceAsString(),
                ]);

                return [];
            }
        }, []);
        });
        
        Log::info('[CACHE STORED] Archivo ACTIVOS guardado en caché', [
            'cache_key' => $cacheKey,
            'rows_count' => count($data),
        ]);
        
        return $data;
    }

    /**
     * Clear cached activos file data.
     */
    public function clearActivosCache(): void
    {
        Cache::forget('excel:activos:processed');
    }

    /**
     * Check if the activos Excel file exists and is readable in private bucket or local disk.
     */
    public function isActivosFileAvailable(): bool
    {
        return $this->storedExcelExists(self::ACTIVOS_FILE_PATH);
    }

    /**
     * Search for a person in the activos file by document type, document number and expedition date.
     */
    public function searchPersonInActivos(string $tipoDocumento, string $documento, string $fechaExpedicion): ?string
    {
        try {
            $data = $this->readActivosFile();

            if (empty($data)) {
                return null;
            }

            // Skip header row (assuming first row is header)
            $rows = array_slice($data, 1);

            foreach ($rows as $row) {
                // Check if row has enough columns
                if (count($row) < 7) {
                    continue;
                }

                $rowTipoDocumento = trim($row[0] ?? '');
                $rowDocumento = trim($row[1] ?? '');
                $rowFechaExpedicion = trim($row[6] ?? ''); // Fecha Expedición is column 6 (0-indexed)

                // Normalize dates for comparison
                $normalizedInputDate = $this->normalizeAssemblyDate($fechaExpedicion);
                $normalizedRowDate = $this->normalizeAssemblyDate($rowFechaExpedicion);

                if ($rowTipoDocumento === $tipoDocumento
                    && $rowDocumento === $documento
                    && $normalizedInputDate === $normalizedRowDate) {
                    // Return the HOSPITAL column (column 5, 0-indexed)
                    return trim($row[5] ?? '');
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error al buscar persona en archivo de activos', [
                'error' => $e->getMessage(),
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
            ]);

            return null;
        }
    }

    /**
     * Search for a person's full name in the activos file by document type and document number.
     */
    public function searchPersonNameInActivos(string $tipoDocumento, string $documento): ?string
    {
        try {
            $data = $this->readActivosFile();

            if (empty($data)) {
                return null;
            }

            // Skip header row (assuming first row is header)
            $rows = array_slice($data, 1);

            foreach ($rows as $row) {
                // Check if row has enough columns
                if (count($row) < 7) {
                    continue;
                }

                $rowTipoDocumento = trim($row[0] ?? '');
                $rowDocumento = trim($row[1] ?? '');

                if ($rowTipoDocumento === $tipoDocumento && $rowDocumento === $documento) {
                    // Try to get name from columns 2 and 3 (nombres and apellidos) or column 2 if it's combined
                    $nombres = trim($row[2] ?? '');
                    $apellidos = trim($row[3] ?? '');

                    // If both columns exist, concatenate them
                    if (!empty($nombres) && !empty($apellidos)) {
                        return trim($nombres . ' ' . $apellidos);
                    }

                    // If only one column has data, return it
                    if (!empty($nombres)) {
                        return $nombres;
                    }

                    if (!empty($apellidos)) {
                        return $apellidos;
                    }

                    // If no name found in expected columns, return null
                    return null;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error al buscar nombre de persona en archivo de activos', [
                'error' => $e->getMessage(),
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
            ]);

            return null;
        }
    }

    /**
     * Read the delegados Excel file.
     */
    public function readDelegadosFile(): array
    {
        return $this->withStoredExcel(self::DELEGADOS_FILE_PATH, function (string $localPath, string $disk) {
            try {
                $spreadsheet = IOFactory::load($localPath);
                $worksheet = $spreadsheet->getActiveSheet();
                $data = $worksheet->toArray();

                Log::info('Archivo de delegados leído exitosamente', [
                    'rows_count' => count($data),
                    'file_path' => self::DELEGADOS_FILE_PATH,
                    'disk' => $disk,
                ]);

                return $data;
            } catch (SpreadsheetException $e) {
                Log::error('Error al procesar archivo Excel de delegados', [
                    'error' => $e->getMessage(),
                    'file_path' => self::DELEGADOS_FILE_PATH,
                    'disk' => $disk,
                ]);

                return [];
            } catch (\Throwable $e) {
                Log::error('Error inesperado al leer archivo de delegados', [
                    'error' => $e->getMessage(),
                    'file_path' => self::DELEGADOS_FILE_PATH,
                    'disk' => $disk,
                    'trace' => $e->getTraceAsString(),
                ]);

                return [];
            }
        }, []);
    }

    /**
     * Check if the delegados Excel file exists and is readable.
     */
    public function isDelegadosFileAvailable(): bool
    {
        return $this->storedExcelExists(self::DELEGADOS_FILE_PATH);
    }

    /**
     * Get all delegados candidates.
     */
    public function getAllDelegados(): array
    {
        try {
            $data = $this->readDelegadosFile();

            if (empty($data)) {
                return [];
            }

            $delegados = [];

            // Process each row, skip the first row if it contains headers
            foreach ($data as $index => $row) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Check if row has enough columns
                if (count($row) < 6) {
                    continue;
                }

                $nombreApellidos = trim($row[0] ?? '');
                $cedula = trim($row[1] ?? '');

                // Skip if this looks like a header row (contains column names)
                if ('NOMBRE Y APELLIDOS' === $nombreApellidos
                    || 'CEDULA' === $cedula
                    || 'SEDE' === $nombreApellidos
                    || 'SEDE' === $cedula) {
                    continue;
                }

                // Only add if has essential data and looks like real data
                if (!empty($nombreApellidos) && !empty($cedula) && is_numeric($cedula)) {
                    $delegado = [
                        'id' => count($delegados) + 1, // Generate ID based on actual data count
                        'nombre_apellidos' => $nombreApellidos,
                        'cedula' => $cedula,
                        'sede' => trim($row[2] ?? ''),
                        'estado_bd_1' => trim($row[3] ?? ''),
                        'proceso' => trim($row[4] ?? ''),
                        'estado_bd_2' => trim($row[5] ?? ''),
                        'avatar_url' => "https://prosalud-vote-hub.lovable.app/avatars/{$cedula}.jpeg",
                    ];

                    $delegados[] = $delegado;
                }
            }

            return $delegados;
        } catch (\Exception $e) {
            Log::error('Error al obtener delegados', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Search delegados by sede (hospital).
     */
    public function getDelegadosBySede(string $sede): array
    {
        try {
            $allDelegados = $this->getAllDelegados();

            return array_filter($allDelegados, function ($delegado) use ($sede) {
                return strtoupper(trim($delegado['sede'])) === strtoupper(trim($sede));
            });
        } catch (\Exception $e) {
            Log::error('Error al buscar delegados por sede', [
                'error' => $e->getMessage(),
                'sede' => $sede,
            ]);

            return [];
        }
    }

    /**
     * Search delegados by cedula.
     */
    public function getDelegadoByCedula(string $cedula): ?array
    {
        try {
            $allDelegados = $this->getAllDelegados();

            foreach ($allDelegados as $delegado) {
                if (trim($delegado['cedula']) === trim($cedula)) {
                    return $delegado;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error al buscar delegado por cédula', [
                'error' => $e->getMessage(),
                'cedula' => $cedula,
            ]);

            return null;
        }
    }

    /**
     * Get delegados grouped by sede.
     */
    public function getDelegadosGroupedBySede(): array
    {
        try {
            $allDelegados = $this->getAllDelegados();
            $grouped = [];

            foreach ($allDelegados as $delegado) {
                $sede = $delegado['sede'];
                if (!isset($grouped[$sede])) {
                    $grouped[$sede] = [];
                }
                $grouped[$sede][] = $delegado;
            }

            return $grouped;
        } catch (\Exception $e) {
            Log::error('Error al agrupar delegados por sede', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Read the asamblea delegados Excel file from public directory.
     */
    public function readAsambleaDelegadosFile(): array
    {
        try {
            $filePath = public_path(self::ASAMBLEA_DELEGADOS_FILE_PATH);
            
            if (!file_exists($filePath)) {
                Log::error('Archivo de asamblea delegados no encontrado', [
                    'file_path' => $filePath,
                ]);
                return [];
            }

            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();

            Log::info('Archivo de asamblea delegados leído exitosamente', [
                'rows_count' => count($data),
                'file_path' => $filePath,
            ]);

            return $data;
        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de asamblea delegados', [
                'error' => $e->getMessage(),
                'file_path' => self::ASAMBLEA_DELEGADOS_FILE_PATH,
            ]);

            return [];
        } catch (\Throwable $e) {
            Log::error('Error inesperado al leer archivo de asamblea delegados', [
                'error' => $e->getMessage(),
                'file_path' => self::ASAMBLEA_DELEGADOS_FILE_PATH,
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Check if the asamblea delegados Excel file exists and is readable.
     */
    public function isAsambleaDelegadosFileAvailable(): bool
    {
        $filePath = public_path(self::ASAMBLEA_DELEGADOS_FILE_PATH);
        return file_exists($filePath) && is_readable($filePath);
    }

    /**
     * Search for an affiliate in the asamblea delegados file by cedula and expedition date.
     * Returns the affiliate's information if found and dates match.
     */
    public function searchAfiliadoInAsamblea(string $cedula, string $fechaExpedicion): ?array
    {
        try {
            $data = $this->readAsambleaDelegadosFile();

            if (empty($data)) {
                return null;
            }

            // Skip header row (assuming first row is header)
            $rows = array_slice($data, 1);

            foreach ($rows as $row) {
                // Check if row has enough columns
                // Columns: CEDULA, NOMBRE Y APELLIDOS, ESTADO BD, F. EXPEDICIÓN
                if (count($row) < 4) {
                    continue;
                }

                $rowCedula = trim($row[0] ?? '');
                $rowNombreApellidos = trim($row[1] ?? '');
                $rowEstadoBD = trim($row[2] ?? '');
                $rowFechaExpedicion = trim($row[3] ?? '');

                // Normalize dates for comparison
                $normalizedInputDate = $this->normalizeAssemblyDate($fechaExpedicion);
                $normalizedRowDate = $this->normalizeAssemblyDate($rowFechaExpedicion);

                if ($rowCedula === $cedula && $normalizedInputDate === $normalizedRowDate) {
                    // Return the affiliate's information
                    return [
                        'cedula' => $rowCedula,
                        'nombre_apellidos' => $rowNombreApellidos,
                        'estado_bd' => $rowEstadoBD,
                        'fecha_expedicion' => $rowFechaExpedicion,
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error al buscar afiliado en archivo de asamblea delegados', [
                'error' => $e->getMessage(),
                'cedula' => $cedula,
                'fecha_expedicion' => $fechaExpedicion,
            ]);

            return null;
        }
    }

    /**
     * Normalize date format for comparison.
     */
    public function normalizeAssemblyDate(string $date): string
    {
        try {
            // Try to parse the date and return in Y-m-d format
            // Format: j/M/Y (e.g., 8/Mar/2017) - abbreviated month name, day without leading zero
            $parsedDate = \DateTime::createFromFormat('j/M/Y', $date);
            if ($parsedDate && $parsedDate->format('j/M/Y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // Format: d/M/Y (e.g., 30/Jun/1993) - abbreviated month name, day with leading zero
            $parsedDate = \DateTime::createFromFormat('d/M/Y', $date);
            if ($parsedDate && $parsedDate->format('d/M/Y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // Format: j/m/Y (e.g., 8/3/2017) - numeric, day without leading zero
            $parsedDate = \DateTime::createFromFormat('j/m/Y', $date);
            if ($parsedDate && $parsedDate->format('j/m/Y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // Format: d/m/Y (e.g., 30/06/1993) - numeric, day with leading zero
            $parsedDate = \DateTime::createFromFormat('d/m/Y', $date);
            if ($parsedDate && $parsedDate->format('d/m/Y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // Format: Y-m-d (e.g., 1993-06-30)
            $parsedDate = \DateTime::createFromFormat('Y-m-d', $date);
            if ($parsedDate && $parsedDate->format('Y-m-d') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // Format: m/d/Y (e.g., 06/30/1993)
            $parsedDate = \DateTime::createFromFormat('m/d/Y', $date);
            if ($parsedDate && $parsedDate->format('m/d/Y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // If none of the formats work, return the original string
            return $date;
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Execute callback with a temporary local copy of the stored Excel file.
     *
     * @template T
     *
     * @param callable(string, string):T $callback
     * @param T|null                     $default
     *
     * @return T|null
     */
    private function withStoredExcel(string $filePath, callable $callback, $default = null)
    {
        $localCopy = $this->getStoredExcelLocalCopy($filePath);
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
     * Create a temporary local copy of an Excel file stored in configured disks.
     *
     * @return array{path: string, disk: string}|null
     */
    private function getStoredExcelLocalCopy(string $filePath): ?array
    {
        $disks = [self::PRIMARY_STORAGE_DISK, self::FALLBACK_STORAGE_DISK];

        foreach ($disks as $disk) {
            try {
                if (!Storage::disk($disk)->exists($filePath)) {
                    continue;
                }

                $stream = Storage::disk($disk)->readStream($filePath);
                if (false === $stream) {
                    Log::warning('No se pudo abrir stream del archivo Excel', [
                        'disk' => $disk,
                        'file_path' => $filePath,
                    ]);
                    continue;
                }

                $tempBasePath = tempnam(sys_get_temp_dir(), 'prosalud_excel_');
                if (false === $tempBasePath) {
                    fclose($stream);
                    Log::error('No se pudo crear archivo temporal para Excel');

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
                    Log::error('No se pudo abrir archivo temporal para escribir Excel', [
                        'file_path' => $tempPath,
                    ]);
                    continue;
                }

                stream_copy_to_stream($stream, $destination);
                fclose($stream);
                fclose($destination);

                return [
                    'path' => $tempPath,
                    'disk' => $disk,
                ];
            } catch (\Throwable $e) {
                Log::error('Error al crear copia local del archivo Excel', [
                    'error' => $e->getMessage(),
                    'disk' => $disk,
                    'file_path' => $filePath,
                ]);
            }
        }

        Log::error('Archivo Excel no encontrado en los discos configurados', [
            'file_path' => $filePath,
            'disks' => $disks,
        ]);

        return null;
    }

    private function storedExcelExists(string $filePath): bool
    {
        if (Storage::disk(self::PRIMARY_STORAGE_DISK)->exists($filePath)) {
            return true;
        }

        if (Storage::disk(self::FALLBACK_STORAGE_DISK)->exists($filePath)) {
            return true;
        }

        return false;
    }

    /**
     * Read the compensaciones Excel file from public directory.
     */
    public function readCompensacionesFile(): array
    {
        try {
            $filePath = public_path(self::COMPENSACIONES_FILE_PATH);
            
            if (!file_exists($filePath)) {
                Log::error('Archivo de compensaciones no encontrado', [
                    'file_path' => $filePath,
                ]);
                return [];
            }

            $spreadsheet = IOFactory::load($filePath);
            
            // Obtener la hoja específica por nombre
            $worksheet = $spreadsheet->getSheetByName(self::COMPENSACIONES_SHEET_NAME);
            
            if ($worksheet === null) {
                Log::error('Hoja "DINAMICA" no encontrada en archivo de compensaciones', [
                    'file_path' => $filePath,
                    'hojas_disponibles' => $spreadsheet->getSheetNames(),
                ]);
                return [];
            }
            
            $data = $worksheet->toArray();

            Log::info('Archivo de compensaciones leído exitosamente', [
                'rows_count' => count($data),
                'file_path' => $filePath,
                'sheet_name' => self::COMPENSACIONES_SHEET_NAME,
            ]);

            return $data;
        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de compensaciones', [
                'error' => $e->getMessage(),
                'file_path' => self::COMPENSACIONES_FILE_PATH,
            ]);

            return [];
        } catch (\Throwable $e) {
            Log::error('Error inesperado al leer archivo de compensaciones', [
                'error' => $e->getMessage(),
                'file_path' => self::COMPENSACIONES_FILE_PATH,
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Check if the compensaciones Excel file exists and is readable.
     */
    public function isCompensacionesFileAvailable(): bool
    {
        $filePath = public_path(self::COMPENSACIONES_FILE_PATH);
        return file_exists($filePath) && is_readable($filePath);
    }

    /**
     * Search for compensation data by documento (row label).
     * Returns compensation data if found: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
     */
    public function buscarCompensacionPorDocumento(string $documento): ?array
    {
        try {
            Log::info('Iniciando búsqueda de compensación por documento', [
                'documento_original' => $documento,
            ]);

            $data = $this->readCompensacionesFile();

            if (empty($data)) {
                Log::warning('Archivo de compensaciones vacío o no se pudo leer', [
                    'documento' => $documento,
                ]);
                return null;
            }

            // Normalizar documento (remover puntos, espacios, etc.)
            $documentoNormalizado = $this->normalizeDocumento($documento);

            Log::info('Buscando documento normalizado en Excel de compensaciones', [
                'documento_original' => $documento,
                'documento_normalizado' => $documentoNormalizado,
                'total_filas' => count($data) - 1, // -1 para excluir header
            ]);

            // Skip header row (assuming first row is header)
            $rows = array_slice($data, 1);
            $rowsRevisadas = 0;
            $muestrasDocumentos = [];

            foreach ($rows as $index => $row) {
                // Check if row has enough columns
                // Columns: Etiquetas de fila (documento), T. Basicos, T. Auxilios, T. Ingresos
                if (count($row) < 4) {
                    continue;
                }

                $rowDocumento = trim($row[0] ?? '');
                $rowDocumentoNormalizado = $this->normalizeDocumento($rowDocumento);

                // Guardar muestras de los primeros documentos para debugging
                if ($index < 5) {
                    $muestrasDocumentos[] = [
                        'original' => $rowDocumento,
                        'normalizado' => $rowDocumentoNormalizado,
                        't_basicos_raw' => $row[1] ?? null,
                        't_auxilios_raw' => $row[2] ?? null,
                        't_ingresos_raw' => $row[3] ?? null,
                    ];
                }

                if ($rowDocumentoNormalizado === $documentoNormalizado) {
                    // Extraer valores numéricos de las columnas
                    $tBasicosRaw = $row[1] ?? 0;
                    $tAuxiliosRaw = $row[2] ?? 0;
                    $tIngresosRaw = $row[3] ?? 0;

                    $tBasicos = $this->normalizeNumericValue($tBasicosRaw);
                    $tAuxilios = $this->normalizeNumericValue($tAuxiliosRaw);
                    $tIngresos = $this->normalizeNumericValue($tIngresosRaw);

                    Log::info('Compensación encontrada en Excel', [
                        'documento' => $documento,
                        'documento_normalizado' => $documentoNormalizado,
                        't_basicos_raw' => $tBasicosRaw,
                        't_basicos' => $tBasicos,
                        't_auxilios_raw' => $tAuxiliosRaw,
                        't_auxilios' => $tAuxilios,
                        't_ingresos_raw' => $tIngresosRaw,
                        't_ingresos' => $tIngresos,
                        'fila_encontrada' => $index + 2, // +2 porque empezamos en índice 0 y saltamos header
                        'rows_revisadas' => $rowsRevisadas + 1,
                    ]);

                    return [
                        't_basicos' => $tBasicos,
                        't_auxilios' => $tAuxilios,
                        't_ingresos' => $tIngresos,
                    ];
                }

                $rowsRevisadas++;
            }

            Log::warning('Documento no encontrado en Excel de compensaciones', [
                'documento' => $documento,
                'documento_normalizado' => $documentoNormalizado,
                'total_rows_revisadas' => $rowsRevisadas,
                'muestras_documentos' => $muestrasDocumentos,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Error al buscar compensación por documento', [
                'error' => $e->getMessage(),
                'documento' => $documento,
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Normaliza un documento removiendo puntos, espacios y caracteres especiales.
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
     * Normaliza un valor numérico removiendo separadores de miles.
     */
    private function normalizeNumericValue($value): int
    {
        if (null === $value || '' === $value) {
            return 0;
        }

        // Si es numérico directo (puede ser float de Excel), retornar
        if (is_numeric($value)) {
            $resultado = (int) round($value);
            return $resultado;
        }

        // Si es string con formato de número (puede tener puntos o comas como separadores de miles)
        $valueString = (string) $value;
        
        // Remover todos los separadores de miles: puntos, comas y espacios
        // Tanto el formato colombiano (puntos) como estadounidense (comas) como formato estándar
        // Usar str_replace para asegurar que se remuevan todos los caracteres
        $normalized = str_replace([',', '.', ' '], '', $valueString);
        
        // También intentar con trim por si acaso
        $normalized = trim($normalized);
        
        if (is_numeric($normalized) && $normalized !== '') {
            $resultado = (int) round((float) $normalized);
            Log::info('Valor numérico normalizado exitosamente', [
                'value_original' => $value,
                'value_string' => $valueString,
                'value_normalized' => $normalized,
                'resultado' => $resultado,
            ]);
            return $resultado;
        }

        Log::warning('No se pudo normalizar valor numérico', [
            'value' => $value,
            'type' => gettype($value),
            'value_string' => $valueString,
            'normalized_attempt' => $normalized ?? null,
        ]);

        return 0;
    }
}
