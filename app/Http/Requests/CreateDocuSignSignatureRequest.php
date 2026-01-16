<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDocuSignSignatureRequest extends FormRequest
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
            'tipo_documento' => [
                'required',
                'string',
                'max:50',
            ],
            'documento' => [
                'required',
                'string',
                'max:50',
            ],
            'fecha_expedicion' => [
                'required',
                'string',
                'max:50',
            ],
            'return_url' => [
                'required',
                //'url',
                'max:500',
            ],
            'email_subject' => [
                'nullable',
                'string',
                'max:255',
            ],
            'document_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'tipo_documento.required' => 'El tipo de documento es requerido.',
            'documento.required' => 'El número de documento es requerido.',
            'fecha_expedicion.required' => 'La fecha de expedición es requerida.',
            'return_url.required' => 'La URL de retorno es requerida.',
            'return_url.url' => 'La URL de retorno debe ser válida.',
        ];
    }
}

