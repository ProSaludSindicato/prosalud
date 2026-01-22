<?php

namespace App\Services;

use Illuminate\Support\Facades\{Cache, Log};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\{Exception as SpreadsheetException, IOFactory};

class KitBienestarService
{
    private const EXCEL_FILE_PATH = 'resources/templates/INFORMACION_PARA_KIT_ESCOLARES.xlsx';

    // Column indexes (0-based)
    private const COL_CC = 0; // Número de documento
    private const COL_NOMBRE = 1;
    private const COL_HOSPITAL = 2;
    private const COL_FECHA_EXPEDICION = 3;
    private const COL_BENEFICIARIO = 4;
    private const COL_PARENTESCO = 5;
    private const COL_EDAD = 6;

    /**
     * Authenticate and get kit bienestar information.
     * Validates by documento (CC) and fecha_expedicion (dd/mm/aa format).
     * Does NOT validate tipo_documento.
     */
    public function authenticateAndGetKitBienestar(
        string $documento,
        string $fechaExpedicion,
    ): ?array {
        Log::info('[KIT BIENESTAR SERVICE] Iniciando autenticación', [
            'documento' => $documento,
            'fecha_expedicion' => $fechaExpedicion,
        ]);

        // Normalizar valores para la clave de caché
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        $cacheKey = sprintf(
            'kit_bienestar:auth:%s:%s',
            md5($normalizedDocumento ?? ''),
            md5($normalizedFechaExpedicion ?? '')
        );

        Log::debug('[KIT BIENESTAR SERVICE] Clave de caché generada', [
            'cache_key' => $cacheKey,
            'documento' => $documento,
        ]);

        // Verificar si existe en caché
        $cachedData = Cache::tags(['kit_bienestar'])->get($cacheKey);
        if ($cachedData !== null) {
            Log::info('[CACHE HIT] Kit bienestar obtenido desde caché', [
                'cache_key' => $cacheKey,
                'documento' => $documento,
            ]);
            return $cachedData;
        }

        Log::info('[CACHE MISS] Consultando kit bienestar desde Excel', [
            'cache_key' => $cacheKey,
            'documento' => $documento,
        ]);

        $result = Cache::tags(['kit_bienestar'])->remember($cacheKey, now()->addHours(24), function () use ($documento, $fechaExpedicion) {
            return $this->withExcelFile(function (string $excelPath) use ($documento, $fechaExpedicion) {
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

                    $spreadsheet = $reader->load($excelPath);

                    // Obtener la primera hoja (asumimos que es la única hoja o la principal)
                    $sheet = $spreadsheet->getActiveSheet();

                    if (!$sheet) {
                        Log::error('Hoja no encontrada en archivo de kits escolares');
                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);
                        return null;
                    }

                    $kitBienestarRowsResult = $this->findKitBienestarRows(
                        $sheet,
                        $documento,
                        $fechaExpedicion
                    );

                    if (empty($kitBienestarRowsResult)) {
                        Log::info('Kit bienestar no encontrado', [
                            'documento' => $documento,
                            'fecha_expedicion' => $fechaExpedicion,
                            'normalized' => [
                                'documento' => $this->normalizeValue($documento),
                                'fecha_expedicion' => $this->normalizeDate($fechaExpedicion),
                            ],
                        ]);

                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);
                        return null;
                    }

                    // Extract info from all matching rows
                    $kitBienestar = [];
                    foreach ($kitBienestarRowsResult as $rowData) {
                        $kitBienestar[] = $this->extractKitBienestarInfo($rowData);
                    }

                    // If only one record, return it directly (backward compatibility)
                    // If multiple records, return array with all records
                    if (count($kitBienestar) === 1) {
                        $kitBienestar = $kitBienestar[0];
                    } else {
                        // Add metadata for multiple records
                        $kitBienestar = [
                            'afiliado' => [
                                'cc' => $kitBienestar[0]['cc'],
                                'nombre' => $kitBienestar[0]['nombre'],
                                'hospital' => $kitBienestar[0]['hospital'],
                                'fecha_expedicion' => $kitBienestar[0]['fecha_expedicion'],
                            ],
                            'beneficiarios' => array_map(function($record) {
                                return [
                                    'beneficiario' => $record['beneficiario'],
                                    'parentesco' => $record['parentesco'],
                                    'edad' => $record['edad'],
                                ];
                            }, $kitBienestar),
                        ];
                    }

                    // Liberar memoria explícitamente
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return $kitBienestar;
                } catch (SpreadsheetException $e) {
                    Log::error('Error al procesar archivo Excel de kits escolares', [
                        'error' => $e->getMessage(),
                        'file_path' => self::EXCEL_FILE_PATH,
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return null;
                } catch (\Throwable $e) {
                    Log::error('Error inesperado al leer archivo Excel de kits escolares', [
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
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
            Log::info('[CACHE STORED] Kit bienestar guardado en caché', [
                'cache_key' => $cacheKey,
                'documento' => $documento,
                'ttl_hours' => 24,
            ]);
        }

        return $result;
    }

    /**
     * Check if the Excel file exists and is readable.
     */
    public function isFileAvailable(): bool
    {
        $filePath = base_path(self::EXCEL_FILE_PATH);
        return file_exists($filePath) && is_readable($filePath);
    }

    /**
     * Execute a callback with the Excel file path.
     * Since the file is in resources/templates, we can use it directly.
     *
     * @template T
     *
     * @param callable(string):T $callback
     * @param T|null                     $default
     *
     * @return T|null
     */
    private function withExcelFile(callable $callback, $default = null)
    {
        $filePath = base_path(self::EXCEL_FILE_PATH);

        if (!file_exists($filePath) || !is_readable($filePath)) {
            Log::error('Archivo de kits escolares no encontrado o no es legible', [
                'file_path' => $filePath,
            ]);
            return $default;
        }

        try {
            return $callback($filePath);
        } catch (\Throwable $e) {
            Log::error('Error al procesar archivo de kits escolares', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
            ]);
            return $default;
        }
    }

    /**
     * Find all rows matching the documento and fecha_expedicion.
     * Returns array of row data arrays (can have multiple rows if same person has multiple beneficiaries).
     */
    private function findKitBienestarRows(
        $sheet,
        string $documento,
        string $fechaExpedicion,
    ): array {
        // Normalizar valores de entrada
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        $matchingRows = [];

        // Get highest row to know when to stop
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
            // Read CC column (documento)
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_CC + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);

            // Read FECHA EXPEDICION column
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_FECHA_EXPEDICION + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowFechaExpedicionRaw = $this->getCellValue($cell);
            $rowFechaExpedicion = $this->normalizeDate($rowFechaExpedicionRaw);

            // Skip if row appears empty
            if (empty($rowDocumento) && empty($rowFechaExpedicion)) {
                continue;
            }

            // Check if this looks like a header row
            if ($rowDocumento && (
                false !== stripos($rowDocumento, 'cc')
                || false !== stripos($rowDocumento, 'documento')
                || false !== stripos($rowDocumento, 'número')
            )) {
                continue;
            }

            // Match authentication criteria (documento and fecha_expedicion)
            if ($rowDocumento === $normalizedDocumento
                && $rowFechaExpedicion === $normalizedFechaExpedicion) {
                // Match found! Read the complete row
                $rowData = [];
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

                for ($colIndex = 0; $colIndex < $highestColumnIndex; ++$colIndex) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter . $rowIndex);
                    $rowData[] = $this->getCellValue($cell);
                }

                $matchingRows[] = $rowData;
            }
        }

        return $matchingRows;
    }

