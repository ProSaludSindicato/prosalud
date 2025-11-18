<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class StoreComfenalcoEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'banner_image' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'], // 5MB max
            'registration_link' => ['nullable', 'string', 'url', 'max:500'],
            'category' => ['required', 'string', 'max:255'],
            'display_size' => ['nullable', 'string', 'in:carousel,mosaic'],
            'description' => ['nullable', 'string'],
            'registration_deadline' => ['nullable', 'date'],
            'event_date' => ['nullable', 'date'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'El título es obligatorio.',
            'title.string' => 'El título debe ser una cadena de texto.',
            'title.max' => 'El título no puede exceder 255 caracteres.',
            'banner_image.required' => 'La imagen del banner es obligatoria.',
            'banner_image.file' => 'El banner debe ser un archivo.',
            'banner_image.image' => 'El banner debe ser una imagen.',
            'banner_image.mimes' => 'El banner debe ser un archivo de tipo: jpeg, png, jpg, gif, webp.',
            'banner_image.max' => 'El banner no puede exceder 5MB.',
            'registration_link.string' => 'El enlace de registro debe ser una cadena de texto.',
            'registration_link.url' => 'El enlace de registro debe ser una URL válida.',
            'registration_link.max' => 'El enlace de registro no puede exceder 500 caracteres.',
            'category.required' => 'La categoría es obligatoria.',
            'category.string' => 'La categoría debe ser una cadena de texto.',
            'category.max' => 'La categoría no puede exceder 255 caracteres.',
            'display_size.string' => 'El tamaño de visualización debe ser una cadena de texto.',
            'display_size.in' => 'El tamaño de visualización debe ser "carousel" o "mosaic".',
            'description.string' => 'La descripción debe ser una cadena de texto.',
            'registration_deadline.date' => 'La fecha límite de registro debe ser una fecha válida (opcional).',
            'event_date.date' => 'La fecha del evento debe ser una fecha válida (opcional).',
            'is_visible.boolean' => 'La visibilidad debe ser verdadero o falso.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_visible') && is_string($this->input('is_visible'))) {
            $this->merge([
                'is_visible' => filter_var($this->input('is_visible'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    protected function failedValidation(Validator $validator)
    {
        Log::warning('Validación fallida para evento Comfenalco', [
            'errors' => $validator->errors()->toArray(),
            'input' => $this->all(),
            'ip_address' => $this->ip(),
            'timestamp' => now()->toISOString(),
        ]);

        throw new HttpResponseException(response()->json(['message' => 'Los datos proporcionados no son válidos.', 'errors' => $validator->errors()], 422));
    }
}
