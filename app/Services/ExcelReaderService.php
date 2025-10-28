<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use Illuminate\Support\Facades\Log;

class ExcelReaderService
{
    private const EXCEL_FILE_PATH = 'data/_RELACION INCAPACIDADES 2025.xlsx';
    private const LIQUIDACIONES_FILE_PATH = 'data/LIQUIDACIONES PENDIENTES.xlsx';
    private const ACTIVOS_FILE_PATH = 'data/ACTIVOS.xlsx';

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
     * Read the activos Excel file
     */
    public function readActivosFile(): array
    {
        try {
            $excelPath = public_path(self::ACTIVOS_FILE_PATH);

            // Check if file exists
            if (!file_exists($excelPath)) {
                Log::error('Archivo de activos no encontrado', [
                    'path' => $excelPath
                ]);
                return [];
            }

            // Load the Excel file
            $spreadsheet = IOFactory::load($excelPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();

            Log::info('Archivo de activos leído exitosamente', [
                'rows_count' => count($data),
                'file_path' => $excelPath
            ]);

            return $data;

        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de activos', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::ACTIVOS_FILE_PATH)
            ]);
            return [];
        } catch (\Exception $e) {
            Log::error('Error inesperado al leer archivo Excel de activos', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::ACTIVOS_FILE_PATH)
            ]);
            return [];
        }
    }

    /**
     * Check if the activos Excel file exists and is readable
     */
    public function isActivosFileAvailable(): bool
    {
        $excelPath = public_path(self::ACTIVOS_FILE_PATH);
        return file_exists($excelPath) && is_readable($excelPath);
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
}
