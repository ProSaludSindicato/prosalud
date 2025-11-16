<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UploadActivosFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Cambiar según tus permisos necesarios
        // return auth()->check() && auth()->user()->can('upload_activos_file');
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls', // Solo archivos Excel
                'max:10240', // Máximo 10MB
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'El archivo es obligatorio.',
            'file.file' => 'Debe ser un archivo válido.',
            'file.mimes' => 'El archivo debe ser un archivo Excel (.xlsx o .xls).',
            'file.max' => 'El archivo no puede ser mayor a 10MB.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        Log::warning('Validación fallida en upload de ACTIVOS2', [
            'errors' => $validator->errors()->toArray(),
            'ip_address' => $this->ip(),
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}


