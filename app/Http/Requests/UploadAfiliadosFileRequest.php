<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UploadAfiliadosFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ajustar según los permisos requeridos
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
                'mimes:xlsx,xls',
                'max:20480', // 20MB
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
            'file.mimes' => 'El archivo debe ser un Excel (.xlsx o .xls).',
            'file.max' => 'El archivo no puede ser mayor a 10MB.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        Log::warning('Validación fallida en upload de afiliados', [
            'errors' => $validator->errors()->toArray(),
            'ip_address' => $this->ip(),
        ]);

        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422));
    }
}
