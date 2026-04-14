<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadAssemblyDelegatesFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Debe seleccionar un archivo Excel (.xlsx).',
            'file.mimes' => 'El archivo debe ser de tipo Excel (.xlsx).',
            'file.max' => 'El archivo no puede superar 10 MB.',
        ];
    }
}
