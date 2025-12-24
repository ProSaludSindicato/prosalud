<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class IncapacidadService
{
    public function __construct(
        private ExcelReaderService $excelReader,
        private DateFormatterService $dateFormatter,
        private AfiliadoService $afiliadoService,
    ) {
    }

    /**
     * Search for disability records by document criteria.
     */
    public function searchByDocument(string $tipo, string $numeroDocumento, string $fechaExpedicion): array
    {
        $startTime = microtime(true);

        try {
            Log::info('Iniciando búsqueda en archivo de incapacidades', [
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
                'fecha_expedicion' => $fechaExpedicion,
            ]);

            $excelData = $this->excelReader->readIncapacidadesFile();

            if (empty($excelData)) {
                Log::warning('Archivo de incapacidades vacío o no encontrado', [
                    'tipo' => $tipo,
                    'numero_documento' => $numeroDocumento,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'No se pudo leer el archivo de incapacidades.',
                ];
            }

            Log::info('Archivo de incapacidades leído exitosamente', [
                'total_rows' => count($excelData),
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
            ]);

            // First, validate the document and date against PROSANET_INFORMACION_AFILIADOS.xlsx
            $afiliadoInfo = $this->afiliadoService->authenticateAndGetAfiliado($tipo, $numeroDocumento, $fechaExpedicion);
            
            if (null === $afiliadoInfo) {
                Log::warning('Validación fallida: documento y/o fecha de expedición no coinciden con PROSANET_INFORMACION_AFILIADOS.xlsx', [
                    'tipo' => $tipo,
                    'numero_documento' => $numeroDocumento,
                    'fecha_expedicion' => $fechaExpedicion,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'El documento o la fecha de expedición no son válidos. Por favor, verifique la información proporcionada.',
                ];
            }

            // If validation passes, search for matching records in the incapacidades file
            $matches = $this->findMatchingRecords($excelData, $tipo, $numeroDocumento);

            if (empty($matches)) {
                Log::info('No se encontraron registros coincidentes', [
                    'tipo' => $tipo,
                    'numero_documento' => $numeroDocumento,
                    'fecha_expedicion' => $fechaExpedicion,
                ]);

                return [
                    'status' => 'not_found',
                    'message' => 'No se encontraron incapacidades registradas para el documento especificado.',
                ];
            }

            $formattedMatches = $this->formatRecordDates($matches);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Búsqueda de incapacidades completada exitosamente', [
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
                'records_found' => count($formattedMatches),
                'execution_time_ms' => $executionTime,
            ]);

            return [
                'status' => 'success',
                'data' => $formattedMatches,
            ];
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Error en búsqueda de incapacidades', [
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
                'fecha_expedicion' => $fechaExpedicion,
                'execution_time_ms' => $executionTime,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Error interno del servidor: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Find records that match the search criteria.
     * Note: Date validation is done before calling this method.
     */
    private function findMatchingRecords(array $excelData, string $tipo, string $numeroDocumento): array
    {
        $headers = array_shift($excelData);
        $matches = [];

        foreach ($excelData as $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            $rowTipo = $row[2] ?? '';
            $rowNumeroDocumento = $row[3] ?? '';

            // Check if document type and number match
            if (trim($rowTipo) === trim($tipo) && trim($rowNumeroDocumento) === trim($numeroDocumento)) {
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
     * Format dates in the records and filter internal fields.
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
     * Filter out internal fields that should not be exposed in the API.
     */
    private function filterInternalFields(array $record): array
    {
        $internalFields = [
            'REPORTE FACTURA',
            'REPORTE VIVI',
            'valor Incapacidad Recibido',
        ];

        return array_filter($record, function ($value, $key) use ($internalFields) {
            return !in_array(trim($key), $internalFields);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Validate if two dates match, handling different formats.
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
                    'request_date' => $requestDate,
                ]);

                return false;
            }

            // Compare dates (ignore time)
            return $requestCarbon->format('Y-m-d') === $excelCarbon->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('Error al validar fechas', [
                'request_date' => $requestDate,
                'excel_date' => $excelDate,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Parse Excel date from various possible formats.
     */
    private function parseExcelDate(string $dateString): ?\Carbon\Carbon
    {
        $formats = [
            'm/d/Y',
            'm/d/y',
            'd/m/Y',
            'd/m/y',
            'Y-m-d',
            'd-M-y',
            'd-M-Y',
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
