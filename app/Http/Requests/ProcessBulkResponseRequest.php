<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ProcessBulkResponseRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:10240', // 10MB máximo
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'El archivo Excel es requerido.',
            'file.file' => 'El archivo debe ser un archivo válido.',
            'file.mimes' => 'El archivo debe ser un archivo Excel (.xlsx o .xls).',
            'file.max' => 'El archivo no debe ser mayor a 10MB.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'file' => 'archivo Excel',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors()->toArray();
        $uploadedFile = $this->file('file');
        
        $fileInfo = [
            'file_name' => $uploadedFile?->getClientOriginalName() ?? 'no proporcionado',
            'file_size' => $uploadedFile?->getSize() ?? null,
            'file_mime_type' => $uploadedFile?->getMimeType() ?? null,
            'file_extension' => $uploadedFile?->getClientOriginalExtension() ?? null,
        ];

        // Mejorar mensajes de error con información del archivo
        if (isset($errors['file'])) {
            $fileErrors = [];
            foreach ($errors['file'] as $error) {
                $fileSize = $fileInfo['file_size'] ? number_format($fileInfo['file_size'] / 1024, 2) . ' KB' : 'desconocido';
                $fileErrors[] = $error . " (Archivo recibido: '{$fileInfo['file_name']}', Tamaño: {$fileSize}, Tipo: {$fileInfo['file_mime_type']})";
            }
            $errors['file'] = $fileErrors;
        }

        Log::warning('Errores de validación en ProcessBulkResponseRequest', [
            'errors' => $errors,
            'file_info' => $fileInfo,
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Error de validación en el archivo enviado',
                'errors' => $errors,
                'file_info' => $fileInfo,
            ], 422)
        );
    }
}

