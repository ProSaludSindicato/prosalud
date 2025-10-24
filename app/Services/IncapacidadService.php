<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class IncapacidadService
{
    public function __construct(
        private ExcelReaderService $excelReader,
        private DateFormatterService $dateFormatter
    ) {}

    /**
     * Search for disability records by document criteria
     */
    public function searchByDocument(string $tipo, string $numeroDocumento, string $fechaExpedicion): array
    {
        try {
            $excelData = $this->excelReader->readIncapacidadesFile();

            if (empty($excelData)) {
                return [
                    'status' => 'error',
                    'message' => 'No se pudo leer el archivo de incapacidades.'
                ];
            }

            $matches = $this->findMatchingRecords($excelData, $tipo, $numeroDocumento, $fechaExpedicion);

            if (empty($matches)) {
                return [
                    'status' => 'not_found',
                    'message' => 'No se encontraron incapacidades registradas para el documento especificado.'
                ];
            }

            $formattedMatches = $this->formatRecordDates($matches);

            return [
                'status' => 'success',
                'data' => $formattedMatches
            ];

        } catch (\Exception $e) {
            Log::error('Error en búsqueda de incapacidades', [
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
                'fecha_expedicion' => $fechaExpedicion,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => 'Error interno del servidor: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Find records that match the search criteria
     */
    private function findMatchingRecords(array $excelData, string $tipo, string $numeroDocumento, string $fechaExpedicion): array
    {
        $headers = array_shift($excelData);
        $matches = [];

        foreach ($excelData as $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            $rowTipo = $row[2] ?? '';
            $rowNumeroDocumento = $row[3] ?? '';
            $rowFechaExpedicion = $row[4] ?? ''; // Fecha Expedicion is at index 4

            // Check if document type and number match
            if (trim($rowTipo) === trim($tipo) && trim($rowNumeroDocumento) === trim($numeroDocumento)) {
                // Validate date if provided
                if (!empty($fechaExpedicion) && !empty($rowFechaExpedicion)) {
                    if (!$this->validateDateMatch($fechaExpedicion, $rowFechaExpedicion)) {
                        continue; // Skip this record if dates don't match
                    }
                }

                $record = [];
                foreach ($headers as $index => $header) {
                    $record[trim($header)] = $row[$index] ?? '';
                }
                $matches[] = $record;
            }
        }

        return $matches;
    }

    /**
     * Format dates in the records and filter internal fields
     */
    private function formatRecordDates(array $records): array
    {
        return array_map(function ($record) {
            $filteredRecord = $this->filterInternalFields($record);

            foreach ($filteredRecord as $field => $value) {
                if ($this->dateFormatter->isDateField($field) && !empty($value)) {
                    $filteredRecord[$field] = $this->dateFormatter->convertDateFormat($value);
                }
            }

            return $filteredRecord;
        }, $records);
    }

    /**
     * Filter out internal fields that should not be exposed in the API
     */
    private function filterInternalFields(array $record): array
    {
        $internalFields = [
            'REPORTE FACTURA',
            'REPORTE VIVI'
        ];

        return array_filter($record, function ($value, $key) use ($internalFields) {
            return !in_array(trim($key), $internalFields);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Validate if two dates match, handling different formats
     */
    private function validateDateMatch(string $requestDate, string $excelDate): bool
    {
        try {
            // Request date is in Y-m-d format (e.g., "2025-01-01")
            $requestCarbon = \Carbon\Carbon::createFromFormat('Y-m-d', $requestDate);
            
            // Excel date could be in various formats (e.g., "1/1/2025", "01/01/2025")
            $excelCarbon = $this->parseExcelDate($excelDate);
            
            if (!$excelCarbon) {
                Log::warning('No se pudo parsear fecha del Excel', [
                    'excel_date' => $excelDate,
                    'request_date' => $requestDate
                ]);
                return false;
            }
            
            // Compare dates (ignore time)
            return $requestCarbon->format('Y-m-d') === $excelCarbon->format('Y-m-d');
            
        } catch (\Exception $e) {
            Log::warning('Error al validar fechas', [
                'request_date' => $requestDate,
                'excel_date' => $excelDate,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Parse Excel date from various possible formats
     */
    private function parseExcelDate(string $dateString): ?\Carbon\Carbon
    {
        $formats = [
            'm/d/Y',      // 1/1/2025
            'm/d/y',      // 1/1/25
            'd/m/Y',      // 1/1/2025 (European format)
            'd/m/y',      // 1/1/25 (European format)
            'Y-m-d',      // 2025-01-01
            'd-M-y',      // 1-Jan-25
            'd-M-Y',      // 1-Jan-2025
        ];

        foreach ($formats as $format) {
            try {
                $date = \Carbon\Carbon::createFromFormat($format, $dateString);
                if ($date) {
                    return $date;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }
}
