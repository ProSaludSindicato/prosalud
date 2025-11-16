<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class IncapacidadSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'tipo' => 'required|string|max:10',
            'numero_documento' => 'required|string|max:20',
            'fecha_expedicion' => 'required|date|date_format:Y-m-d'
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'El tipo de documento es obligatorio.',
            'tipo.string' => 'El tipo de documento debe ser un texto.',
            'tipo.max' => 'El tipo de documento no puede exceder los 10 caracteres.',

            'numero_documento.required' => 'El número de documento es obligatorio.',
            'numero_documento.string' => 'El número de documento debe ser un texto.',
            'numero_documento.max' => 'El número de documento no puede exceder los 20 caracteres.',

            'fecha_expedicion.required' => 'La fecha de expedición es obligatoria.',
            'fecha_expedicion.date' => 'La fecha de expedición debe ser una fecha válida.',
            'fecha_expedicion.date_format' => 'La fecha de expedición debe tener el formato YYYY-MM-DD.'
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        Log::warning('Validación fallida en consulta de incapacidades', [
            'errors' => $validator->errors()->toArray(),
            'input' => $this->all(),
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'timestamp' => now()
        ]);

        throw new HttpResponseException(
            response()->json([
                'message' => 'Los datos proporcionados no son válidos.',
                'errors' => $validator->errors()
            ], 422)
        );
    }
}

