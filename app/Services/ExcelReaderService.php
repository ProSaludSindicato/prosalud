<?php

namespace App\Services;

use App\Models\Assembly;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetExcelDate;

class ExcelReaderService
{
    private const INCAPACIDADES_FILE_PATH = 'data/RELACION_INCAPACIDADES.xlsx';

    private const LIQUIDACIONES_FILE_PATH = 'data/LIQUIDACIONES_PENDIENTES.xlsx';

    private const ACTIVOS_FILE_PATH = 'data/ACTIVOS.xlsx';

    private const DELEGADOS_FILE_PATH = 'data/DELEGADOS.xlsx';

    private const COMPENSACIONES_FILE_PATH = 'data/COMPENSACIONES_AFILIADOS_ACTIVOS.xlsx';

    private const DELEGADOS_AVATARS_DIRECTORY = 'delegados/avatars';

    private const DELEGADOS_AVATARS_PUBLIC_DISK = 'prosalud-public';

    private const DELEGADOS_AVATARS_FALLBACK_DISK = 'public';

    private const DELEGADOS_AVATARS_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const PRIMARY_STORAGE_DISK = 'prosalud-private';

    private const FALLBACK_STORAGE_DISK = 'local';

    private const COMPENSACIONES_SHEET_NAME = 'DINAMICA';

