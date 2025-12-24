<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\{Exception as SpreadsheetException, IOFactory};

class UploadCompensacionesFileRequest extends FormRequest
{
    private const REQUIRED_SHEET_NAME = 'DINAMICA';
    private const MIN_REQUIRED_COLUMNS = 4; // documento, T. Basicos, T. Auxilios, T. Ingresos
    private const MIN_DATA_ROWS = 1; // Al menos una fila de datos además del header

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:10240', // 10MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'El archivo es obligatorio.',
            'file.file' => 'Debe ser un archivo válido.',
            'file.mimes' => 'El archivo debe ser un Excel (.xlsx o .xls).',
            'file.max' => 'El archivo no puede ser mayor a 10MB.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $file = $this->file('file');
            
            if (!$file) {
                return;
            }

            try {
                // Validar que el archivo Excel sea válido
                $spreadsheet = IOFactory::load($file->getRealPath());
                
                // Validar que existe la hoja "DINAMICA"
                $worksheet = $spreadsheet->getSheetByName(self::REQUIRED_SHEET_NAME);
                
                if (!$worksheet) {
                    $availableSheets = $spreadsheet->getSheetNames();
                    $validator->errors()->add(
                        'file',
                        "El archivo debe contener una hoja llamada 'DINAMICA'. Hojas encontradas: " . implode(', ', $availableSheets)
                    );
                    return;
                }

                // Validar estructura de datos
                $data = $worksheet->toArray();
                
                if (count($data) < self::MIN_DATA_ROWS + 1) { // +1 para el header
                    $validator->errors()->add(
                        'file',
                        'El archivo debe contener al menos una fila de datos además del encabezado.'
                    );
                    return;
                }

                // Validar que las columnas necesarias existen
                // Verificar que hay al menos MIN_REQUIRED_COLUMNS columnas en la primera fila de datos
                $firstDataRow = $data[1] ?? []; // Primera fila de datos (índice 1, después del header)
                
                if (count($firstDataRow) < self::MIN_REQUIRED_COLUMNS) {
                    $validator->errors()->add(
                        'file',
                        "El archivo debe contener al menos " . self::MIN_REQUIRED_COLUMNS . " columnas: documento, T. Basicos, T. Auxilios, T. Ingresos."
                    );
                    return;
                }

                // Validar que la primera columna (documento) no esté vacía en al menos una fila
                $hasValidData = false;
                for ($i = 1; $i < count($data); $i++) {
                    $row = $data[$i];
                    if (!empty($row[0])) {
                        $hasValidData = true;
                        break;
                    }
                }

                if (!$hasValidData) {
                    $validator->errors()->add(
                        'file',
                        'El archivo debe contener al menos una fila con un documento válido.'
                    );
                    return;
                }

            } catch (SpreadsheetException $e) {
                Log::warning('Archivo de compensaciones inválido', [
                    'error' => $e->getMessage(),
                    'filename' => $file->getClientOriginalName(),
                    'ip_address' => $this->ip(),
                ]);

                $validator->errors()->add(
                    'file',
                    'El archivo Excel no es válido o está corrupto: ' . $e->getMessage()
                );
            } catch (\Throwable $e) {
                Log::error('Error inesperado al validar archivo de compensaciones', [
                    'error' => $e->getMessage(),
                    'filename' => $file->getClientOriginalName(),
                    'ip_address' => $this->ip(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $validator->errors()->add(
                    'file',
                    'Error al validar el archivo: ' . $e->getMessage()
                );
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        Log::warning('Validación fallida en upload de compensaciones', [
            'errors' => $validator->errors()->toArray(),
            'ip_address' => $this->ip(),
        ]);

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Errores de validación',
            'errors' => $validator->errors()
        ], 422));
    }
}

