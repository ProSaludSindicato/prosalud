<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UpdateWellnessEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'required', 'string', 'max:255'],
            'attendees' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'gift' => ['sometimes', 'nullable', 'string', 'max:255'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_visible' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'nullable', 'array'],
            'images.*' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'], // 5MB max
            'attendance_list' => ['sometimes', 'nullable', 'file', 'mimes:pdf,xls,xlsx', 'max:10240'], // 10MB max
        ];
    }

    public function messages(): array
    {
        return [
            // title
            'title.required' => 'El título es obligatorio cuando se envía.',
            'title.string' => 'El título debe ser un texto válido.',
            'title.max' => 'El título no puede exceder 255 caracteres.',

            // date
            'date.required' => 'La fecha es obligatoria cuando se envía.',
            'date.date' => 'La fecha no tiene un formato válido.',

            // category
            'category.required' => 'La categoría es obligatoria cuando se envía.',
            'category.string' => 'La categoría debe ser un texto válido.',
            'category.max' => 'La categoría no puede exceder 255 caracteres.',

            // description
            'description.string' => 'La descripción debe ser un texto válido.',

            // location
            'location.required' => 'La ubicación es obligatoria cuando se envía.',
            'location.string' => 'La ubicación debe ser un texto válido.',
            'location.max' => 'La ubicación no puede exceder 255 caracteres.',

            // attendees
            'attendees.integer' => 'El número de asistentes debe ser un número entero.',
            'attendees.min' => 'El número de asistentes no puede ser negativo.',

            // gift
            'gift.string' => 'El obsequio debe ser un texto válido.',
            'gift.max' => 'El obsequio no puede exceder 255 caracteres.',

            // provider
            'provider.string' => 'El proveedor debe ser un texto válido.',
            'provider.max' => 'El proveedor no puede exceder 255 caracteres.',

            // is_visible
            'is_visible.boolean' => 'El campo de visibilidad debe ser verdadero o falso.',

            // attendance_list
            'attendance_list.file' => 'El listado de asistencia debe ser un archivo válido.',
            'attendance_list.mimes' => 'El listado de asistencia debe ser de tipo: pdf, xls, xlsx.',
            'attendance_list.max' => 'El listado de asistencia no puede exceder 10MB.',
        ];
    }

    protected function prepareForValidation()
    {
        $allData = $this->all();
        $inputData = $this->input();
        $contentType = $this->header('Content-Type', '');
        
        Log::info('UpdateWellnessEventRequest - Datos recibidos', [
            'method' => $this->method(),
            'content_type' => $contentType,
            'raw_data' => $allData,
            'json_data' => $this->json()->all(),
            'input_data' => $inputData,
            'has_files' => $this->hasFile('images') || $this->hasFile('attendance_list'),
            'files_count' => count($this->file('images', [])),
            'has_attendance_list_file' => $this->hasFile('attendance_list'),
            'request_body' => substr($this->getContent(), 0, 500), // Limit log size
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);

        // If input is empty but we have multipart/form-data, try to parse it manually
        if (empty($inputData) && str_contains($contentType, 'multipart/form-data')) {
            $parsedData = $this->parseMultipartFormData();
            if (!empty($parsedData)) {
                Log::info('UpdateWellnessEventRequest - Datos parseados manualmente', [
                    'parsed_data' => $parsedData,
                    'timestamp' => now()->toISOString(),
                ]);
                $this->merge($parsedData);
            }
        }

        // Parse is_visible from string to boolean if needed
        if ($this->has('is_visible')) {
            $isVisible = $this->input('is_visible');
            if (is_string($isVisible)) {
                $this->merge([
                    'is_visible' => filter_var($isVisible, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }

        // Parse attendees from string to integer if needed
        if ($this->has('attendees') && is_string($this->input('attendees'))) {
            $attendees = $this->input('attendees');
            if ($attendees !== null && $attendees !== '') {
                $this->merge([
                    'attendees' => (int) $attendees,
                ]);
            }
        }
    }

    /**
     * Manually parse multipart/form-data when Laravel doesn't parse it correctly
     */
    private function parseMultipartFormData(): array
    {
        $contentType = $this->header('Content-Type', '');
        if (!str_contains($contentType, 'multipart/form-data')) {
            return [];
        }

        // Extract boundary from Content-Type header
        if (!preg_match('/boundary=(.+)$/i', $contentType, $matches)) {
            return [];
        }

        $boundary = '--' . trim($matches[1]);
        $body = $this->getContent();
        
        if (empty($body)) {
            return [];
        }

        $parts = explode($boundary, $body);
        $data = [];

        foreach ($parts as $part) {
            $part = trim($part);
            
            // Skip empty parts and the closing boundary
            if (empty($part) || $part === '--') {
                continue;
            }

            // Split headers and content
            if (strpos($part, "\r\n\r\n") === false && strpos($part, "\n\n") === false) {
                continue;
            }

            $delimiter = strpos($part, "\r\n\r\n") !== false ? "\r\n\r\n" : "\n\n";
            list($headers, $content) = explode($delimiter, $part, 2);
            $content = rtrim($content, "\r\n--");

            // Parse headers to find field name
            if (preg_match('/Content-Disposition:.*name="([^"]+)"/i', $headers, $nameMatch)) {
                $fieldName = $nameMatch[1];
                
                // Skip file fields (they should be handled by Laravel's file handling)
                if (preg_match('/filename="([^"]*)"/i', $headers)) {
                    continue;
                }

                // Only process known fields
                $allowedFields = ['title', 'date', 'category', 'description', 'location', 
                                 'attendees', 'gift', 'provider', 'is_visible', 'eliminar_attendance_list'];
                
                if (in_array($fieldName, $allowedFields)) {
                    $value = trim($content);
                    
                    // Convert boolean strings
                    if ($fieldName === 'is_visible') {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    }
                    
                    // Convert integer strings
                    if ($fieldName === 'attendees' && is_numeric($value)) {
                        $value = (int) $value;
                    }
                    
                    // Only add non-empty values
                    if ($value !== null && $value !== '') {
                        $data[$fieldName] = $value;
                    }
                }
            }
        }

        return $data;
    }

    protected function failedValidation(Validator $validator)
    {
        // Loguear payload y errores de validación
        Log::error('Validación fallida en UpdateWellnessEventRequest', [
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->all(),
            'path' => $this->path(),
            'method' => $this->method(),
            'ip' => $this->ip(),
            'user_id' => optional($this->user())->id ?? null,
        ]);

        $response = response()->json([
            'message' => 'La solicitud contiene errores de validación.',
            'errors' => $validator->errors(),
        ], 422);

        throw new HttpResponseException($response);
    }
}
