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

            $matches = $this->findMatchingRecords($excelData, $tipo, $numeroDocumento);

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
}
