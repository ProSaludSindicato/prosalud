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
        ];
    }

    protected function prepareForValidation()
    {
        Log::info('UpdateWellnessEventRequest - Datos recibidos', [
            'method' => $this->method(),
            'content_type' => $this->header('Content-Type'),
            'raw_data' => $this->all(),
            'json_data' => $this->json()->all(),
            'input_data' => $this->input(),
            'has_files' => $this->hasFile('images'),
            'files_count' => count($this->file('images', [])),
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);

        // Parse is_visible from string to boolean if needed
        if ($this->has('is_visible') && is_string($this->input('is_visible'))) {
            $this->merge([
                'is_visible' => filter_var($this->input('is_visible'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
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