    /**
     * Extract kit bienestar information from a row.
     */
    private function extractKitBienestarInfo(array $rowData): array
    {
        return [
            'cc' => $this->normalizeValue($rowData[self::COL_CC] ?? ''),
            'nombre' => $this->normalizeValue($rowData[self::COL_NOMBRE] ?? ''),
            'hospital' => $this->normalizeValue($rowData[self::COL_HOSPITAL] ?? ''),
            'fecha_expedicion' => $this->normalizeDate($rowData[self::COL_FECHA_EXPEDICION] ?? ''),
            'beneficiario' => $this->normalizeValue($rowData[self::COL_BENEFICIARIO] ?? ''),
            'parentesco' => $this->normalizeValue($rowData[self::COL_PARENTESCO] ?? ''),
            'edad' => $this->normalizeValue($rowData[self::COL_EDAD] ?? ''),
        ];
    }

    /**
     * Get cell value handling different data types (dates, numbers, strings).
     */
    private function getCellValue($cell)
    {
        $value = $cell->getCalculatedValue();

        // Handle DateTime objects from Excel dates
        if ($value instanceof \DateTime) {
            return $value->format('d/m/y');
        }

        // Handle numeric values (convert to string to maintain consistency)
        if (is_numeric($value) && !is_string($value)) {
            return (string) $value;
        }

        return $value;
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
     * Normalize date format for comparison.
     * Handles dd/mm/aa format (as specified in requirements).
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

        // If already in dd/mm/yy format, normalize it
        if (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $dateStr)) {
            return $dateStr;
        }

        // Try to parse various date formats
        try {
            // Try dd/mm/yy format (as specified in requirements)
            $parsedDate = \DateTime::createFromFormat('d/m/y', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('d/m/y');
            }

            // Try dd/mm/yyyy format
            $parsedDate = \DateTime::createFromFormat('d/m/Y', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('d/m/y');
            }

            // Try YYYY-MM-DD format
            $parsedDate = \DateTime::createFromFormat('Y-m-d', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('d/m/y');
            }

            // Try Excel serial date (numeric)
            if (is_numeric($dateStr)) {
                $excelBaseDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $dateStr);
                return $excelBaseDate->format('d/m/y');
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
}

