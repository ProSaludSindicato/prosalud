<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use Illuminate\Support\Facades\Log;

class ExcelReaderService
{
    private const EXCEL_FILE_PATH = 'data/_RELACION INCAPACIDADES 2025.xlsx';

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
}
