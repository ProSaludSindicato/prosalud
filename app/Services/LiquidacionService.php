<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LiquidacionService
{
    public function __construct(
        private ExcelReaderService $excelReader,
        private DateFormatterService $dateFormatter
    ) {}

    /**
     * Search for liquidation records by document criteria
     */
    public function searchByDocument(string $tipo, string $numeroDocumento, string $fechaExpedicion): array
    {
        $startTime = microtime(true);

        try {
            Log::info('Iniciando búsqueda en archivo de liquidaciones', [
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
                'fecha_expedicion' => $fechaExpedicion,
            ]);

            $excelData = $this->excelReader->readLiquidacionesFile();

            if (empty($excelData)) {
                Log::warning('Archivo de liquidaciones vacío o no encontrado', [
                    'tipo' => $tipo,
                    'numero_documento' => $numeroDocumento,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'No se pudo leer el archivo de liquidaciones.'
                ];
            }

            Log::info('Archivo de liquidaciones leído exitosamente', [
                'total_rows' => count($excelData),
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
            ]);

            $matches = $this->findMatchingRecords($excelData, $tipo, $numeroDocumento, $fechaExpedicion);

            if (empty($matches)) {
                Log::info('No se encontraron registros coincidentes', [
                    'tipo' => $tipo,
                    'numero_documento' => $numeroDocumento,
                    'fecha_expedicion' => $fechaExpedicion,
                ]);

                return [
                    'status' => 'not_found',
                    'message' => 'No se encontraron liquidaciones registradas para el documento especificado.'
                ];
            }

            $formattedMatches = $this->formatRecordDates($matches);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Búsqueda de liquidaciones completada exitosamente', [
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
                'records_found' => count($formattedMatches),
                'execution_time_ms' => $executionTime,
            ]);

            return [
                'status' => 'success',
                'data' => $formattedMatches
            ];

        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Error en búsqueda de liquidaciones', [
                'tipo' => $tipo,
                'numero_documento' => $numeroDocumento,
                'fecha_expedicion' => $fechaExpedicion,
                'execution_time_ms' => $executionTime,
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

            $rowTipo = $row[0] ?? ''; // TIPO DE DOCUMENTO
            $rowNumeroDocumento = $row[1] ?? ''; // N° DOCUMENTO
            $rowFechaExpedicion = $row[2] ?? ''; // FECHA EXPEDICION

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
            'FECHA DE ENTREGA A CAMILA',
            'RESPONSABLE DE ENTREGA CONTABILIDAD',
            'REVISION',
            'FORMATO DE REVISION FISICO'
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
            $requestCarbon = \Carbon\Carbon::createFromFormat('Y-m-d', $requestDate);

            $excelCarbon = $this->parseExcelDate($excelDate);

            if (!$excelCarbon) {
                Log::warning('No se pudo parsear fecha del Excel', [
                    'excel_date' => $excelDate,
                    'request_date' => $requestDate
                ]);
                return false;
            }

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
