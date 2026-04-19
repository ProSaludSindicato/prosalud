<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UploadDelegadosPhotosZipRequest extends FormRequest
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
                'mimes:zip',
                'max:30720',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'El archivo comprimido es obligatorio.',
            'file.file' => 'Debe ser un archivo válido.',
            'file.mimes' => 'El archivo debe estar en formato .zip.',
            'file.max' => 'El archivo no puede ser mayor a 30MB.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        Log::warning('Validación fallida en upload de fotos de delegados', [
            'errors' => $validator->errors()->toArray(),
            'ip_address' => $this->ip(),
        ]);

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Errores de validación',
            'errors' => $validator->errors(),
        ], 422));
    }
}
