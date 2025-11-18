<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UploadIncapacidadesFileRequest extends FormRequest
{
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
                'max:5120', // 5MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'El archivo es obligatorio.',
            'file.file' => 'Debe ser un archivo válido.',
            'file.mimes' => 'El archivo debe ser un Excel (.xlsx o .xls).',
            'file.max' => 'El archivo no puede ser mayor a 5MB.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        Log::warning('Validación fallida en upload de incapacidades', [
            'errors' => $validator->errors()->toArray(),
            'ip_address' => $this->ip(),
        ]);

        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422));
    }
}
