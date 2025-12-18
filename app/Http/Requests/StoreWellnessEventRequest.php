<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class StoreWellnessEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'category' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['required', 'string', 'max:255'],
            'attendees' => ['nullable', 'integer', 'min:0'],
            'gift' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'is_visible' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array'],
            'images.*' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'], // 5MB max
            'attendance_list' => ['nullable', 'file', 'mimes:pdf,xls,xlsx', 'max:10240'], // 10MB max
        ];
    }

    public function messages(): array
    {
        return [
            // title
            'title.required' => 'El título es obligatorio.',
            'title.string' => 'El título debe ser un texto válido.',
            'title.max' => 'El título no puede exceder 255 caracteres.',

            // date
            'date.required' => 'La fecha es obligatoria.',
            'date.date' => 'La fecha no tiene un formato válido.',

            // category
            'category.required' => 'La categoría es obligatoria.',
            'category.string' => 'La categoría debe ser un texto válido.',
            'category.max' => 'La categoría no puede exceder 255 caracteres.',

            // description
            'description.string' => 'La descripción debe ser un texto válido.',

            // location
            'location.required' => 'La ubicación es obligatoria.',
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

            // images
            'images.array' => 'Las imágenes deben ser enviadas como un arreglo.',
            'images.*.required' => 'Cada imagen es obligatoria cuando se envía.',
            'images.*.file' => 'Cada elemento debe ser un archivo válido.',
            'images.*.image' => 'Cada archivo debe ser una imagen válida.',
            'images.*.mimes' => 'Las imágenes deben ser de tipo: jpeg, png, jpg, gif, webp.',
            'images.*.max' => 'Cada imagen no puede exceder 5MB.',

            // attendance_list
            'attendance_list.file' => 'El listado de asistencia debe ser un archivo válido.',
            'attendance_list.mimes' => 'El listado de asistencia debe ser de tipo: pdf, xls, xlsx.',
            'attendance_list.max' => 'El listado de asistencia no puede exceder 10MB.',
        ];
    }

    protected function prepareForValidation()
    {
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
        Log::error('Validación fallida en StoreWellnessEventRequest', [
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