    /**
     * Normalized header label => semantic field. Optional: estado_bd_1, estado_bd_2.
     * Unknown headers (columnas extra) se ignoran.
     *
     * @var array<string, string>
     */
    private const DELEGADOS_HEADER_SEMANTICS = [
        'NOMBRE Y APELLIDOS' => 'nombre_apellidos',
        'CEDULA' => 'cedula',
        'SEDE' => 'sede',
        'PROCESO' => 'proceso',
        'ESTADO BD 1' => 'estado_bd_1',
        'ESTADO BD 2' => 'estado_bd_2',
    ];

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
                if (! Storage::disk($disk)->exists(self::INCAPACIDADES_FILE_PATH)) {
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
     * Clear cached compensaciones file data.
     */
    public function clearCompensacionesCache(): void
    {
        Cache::forget('excel:compensaciones:dinamica');
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

                if ($rowTipoDocumento === $tipoDocumento
                    && $rowDocumento === $documento
                    && $this->assemblyNormalizedDatesEquivalent($fechaExpedicion, $rowFechaExpedicion)) {
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
                    if (! empty($nombres) && ! empty($apellidos)) {
                        return trim($nombres.' '.$apellidos);
                    }

                    // If only one column has data, return it
                    if (! empty($nombres)) {
                        return $nombres;
                    }

                    if (! empty($apellidos)) {
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
     * Validate a delegados upload (same columns and non-empty data as {@see getAllDelegados()}).
     *
     * @param  array<int, array<int, mixed>>  $data
     */
    public function validateDelegadosUploadSheetData(array $data): ?string
    {
        if ($data === []) {
            return 'El archivo Excel está vacío.';
        }

        if (count($data) < 2) {
            return 'El archivo debe incluir al menos una fila de encabezados y una fila de datos.';
        }

        $headerRow = $data[0];
        $map = $this->mapDelegadosHeaderRowToIndices($headerRow);
        if ($map === null) {
            return 'La primera fila debe incluir los encabezados obligatorios (sin duplicados): Nombre y apellidos, Cédula, Sede y Proceso. Estado BD 1 y Estado BD 2 son opcionales. El orden y las columnas adicionales no importan.';
        }

        if ($this->countDelegadosDataRowsFromSheet($data, $map) === 0) {
            return 'No existe ninguna fila de candidato válida: revise que haya nombre completo y cédula numérica en cada fila de datos.';
        }

        return null;
    }

    /**
     * @param  array<int, array<int, mixed>>  $data
     * @param  array<string, int>  $map
     */
    private function countDelegadosDataRowsFromSheet(array $data, array $map): int
    {
        $count = 0;
        foreach (array_slice($data, 1) as $row) {
            if ($this->parseDelegadoDataRow($row, $map) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int>|null
     */
    private function mapDelegadosHeaderRowToIndices(array $headerRow): ?array
    {
        $indices = [];
        foreach ($headerRow as $colIndex => $cell) {
            $normalized = $this->normalizeDelegadosHeaderCell($cell);
            if ($normalized === '') {
                continue;
            }

            $semantic = self::DELEGADOS_HEADER_SEMANTICS[$normalized] ?? null;
            if ($semantic === null) {
                continue;
            }

            if (isset($indices[$semantic])) {
                return null;
            }

            $indices[$semantic] = (int) $colIndex;
        }

        foreach (['nombre_apellidos', 'cedula', 'sede', 'proceso'] as $required) {
            if (! isset($indices[$required])) {
                return null;
            }
        }

        return $indices;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     * @return array{nombre_apellidos: string, cedula: string, sede: string, proceso: string, estado_bd_1: string, estado_bd_2: string}|null
     */
    private function parseDelegadoDataRow(array $row, array $map): ?array
    {
        if (empty(array_filter($row, static function ($v): bool {
            return $v !== null && trim((string) $v) !== '';
        }))) {
            return null;
        }

        $nombreApellidos = trim((string) ($row[$map['nombre_apellidos']] ?? ''));
        $cedula = trim((string) ($row[$map['cedula']] ?? ''));

        if ($this->normalizeDelegadosHeaderCell($nombreApellidos) === 'NOMBRE Y APELLIDOS'
            && $this->normalizeDelegadosHeaderCell($cedula) === 'CEDULA') {
            return null;
        }

        if ($nombreApellidos === '' || $cedula === '' || ! is_numeric($cedula)) {
            return null;
        }

        return [
            'nombre_apellidos' => $nombreApellidos,
            'cedula' => $cedula,
            'sede' => trim((string) ($row[$map['sede']] ?? '')),
            'proceso' => trim((string) ($row[$map['proceso']] ?? '')),
            'estado_bd_1' => isset($map['estado_bd_1']) ? trim((string) ($row[$map['estado_bd_1']] ?? '')) : '',
            'estado_bd_2' => isset($map['estado_bd_2']) ? trim((string) ($row[$map['estado_bd_2']] ?? '')) : '',
        ];
    }

    private function normalizeDelegadosHeaderCell(mixed $value): string
    {
        $s = trim((string) $value);
        if ($s === '') {
            return '';
        }

        $s = mb_strtoupper($s, 'UTF-8');
        $s = strtr($s, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
        $collapsed = preg_replace('/\s+/', ' ', $s);

        return trim(is_string($collapsed) ? $collapsed : '');
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

            $avatarMapByCedula = $this->buildDelegadosAvatarMap();
            $delegados = [];

            $headerRow = $data[0] ?? [];
            $map = $this->mapDelegadosHeaderRowToIndices($headerRow);
            if ($map === null) {
                Log::warning('Archivo de delegados: primera fila sin encabezados reconocibles', [
                    'file_path' => self::DELEGADOS_FILE_PATH,
                ]);

                return [];
            }

            foreach (array_slice($data, 1) as $row) {
                $parsed = $this->parseDelegadoDataRow($row, $map);
                if ($parsed === null) {
                    continue;
                }

                $cedula = $parsed['cedula'];
                $delegados[] = [
                    'id' => count($delegados) + 1,
                    'nombre_apellidos' => $parsed['nombre_apellidos'],
                    'cedula' => $cedula,
                    'sede' => $parsed['sede'],
                    'estado_bd_1' => $parsed['estado_bd_1'],
                    'proceso' => $parsed['proceso'],
                    'estado_bd_2' => $parsed['estado_bd_2'],
                    'avatar_url' => $avatarMapByCedula[$cedula] ?? null,
                ];
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

    private function buildDelegadosAvatarMap(): array
    {
        $avatarMap = [];
        foreach ([self::DELEGADOS_AVATARS_PUBLIC_DISK, self::DELEGADOS_AVATARS_FALLBACK_DISK] as $disk) {
            try {
                if (! Storage::disk($disk)->exists(self::DELEGADOS_AVATARS_DIRECTORY)) {
                    continue;
                }

                $files = Storage::disk($disk)->files(self::DELEGADOS_AVATARS_DIRECTORY);
                foreach ($files as $filePath) {
                    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    if (! in_array($extension, self::DELEGADOS_AVATARS_ALLOWED_EXTENSIONS, true)) {
                        continue;
                    }

                    $cedula = pathinfo($filePath, PATHINFO_FILENAME);
                    if (! ctype_digit($cedula)) {
                        continue;
                    }

                    if (array_key_exists($cedula, $avatarMap)) {
                        continue;
                    }

                    $avatarMap[$cedula] = Storage::disk($disk)->url($filePath);
                }

                if (! empty($avatarMap)) {
                    return $avatarMap;
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo construir mapa de avatares de delegados', [
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $avatarMap;
    }

    /**
     * Search delegados by sede (hospital).
     */
    public function getDelegadosBySede(string $sede): array
    {
        try {
            $allDelegados = $this->getAllDelegados();

            return array_values(array_filter($allDelegados, function ($delegado) use ($sede) {
                return strtoupper(trim($delegado['sede'])) === strtoupper(trim($sede));
            }));
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
                if (! isset($grouped[$sede])) {
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
     * Read delegates Excel from private storage path (per assembly).
     *
     * @return array<int, array<int, mixed>>
     */
    public function readAssemblyDelegatesFromStoredPath(string $relativePath, string $preferredDisk): array
    {
        return $this->withStoredExcelPreferDisk($relativePath, $preferredDisk, function (string $localPath) {
            try {
                $spreadsheet = IOFactory::load($localPath);
                $worksheet = $spreadsheet->getActiveSheet();

                return $worksheet->toArray();
            } catch (SpreadsheetException $e) {
                Log::error('Error al procesar Excel de delegados de asamblea', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            } catch (\Throwable $e) {
                Log::error('Error inesperado al leer Excel de delegados de asamblea', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return [];
            }
        }, []);
    }

    /**
     * Whether the active assembly has a delegates file configured and present on storage.
     */
    public function isAsambleaDelegadosFileAvailable(?Assembly $assembly = null): bool
    {
        $assembly = $assembly ?? Assembly::getCurrent();
        if (! $assembly || ! $assembly->delegates_file_path) {
            return false;
        }

        $preferredDisk = $assembly->delegates_file_disk ?: self::PRIMARY_STORAGE_DISK;

        return $this->storedExcelExistsOnPreferredDisk($assembly->delegates_file_path, $preferredDisk);
    }

    /**
     * Search for an affiliate in the current assembly's delegates file by cédula and expedition date.
     * Returns the affiliate's information if found and dates match.
     */
    public function searchAfiliadoInAsamblea(string $cedula, string $fechaExpedicion, ?Assembly $assembly = null): ?array
    {
        try {
            $assembly = $assembly ?? Assembly::getCurrent();
            if (! $assembly || ! $assembly->delegates_file_path) {
                Log::warning('Asamblea delegados: no hay asamblea activa o falta ruta de archivo de delegados', [
                    'assembly_id' => $assembly?->id,
                    'delegates_file_path' => $assembly?->delegates_file_path,
                ]);

                return null;
            }

            $preferredDisk = $assembly->delegates_file_disk ?: self::PRIMARY_STORAGE_DISK;
            $data = $this->readAssemblyDelegatesFromStoredPath($assembly->delegates_file_path, $preferredDisk);

            if (empty($data)) {
                Log::warning('Asamblea delegados: lectura del Excel devolvió vacío', [
                    'assembly_id' => $assembly->id,
                    'delegates_file_path' => $assembly->delegates_file_path,
                    'disk' => $preferredDisk,
                ]);

                return null;
            }

            try {
                $columnMap = AssemblyDelegateColumnResolver::resolveOrFail($data[0] ?? []);
            } catch (\InvalidArgumentException $e) {
                Log::error('Encabezados inválidos en archivo de delegados de asamblea', [
                    'error' => $e->getMessage(),
                    'assembly_id' => $assembly->id,
                ]);

                return null;
            }

            $rows = array_slice($data, 1);
            $headerRow = $data[0] ?? [];
            $normalizedSoughtDoc = $this->normalizeAssemblyDocumentForMatch($cedula);
            $normalizedInputDate = $this->normalizeAssemblyDate(trim($fechaExpedicion));

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $rowCedula = $this->normalizeAssemblyDocumentForMatch(
                    $this->assemblyDelegateCellToString($row[$columnMap->cedula] ?? null)
                );
                $rowNombreApellidos = $this->assemblyDelegateCellToString($row[$columnMap->nombreApellidos] ?? null);
                $rowEstadoBD = $this->assemblyDelegateCellToString($row[$columnMap->estadoBd] ?? null);
                $rowSede = $columnMap->sede !== null
                    ? $this->assemblyDelegateCellToString($row[$columnMap->sede] ?? null)
                    : '';
                $rowProceso = $columnMap->proceso !== null
                    ? $this->assemblyDelegateCellToString($row[$columnMap->proceso] ?? null)
                    : '';

                if ($rowCedula === '') {
                    continue;
                }

                if ($rowCedula !== $normalizedSoughtDoc) {
                    continue;
                }

                $dateCandidates = $this->assemblyDelegateExpeditionDateCandidates($row, $columnMap);
                $rowFechaExpedicion = $this->assemblyDelegateCellToString($row[$columnMap->fechaExpedicion] ?? null);
                $matchedDateToken = null;

                foreach ($dateCandidates as $candidate) {
                    $asString = $this->delegateExcelCellToComparableDateString($candidate);
                    if ($asString !== '' && $this->assemblyNormalizedDatesEquivalent($asString, $normalizedInputDate)) {
                        $matchedDateToken = is_scalar($candidate) ? (string) $candidate : $asString;
                        break;
                    }
                }

                if ($matchedDateToken === null) {
                    continue;
                }

                if ($rowFechaExpedicion === '') {
                    $rowFechaExpedicion = $matchedDateToken;
                }

                $payload = [
                    'cedula' => $rowCedula,
                    'nombre_apellidos' => $rowNombreApellidos,
                    'estado_bd' => $rowEstadoBD,
                    'fecha_expedicion' => $rowFechaExpedicion,
                ];

                if ($rowSede !== '') {
                    $payload['sede'] = $rowSede;
                }

                if ($rowProceso !== '') {
                    $payload['proceso'] = $rowProceso;
                }

                return $payload;
            }

            $this->logAssemblyDelegateSearchMissDiagnostics(
                $assembly,
                $columnMap,
                $headerRow,
                $rows,
                $cedula,
                $normalizedSoughtDoc,
                $fechaExpedicion,
                $normalizedInputDate
            );

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
     * Extrae texto legible de una celda PhpSpreadsheet (RichText, número entero “grande”, etc.).
     */
    private function assemblyDelegateCellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof RichText) {
            return trim(str_replace("\xC2\xA0", ' ', $value->getPlainText()));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            $n = (float) $value;
            if ($this->looksLikeExcelSerialDate($n)) {
                return (string) $n;
            }

            if ($n == round($n) && abs($n) <= 1e15) {
                return (string) (int) round($n);
            }

            return trim(str_replace("\xC2\xA0", ' ', (string) $value));
        }

        return trim(str_replace("\xC2\xA0", ' ', (string) $value));
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @param  array<int, array<int, mixed>|mixed>  $rows
     */
    private function logAssemblyDelegateSearchMissDiagnostics(
        Assembly $assembly,
        AssemblyDelegateColumnMap $columnMap,
        array $headerRow,
        array $rows,
        string $cedulaInput,
        string $normalizedSoughtDoc,
        string $fechaExpedicionInput,
        string $normalizedInputDate,
    ): void {
        $sampleCedulas = [];
        $documentMatchedRows = [];
        $rowIndex = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $rowIndex++;

                continue;
            }

            $rawCedulaCell = $row[$columnMap->cedula] ?? null;
            $rowCedula = $this->normalizeAssemblyDocumentForMatch(
                $this->assemblyDelegateCellToString($rawCedulaCell)
            );

            if (count($sampleCedulas) < 20 && $rowCedula !== '') {
                $sampleCedulas[] = [
                    'fila_excel' => $rowIndex + 2,
                    'documento_normalizado' => $rowCedula,
                    'celda_tipo' => is_object($rawCedulaCell) ? $rawCedulaCell::class : gettype($rawCedulaCell),
                    'celda_valor_bruto' => is_scalar($rawCedulaCell)
                        ? (string) $rawCedulaCell
                        : '(no escalar)',
                ];
            }

            if ($rowCedula === $normalizedSoughtDoc) {
                $dateCandidates = $this->assemblyDelegateExpeditionDateCandidates($row, $columnMap);
                $detail = [];
                foreach ($dateCandidates as $cand) {
                    $coerced = $this->delegateExcelCellToComparableDateString($cand);
                    $detail[] = [
                        'fragmento' => is_scalar($cand) ? (string) $cand : get_debug_type($cand),
                        'coercido' => $coerced,
                        'candidatos_Ymd' => $coerced !== '' ? $this->assemblyDateNormalizationCandidatesForMatch($coerced) : [],
                    ];
                }

                $documentMatchedRows[] = [
                    'fila_excel' => $rowIndex + 2,
                    'nombre_columna' => $this->assemblyDelegateCellToString($row[$columnMap->nombreApellidos] ?? null),
                    'candidatos_fecha' => $detail,
                    'fecha_esperada_normalizada' => $normalizedInputDate,
                    'columna_fecha_cruda' => $this->assemblyDelegateCellToString($row[$columnMap->fechaExpedicion] ?? null),
                    'columna_sede_cruda' => $columnMap->sede !== null
                        ? $this->assemblyDelegateCellToString($row[$columnMap->sede] ?? null)
                        : null,
                    'columna_proceso_cruda' => $columnMap->proceso !== null
                        ? $this->assemblyDelegateCellToString($row[$columnMap->proceso] ?? null)
                        : null,
                ];
            }

            $rowIndex++;
        }

        $headersForLog = [];
        foreach ($headerRow as $i => $h) {
            $headersForLog[$i] = $this->assemblyDelegateCellToString($h);
        }

        Log::warning('Asamblea delegados: búsqueda sin coincidencia (diagnóstico detallado)', [
            'assembly_id' => $assembly->id,
            'delegates_file_path' => $assembly->delegates_file_path,
            'delegates_file_disk' => $assembly->delegates_file_disk,
            'indices_columnas' => [
                'cedula' => $columnMap->cedula,
                'nombre' => $columnMap->nombreApellidos,
                'sede' => $columnMap->sede,
                'estado_bd' => $columnMap->estadoBd,
                'proceso' => $columnMap->proceso,
                'fecha_expedicion' => $columnMap->fechaExpedicion,
            ],
            'encabezados_por_indice' => $headersForLog,
            'busqueda' => [
                'documento_original' => $cedulaInput,
                'documento_normalizado' => $normalizedSoughtDoc,
                'fecha_original' => $fechaExpedicionInput,
                'fecha_normalizada' => $normalizedInputDate,
            ],
            'filas_datos_escaneadas' => count($rows),
            'muestra_primeras_cedulas_en_archivo' => $sampleCedulas,
            'filas_con_mismo_documento_fecha_no_coincide' => $documentMatchedRows,
        ]);
    }

    /**
     * @param  array<int, mixed>  $row
     * @return list<mixed>
     */
    private function assemblyDelegateExpeditionDateCandidates(array $row, AssemblyDelegateColumnMap $columnMap): array
    {
        $seen = [];
        $ordered = [];

        $push = function (mixed $raw) use (&$seen, &$ordered): void {
            if ($raw === null || $raw === '') {
                return;
            }
            foreach ($this->splitDelegateCellLines($raw) as $piece) {
                if ($piece === '' || $piece === null) {
                    continue;
                }
                $key = is_scalar($piece) ? (string) $piece : serialize($piece);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $ordered[] = $piece;
                }
            }
        };

        $push($row[$columnMap->fechaExpedicion] ?? '');
        if ($columnMap->sede !== null) {
            $push($row[$columnMap->sede] ?? '');
        }
        if ($columnMap->proceso !== null) {
            $push($row[$columnMap->proceso] ?? '');
        }

        return $ordered;
    }

    /**
     * @return list<string|int|float>
     */
    private function splitDelegateCellLines(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_int($value) || is_float($value)) {
            return [$value];
        }

        $s = trim(str_replace("\xC2\xA0", ' ', (string) $value));
        if ($s === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $s);
        if ($lines === false) {
            return [$s];
        }

        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out !== [] ? $out : [$s];
    }

    private function delegateExcelCellToComparableDateString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_int($value) || is_float($value)) {
            $n = (float) $value;
            if ($this->looksLikeExcelSerialDate($n)) {
                try {
                    return SpreadsheetExcelDate::excelToDateTimeObject($n)->format('Y-m-d');
                } catch (\Throwable) {
                    return '';
                }
            }
        }

        $s = trim(str_replace("\xC2\xA0", ' ', (string) $value));
        if ($s !== '' && is_numeric($s)) {
            $n = (float) $s;
            if ($this->looksLikeExcelSerialDate($n)) {
                try {
                    return SpreadsheetExcelDate::excelToDateTimeObject($n)->format('Y-m-d');
                } catch (\Throwable) {
                    return $s;
                }
            }
        }

        return $s;
    }

    private function looksLikeExcelSerialDate(float $n): bool
    {
        return $n >= 200 && $n < 1200000;
    }

    private function normalizeAssemblyDocumentForMatch(string $documento): string
    {
        $d = str_replace(["\xC2\xA0", ' '], '', trim($documento));
        if ($d !== '' && is_numeric($d)) {
            return (string) (int) round((float) $d);
        }

        return $d;
    }

    /**
     * Interpretaciones posibles en Y-m-d para comparar fechas (Excel US m/d vs CO d/m cuando ambos ≤ 12).
     *
     * @return list<string>
     */
    public function assemblyDateNormalizationCandidatesForMatch(string $date): array
    {
        $date = trim(str_replace("\xC2\xA0", ' ', $date));
        if ($date === '') {
            return [];
        }

        /** @var array<string, bool> $ymdKeys */
        $ymdKeys = [];

        $pushYmd = function (string $ymd) use (&$ymdKeys): void {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
                $ymdKeys[$ymd] = true;
            }
        };

        $norm = $this->normalizeAssemblyDate($date);
        $pushYmd($norm);

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            if ($a <= 12 && $b <= 12) {
                $us = \DateTime::createFromFormat('n/j/Y', $date);
                if ($us instanceof \DateTimeInterface && $us->format('n/j/Y') === $date) {
                    $pushYmd($us->format('Y-m-d'));
                }
                $eu = \DateTime::createFromFormat('j/n/Y', $date);
                if ($eu instanceof \DateTimeInterface && $eu->format('j/n/Y') === $date) {
                    $pushYmd($eu->format('Y-m-d'));
                }
            }
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/', $date, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            if ($a <= 12 && $b <= 12) {
                $us = \DateTime::createFromFormat('n/j/y', $date);
                if ($us instanceof \DateTimeInterface && $us->format('n/j/y') === $date) {
                    $pushYmd($us->format('Y-m-d'));
                }
                $eu = \DateTime::createFromFormat('j/n/y', $date);
                if ($eu instanceof \DateTimeInterface && $eu->format('j/n/y') === $date) {
                    $pushYmd($eu->format('Y-m-d'));
                }
            }
        }

        return array_keys($ymdKeys);
    }

    public function assemblyNormalizedDatesEquivalent(string $left, string $right): bool
    {
        $a = $this->assemblyDateNormalizationCandidatesForMatch($left);
        $b = $this->assemblyDateNormalizationCandidatesForMatch($right);

        foreach ($a as $ymd) {
            if (in_array($ymd, $b, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize date format for comparison.
     */
    public function normalizeAssemblyDate(string $date): string
    {
        try {
            $date = trim(str_replace("\xC2\xA0", ' ', $date));
            if ($date === '') {
                return $date;
            }

            // Format: Y-m-d (e.g., 1993-06-30) — común desde formularios HTML
            $parsedDate = \DateTime::createFromFormat('Y-m-d', $date);
            if ($parsedDate && $parsedDate->format('Y-m-d') === $date) {
                return $parsedDate->format('Y-m-d');
            }

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

            // d/m/y, j/m/y — año de dos dígitos antes que d/m/Y (evita 8/4/16 → año 0016)
            $parsedDate = \DateTime::createFromFormat('d/m/y', $date);
            if ($parsedDate && $parsedDate->format('d/m/y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            $parsedDate = \DateTime::createFromFormat('j/n/y', $date);
            if ($parsedDate && $parsedDate->format('j/n/y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // Format: j/n/Y (e.g., 8/3/2017) — día y mes sin ceros a la izquierda
            $parsedDate = \DateTime::createFromFormat('j/n/Y', $date);
            if ($parsedDate && $parsedDate->format('j/n/Y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // Format: d/m/Y (e.g., 30/06/1993) - numeric, day with leading zero, año 4 dígitos
            $parsedDate = \DateTime::createFromFormat('d/m/Y', $date);
            if ($parsedDate && $parsedDate->format('d/m/Y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            // US-style from Excel (e.g. 1/14/2002) — validación estricta para no confundir con d/m
            $parsedDate = \DateTime::createFromFormat('n/j/Y', $date);
            if ($parsedDate && $parsedDate->format('n/j/Y') === $date) {
                return $parsedDate->format('Y-m-d');
            }

            $parsedDate = \DateTime::createFromFormat('n/j/y', $date);
            if ($parsedDate && $parsedDate->format('n/j/y') === $date) {
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
     * @param  callable(string, string):T  $callback
     * @param  T|null  $default
     * @return T|null
     */
    private function withStoredExcel(string $filePath, callable $callback, $default = null)
    {
        return $this->withStoredExcelPreferDisk($filePath, self::PRIMARY_STORAGE_DISK, $callback, $default);
    }

    /**
     * @template T
     *
     * @param  callable(string, string):T  $callback
     * @param  T|null  $default
     * @return T|null
     */
    private function withStoredExcelPreferDisk(string $filePath, string $preferredDisk, callable $callback, $default = null)
    {
        $localCopy = $this->getStoredExcelLocalCopyPreferDisk($filePath, $preferredDisk);
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
     * Create a temporary local copy of an Excel file stored in configured disks.
     *
     * @return array{path: string, disk: string}|null
     */
    private function getStoredExcelLocalCopy(string $filePath): ?array
    {
        return $this->getStoredExcelLocalCopyPreferDisk($filePath, self::PRIMARY_STORAGE_DISK);
    }

    /**
     * Try preferred disk first, then the other configured disk.
     *
     * @return array{path: string, disk: string}|null
     */
    private function getStoredExcelLocalCopyPreferDisk(string $filePath, string $preferredDisk): ?array
    {
        $copy = $this->getStoredExcelLocalCopyForSingleDisk($filePath, $preferredDisk);
        if ($copy !== null) {
            return $copy;
        }

        $otherDisk = $preferredDisk === self::PRIMARY_STORAGE_DISK
            ? self::FALLBACK_STORAGE_DISK
            : self::PRIMARY_STORAGE_DISK;

        return $this->getStoredExcelLocalCopyForSingleDisk($filePath, $otherDisk);
    }

    /**
     * @return array{path: string, disk: string}|null
     */
    private function getStoredExcelLocalCopyForSingleDisk(string $filePath, string $disk): ?array
    {
        try {
            if (! Storage::disk($disk)->exists($filePath)) {
                return null;
            }

            $stream = Storage::disk($disk)->readStream($filePath);
            if ($stream === false) {
                Log::warning('No se pudo abrir stream del archivo Excel', [
                    'disk' => $disk,
                    'file_path' => $filePath,
                ]);

                return null;
            }

            $tempBasePath = tempnam(sys_get_temp_dir(), 'prosalud_excel_');
            if ($tempBasePath === false) {
                fclose($stream);
                Log::error('No se pudo crear archivo temporal para Excel');

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
                Log::error('No se pudo abrir archivo temporal para escribir Excel', [
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
            Log::error('Error al crear copia local del archivo Excel', [
                'error' => $e->getMessage(),
                'disk' => $disk,
                'file_path' => $filePath,
            ]);

            return null;
        }
    }

    private function storedExcelExistsOnPreferredDisk(string $filePath, string $preferredDisk): bool
    {
        if (Storage::disk($preferredDisk)->exists($filePath)) {
            return true;
        }

        $otherDisk = $preferredDisk === self::PRIMARY_STORAGE_DISK
            ? self::FALLBACK_STORAGE_DISK
            : self::PRIMARY_STORAGE_DISK;

        return Storage::disk($otherDisk)->exists($filePath);
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
     * Read the compensaciones Excel file from private storage (or fallback to local).
     *
     * NOTA: No se cachea el contenido completo del archivo para evitar problemas de memoria.
     * El archivo se lee directamente desde storage cada vez que se necesita.
     * Solo se cachea un indicador de disponibilidad del archivo por un tiempo corto.
     */
    public function readCompensacionesFile(): array
    {
        // Cachear solo un indicador de disponibilidad, no el contenido completo
        // Esto evita consumir toda la memoria con archivos grandes
        $availabilityCacheKey = 'excel:compensaciones:available';
        $isAvailable = Cache::remember($availabilityCacheKey, now()->addHours(1), function () {
            return $this->storedExcelExists(self::COMPENSACIONES_FILE_PATH);
        });

        if (! $isAvailable) {
            Log::warning('Archivo de compensaciones no disponible', [
                'file_path' => self::COMPENSACIONES_FILE_PATH,
            ]);

            return [];
        }

        // Leer directamente desde storage sin cachear el contenido
        // Esto evita problemas de memoria con archivos grandes
        return $this->withStoredExcel(self::COMPENSACIONES_FILE_PATH, function (string $localPath, string $disk) {
            try {
                $spreadsheet = IOFactory::load($localPath);

                // Obtener la hoja específica por nombre
                $worksheet = $spreadsheet->getSheetByName(self::COMPENSACIONES_SHEET_NAME);

                if ($worksheet === null) {
                    Log::error('Hoja "DINAMICA" no encontrada en archivo de compensaciones', [
                        'file_path' => $localPath,
                        'disk' => $disk,
                        'hojas_disponibles' => $spreadsheet->getSheetNames(),
                    ]);

                    return [];
                }

                $data = $worksheet->toArray();

                Log::info('Archivo de compensaciones leído exitosamente (sin caché)', [
                    'rows_count' => count($data),
                    'file_path' => self::COMPENSACIONES_FILE_PATH,
                    'disk' => $disk,
                    'sheet_name' => self::COMPENSACIONES_SHEET_NAME,
                ]);

                return $data;
            } catch (SpreadsheetException $e) {
                Log::error('Error al procesar archivo Excel de compensaciones', [
                    'error' => $e->getMessage(),
                    'file_path' => self::COMPENSACIONES_FILE_PATH,
                    'disk' => $disk,
                ]);

                return [];
            } catch (\Throwable $e) {
                Log::error('Error inesperado al leer archivo de compensaciones', [
                    'error' => $e->getMessage(),
                    'file_path' => self::COMPENSACIONES_FILE_PATH,
                    'disk' => $disk,
                    'trace' => $e->getTraceAsString(),
                ]);

                return [];
            }
        }, []);
    }

    /**
     * Check if the compensaciones Excel file exists and is readable.
     */
    public function isCompensacionesFileAvailable(): bool
    {
        return $this->storedExcelExists(self::COMPENSACIONES_FILE_PATH);
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

            // Reintentar lectura del archivo de compensaciones en caso de fallos intermitentes
            $data = [];
            $maxAttempts = 3;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $data = $this->readCompensacionesFile();

                if (! empty($data)) {
                    break;
                }

                Log::warning('Archivo de compensaciones vacío o no se pudo leer, reintento', [
                    'documento' => $documento,
                    'attempt' => $attempt,
                ]);

                // Pequeña espera entre intentos para dar tiempo a recuperación de I/O/storage
                usleep(200000); // 200ms
            }

            if (empty($data)) {
                Log::error('No se pudo leer el archivo de compensaciones después de varios intentos', [
                    'documento' => $documento,
                    'max_attempts' => $maxAttempts,
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
        if ($value === null || $value === '') {
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
        if ($value === null || $value === '') {
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
