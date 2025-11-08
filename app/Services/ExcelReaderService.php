<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExcelReaderService
{
    private const INCAPACIDADES_FILE_PATH = 'data/RELACION_INCAPACIDADES.xlsx';
    private const LIQUIDACIONES_FILE_PATH = 'data/LIQUIDACIONES_PENDIENTES.xlsx';
    private const ACTIVOS_FILE_PATH = 'data/ACTIVOS.xlsx';
    private const DELEGADOS_FILE_PATH = 'data/DELEGADOS.xlsx';
    
    private const PRIMARY_STORAGE_DISK = 'prosalud-private';
    private const FALLBACK_STORAGE_DISK = 'local';

    /**
     * Read the incapacidades Excel file
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
     * Check if the Excel file exists and is readable
     */
    public function isFileAvailable(): bool
    {
        return $this->storedExcelExists(self::INCAPACIDADES_FILE_PATH);
    }

    /**
     * Read the liquidaciones Excel file
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
     * Check if the liquidaciones Excel file exists and is readable
     */
    public function isLiquidacionesFileAvailable(): bool
    {
        return $this->storedExcelExists(self::LIQUIDACIONES_FILE_PATH);
    }

    /**
     * Get file information
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
     * Read the activos Excel file from private bucket (or fallback to local storage)
     */
    public function readActivosFile(): array
    {
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
    }

    /**
     * Check if the activos Excel file exists and is readable in private bucket or local disk
     */
    public function isActivosFileAvailable(): bool
    {
        return $this->storedExcelExists(self::ACTIVOS_FILE_PATH);
    }

    /**
     * Search for a person in the activos file by document type, document number and expedition date
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
                $normalizedInputDate = $this->normalizeDate($fechaExpedicion);
                $normalizedRowDate = $this->normalizeDate($rowFechaExpedicion);

                if ($rowTipoDocumento === $tipoDocumento &&
                    $rowDocumento === $documento &&
                    $normalizedInputDate === $normalizedRowDate) {

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
                'fecha_expedicion' => $fechaExpedicion
            ]);
            return null;
        }
    }

    /**
     * Search for a person's full name in the activos file by document type and document number
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
                'documento' => $documento
            ]);
            return null;
        }
    }

    /**
     * Normalize date format for comparison
     */
    private function normalizeDate(string $date): string
    {
        try {
            // Try to parse the date and return in Y-m-d format
            $parsedDate = \DateTime::createFromFormat('d/m/Y', $date);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            $parsedDate = \DateTime::createFromFormat('Y-m-d', $date);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            $parsedDate = \DateTime::createFromFormat('m/d/Y', $date);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            // If none of the formats work, return the original string
            return $date;
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Read the delegados Excel file
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
     * Check if the delegados Excel file exists and is readable
     */
    public function isDelegadosFileAvailable(): bool
    {
        return $this->storedExcelExists(self::DELEGADOS_FILE_PATH);
    }

    /**
     * Execute callback with a temporary local copy of the stored Excel file.
     *
     * @template T
     * @param string $filePath
     * @param callable(string, string):T $callback
     * @param T|null $default
     * @return T|null
     */
    private function withStoredExcel(string $filePath, callable $callback, $default = null)
    {
        $localCopy = $this->getStoredExcelLocalCopy($filePath);
        if ($localCopy === null) {
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
                if ($stream === false) {
                    Log::warning('No se pudo abrir stream del archivo Excel', [
                        'disk' => $disk,
                        'file_path' => $filePath,
                    ]);
                    continue;
                }

                $tempBasePath = tempnam(sys_get_temp_dir(), 'prosalud_excel_');
                if ($tempBasePath === false) {
                    fclose($stream);
                    Log::error('No se pudo crear archivo temporal para Excel');
                    return null;
                }

                $tempPath = $tempBasePath . '.xlsx';
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
     * Get all delegados candidates
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
                if ($nombreApellidos === 'NOMBRE Y APELLIDOS' ||
                    $cedula === 'CEDULA' ||
                    $nombreApellidos === 'SEDE' ||
                    $cedula === 'SEDE') {
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
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Search delegados by sede (hospital)
     */
    public function getDelegadosBySede(string $sede): array
    {
        try {
            $allDelegados = $this->getAllDelegados();

            return array_filter($allDelegados, function($delegado) use ($sede) {
                return strtoupper(trim($delegado['sede'])) === strtoupper(trim($sede));
            });

        } catch (\Exception $e) {
            Log::error('Error al buscar delegados por sede', [
                'error' => $e->getMessage(),
                'sede' => $sede
            ]);
            return [];
        }
    }

    /**
     * Search delegados by cedula
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
                'cedula' => $cedula
            ]);
            return null;
        }
    }

    /**
     * Get delegados grouped by sede
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
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
