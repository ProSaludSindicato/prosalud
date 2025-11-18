<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AfiliadoRequestOtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'tipo_documento' => 'required|string|max:50',
            'documento' => 'required|string|max:50',
            'fecha_expedicion' => 'required|string|max:50',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'tipo_documento.required' => 'El tipo de documento es requerido.',
            'tipo_documento.string' => 'El tipo de documento debe ser una cadena de texto.',
            'tipo_documento.max' => 'El tipo de documento no puede exceder 50 caracteres.',
            'documento.required' => 'El número de documento es requerido.',
            'documento.string' => 'El número de documento debe ser una cadena de texto.',
            'documento.max' => 'El número de documento no puede exceder 50 caracteres.',
            'fecha_expedicion.required' => 'La fecha de expedición es requerida.',
            'fecha_expedicion.string' => 'La fecha de expedición debe ser una cadena de texto.',
            'fecha_expedicion.max' => 'La fecha de expedición no puede exceder 50 caracteres.',
        ];
    }
}
