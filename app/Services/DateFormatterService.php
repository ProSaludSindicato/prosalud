<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DateFormatterService
{
    private const DATE_FIELDS = [
        'fecha recibido',
        'Fecha Incio Incapacidad',
        'Fecha Fin Incapacidad',
        'FECHA ENVIO',
        'Fecha Expedicion',
        'FECHA EXPEDICION',
        'FECHA INGRESO',
        'FECHA RETIRO',
        'FECHA DE ENTREGA A CAMILA',
    ];

    private const INPUT_DATE_FORMAT = 'm/d/Y';
    private const INPUT_ENGLISH_DATE_FORMAT = 'd-M-y';
    private const OUTPUT_DATE_FORMAT = 'd/m/Y';

    /**
     * Check if a field is a date field that needs format conversion.
     */
    public function isDateField(string $fieldName): bool
    {
        return in_array(trim($fieldName), self::DATE_FIELDS);
    }

    /**
     * Convert date format from various formats to DD/MM/YYYY.
     */
    public function convertDateFormat(string $dateString): string
    {
        // Try MM/DD/YYYY format first (most common)
        try {
            $date = Carbon::createFromFormat(self::INPUT_DATE_FORMAT, $dateString);

            return $date->format(self::OUTPUT_DATE_FORMAT);
        } catch (\Exception $e) {
            // Try English format (d-M-y) like "20-Mar-25"
            try {
                $date = Carbon::createFromFormat(self::INPUT_ENGLISH_DATE_FORMAT, $dateString);

                return $date->format(self::OUTPUT_DATE_FORMAT);
            } catch (\Exception $e2) {
                // Log the conversion error but don't break the flow
                Log::warning('Error al convertir formato de fecha', [
                    'input_date' => $dateString,
                    'error_mm_dd' => $e->getMessage(),
                    'error_english' => $e2->getMessage(),
                ]);

                // If all parsing fails, return the original string
                return $dateString;
            }
        }
    }

    /**
     * Convert multiple date fields in a record.
     */
    public function convertRecordDates(array $record): array
    {
        foreach ($record as $field => $value) {
            if ($this->isDateField($field) && !empty($value)) {
                $record[$field] = $this->convertDateFormat($value);
            }
        }

        return $record;
    }

    /**
     * Get list of date fields that will be converted.
     */
    public function getDateFields(): array
    {
        return self::DATE_FIELDS;
    }

    /**
     * Validate if a date string can be converted.
     */
    public function canConvertDate(string $dateString): bool
    {
        // Try MM/DD/YYYY format first
        try {
            Carbon::createFromFormat(self::INPUT_DATE_FORMAT, $dateString);

            return true;
        } catch (\Exception $e) {
            // Try English format
            try {
                Carbon::createFromFormat(self::INPUT_ENGLISH_DATE_FORMAT, $dateString);

                return true;
            } catch (\Exception $e2) {
                return false;
            }
        }
    }
}
