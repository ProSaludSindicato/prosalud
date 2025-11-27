<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\{Auth, Log};

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
                'max:20480', // 20MB
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $file = $this->file('file');
            
            if ($file) {
                $extension = strtolower($file->getClientOriginalExtension());
                $allowedExtensions = ['xlsx', 'xls'];
                
                if (!in_array($extension, $allowedExtensions, true)) {
                    $validator->errors()->add(
                        'file',
                        'El archivo debe ser un Excel (.xlsx o .xls).'
                    );
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'El archivo es obligatorio.',
            'file.file' => 'Debe ser un archivo válido.',
            'file.max' => 'El archivo no puede ser mayor a 20MB.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        $file = $this->file('file');
        $fileDetails = [];

        if ($file) {
            $fileSize = $file->getSize();
            $fileDetails = [
                'file_name' => $file->getClientOriginalName() ?: 'unknown',
                'file_size' => $fileSize !== false ? $fileSize : null,
                'file_size_human' => $fileSize !== false ? $this->formatBytes($fileSize) : 'unknown',
                'mime_type' => $file->getMimeType() ?: 'unknown',
                'file_extension' => $file->getClientOriginalExtension() ?: 'unknown',
                'file_path' => $file->getRealPath() ?: 'temporary',
                'is_valid' => $file->isValid(),
                'error_code' => $file->getError(),
                'error_message' => $file->getError() !== UPLOAD_ERR_OK ? $this->getUploadErrorMessage($file->getError()) : null,
            ];
        } else {
            $fileDetails = [
                'status' => 'file_not_present',
                'message' => 'El archivo no fue encontrado en la solicitud',
            ];
        }

        $logData = [
            'errors' => $validator->errors()->toArray(),
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'timestamp' => now()->toISOString(),
            'request_method' => $this->method(),
            'request_url' => $this->fullUrl(),
            'request_data' => $this->except(['file']), // Excluir el archivo binario del log
        ];

        if (!empty($fileDetails)) {
            $logData['file_details'] = $fileDetails;
        }

        if (Auth::check()) {
            $user = Auth::user();
            $logData['user'] = [
                'id' => $user->id,
                'email' => $user->email ?? null,
                'name' => $user->name ?? null,
            ];
        }

        Log::warning('Validación fallida en upload de afiliados', $logData);

        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422));
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get human readable upload error message.
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_OK => 'No hay error',
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por el servidor (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el formulario (MAX_FILE_SIZE)',
            UPLOAD_ERR_PARTIAL => 'El archivo fue subido parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida del archivo',
            default => 'Error desconocido (' . $errorCode . ')',
        };
    }
}
