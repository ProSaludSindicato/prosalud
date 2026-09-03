<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UploadConvenioPdfZipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('send_email') && is_string($this->input('send_email'))) {
            $normalized = mb_strtolower(trim((string) $this->input('send_email')), 'UTF-8');
            $boolValue = match ($normalized) {
                'si', 'sí', 'yes', '1', 'true' => true,
                'no', '0', 'false' => false,
                default => $this->input('send_email'),
            };

            $this->merge(['send_email' => $boolValue]);
        }
    }

    public function rules(): array
    {
        $maxKb = max(1024, (int) config('convenios.zip_max_kb', 51200));

        return [
            'file' => [
                'required',
                'file',
                'mimes:zip',
                'max:'.$maxKb,
            ],
            'send_email' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        $maxMb = round(max(1, (int) config('convenios.zip_max_kb', 51200)) / 1024, 1);

        return [
            'file.required' => 'El archivo comprimido es obligatorio.',
            'file.file' => 'Debe ser un archivo válido.',
            'file.mimes' => 'El archivo debe estar en formato .zip.',
            'file.max' => 'El archivo no puede ser mayor a '.$maxMb.'MB.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('[CONVENIO ZIP] Validación fallida en upload', [
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
