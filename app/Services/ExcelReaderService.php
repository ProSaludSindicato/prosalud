<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExcelReaderService
{
    private const EXCEL_FILE_PATH = 'data/_RELACION INCAPACIDADES 2025.xlsx';
    private const LIQUIDACIONES_FILE_PATH = 'data/LIQUIDACIONES PENDIENTES.xlsx';
    private const ACTIVOS_FILE_PATH = 'data/ACTIVOS.xlsx';
    private const DELEGADOS_FILE_PATH = 'data/DELEGADOS_2025_2.xlsx';
    
    // Disk configuration for ACTIVOS2 file (stored in S3)
    private const ACTIVOS_S3_DISK = 'prosalud-public';
    private const ACTIVOS_FALLBACK_DISK = 'public';

    /**
     * Read the incapacidades Excel file
     */
    public function readIncapacidadesFile(): array
    {
        try {
            $excelPath = public_path(self::EXCEL_FILE_PATH);

            // Check if file exists
            if (!file_exists($excelPath)) {
                Log::error('Archivo de incapacidades no encontrado', [
                    'path' => $excelPath
                ]);
                return [];
            }

            // Load the Excel file
            $spreadsheet = IOFactory::load($excelPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();

            Log::info('Archivo de incapacidades leído exitosamente', [
                'rows_count' => count($data),
                'file_path' => $excelPath
            ]);

            return $data;

        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de incapacidades', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::EXCEL_FILE_PATH)
            ]);
            return [];
        } catch (\Exception $e) {
            Log::error('Error inesperado al leer archivo Excel', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::EXCEL_FILE_PATH)
            ]);
            return [];
        }
    }

    /**
     * Check if the Excel file exists and is readable
     */
    public function isFileAvailable(): bool
    {
        $excelPath = public_path(self::EXCEL_FILE_PATH);
        return file_exists($excelPath) && is_readable($excelPath);
    }

    /**
     * Read the liquidaciones Excel file
     */
    public function readLiquidacionesFile(): array
    {
        try {
            $excelPath = public_path(self::LIQUIDACIONES_FILE_PATH);

            // Check if file exists
            if (!file_exists($excelPath)) {
                Log::error('Archivo de liquidaciones no encontrado', [
                    'path' => $excelPath
                ]);
                return [];
            }

            // Load the Excel file
            $spreadsheet = IOFactory::load($excelPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();

            Log::info('Archivo de liquidaciones leído exitosamente', [
                'rows_count' => count($data),
                'file_path' => $excelPath
            ]);

            return $data;

        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de liquidaciones', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::LIQUIDACIONES_FILE_PATH)
            ]);
            return [];
        } catch (\Exception $e) {
            Log::error('Error inesperado al leer archivo Excel de liquidaciones', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::LIQUIDACIONES_FILE_PATH)
            ]);
            return [];
        }
    }

    /**
     * Check if the liquidaciones Excel file exists and is readable
     */
    public function isLiquidacionesFileAvailable(): bool
    {
        $excelPath = public_path(self::LIQUIDACIONES_FILE_PATH);
        return file_exists($excelPath) && is_readable($excelPath);
    }

    /**
     * Get file information
     */
    public function getFileInfo(): array
    {
        $excelPath = public_path(self::EXCEL_FILE_PATH);

        if (!file_exists($excelPath)) {
            return [
                'exists' => false,
                'path' => $excelPath
            ];
        }

        return [
            'exists' => true,
            'path' => $excelPath,
            'size' => filesize($excelPath),
            'modified' => filemtime($excelPath),
            'readable' => is_readable($excelPath)
        ];
    }

    /**
     * Read the activos Excel file from S3 (or fallback to local storage)
     */
    public function readActivosFile(): array
    {
        try {
            $disk = self::ACTIVOS_S3_DISK;
            $filePath = self::ACTIVOS_FILE_PATH;
            
            // Check if file exists in S3
            if (!Storage::disk($disk)->exists($filePath)) {
                // Fallback to local disk (useful for development)
                $disk = self::ACTIVOS_FALLBACK_DISK;
                if (!Storage::disk($disk)->exists($filePath)) {
                    Log::error('Archivo de activos no encontrado en S3 ni en disco local', [
                        's3_path' => $filePath,
                        's3_disk' => self::ACTIVOS_S3_DISK,
                        'fallback_disk' => $disk
                    ]);
                    return [];
                }
            }
            
            // Get the file content from storage
            $fileContent = Storage::disk($disk)->get($filePath);
            
            // Create a temporary file to load with PhpSpreadsheet
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            file_put_contents($tempPath, $fileContent);
            
            // Load the Excel file
            $spreadsheet = IOFactory::load($tempPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();
            
            // Clean up temporary file
            fclose($tempFile);

            Log::info('Archivo de activos leído exitosamente desde S3', [
                'rows_count' => count($data),
                'file_path' => $filePath,
                'disk' => $disk
            ]);

            return $data;

        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de activos', [
                'error' => $e->getMessage(),
                'file_path' => self::ACTIVOS_FILE_PATH
            ]);
            return [];
        } catch (\Exception $e) {
            Log::error('Error inesperado al leer archivo Excel de activos', [
                'error' => $e->getMessage(),
                'file_path' => self::ACTIVOS_FILE_PATH,
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Check if the activos Excel file exists and is readable in S3 or local disk
     */
    public function isActivosFileAvailable(): bool
    {
        $filePath = self::ACTIVOS_FILE_PATH;
        
        // Check S3 first
        if (Storage::disk(self::ACTIVOS_S3_DISK)->exists($filePath)) {
            return true;
        }
        
        // Fallback to local disk
        if (Storage::disk(self::ACTIVOS_FALLBACK_DISK)->exists($filePath)) {
            return true;
        }
        
        return false;
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
        try {
            $excelPath = public_path(self::DELEGADOS_FILE_PATH);

            // Check if file exists
            if (!file_exists($excelPath)) {
                Log::error('Archivo de delegados no encontrado', [
                    'path' => $excelPath
                ]);
                return [];
            }

            // Load the Excel file
            $spreadsheet = IOFactory::load($excelPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();

            Log::info('Archivo de delegados leído exitosamente', [
                'rows_count' => count($data),
                'file_path' => $excelPath
            ]);

            return $data;

        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de delegados', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::DELEGADOS_FILE_PATH)
            ]);
            return [];
        } catch (\Exception $e) {
            Log::error('Error inesperado al leer archivo Excel de delegados', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::DELEGADOS_FILE_PATH)
            ]);
            return [];
        }
    }

    /**
     * Check if the delegados Excel file exists and is readable
     */
    public function isDelegadosFileAvailable(): bool
    {
        $excelPath = public_path(self::DELEGADOS_FILE_PATH);
        return file_exists($excelPath) && is_readable($excelPath);
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
