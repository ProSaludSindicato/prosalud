<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ReportConvenioSigningClientErrorRequest extends FormRequest
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
            'message' => 'required|string|max:2000',
            'stack' => 'nullable|string|max:8000',
            'component_stack' => 'nullable|string|max:8000',
            'url' => 'nullable|string|max:2000',
            'token' => 'nullable|string|max:128',
            'context' => 'nullable|array',
            'context.user_agent' => 'nullable|string|max:1000',
            'context.device_memory' => 'nullable|numeric',
            'context.viewport' => 'nullable|string|max:64',
            'context.phase' => 'nullable|string|max:64',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'El mensaje de error es obligatorio.',
            'message.string' => 'El mensaje de error no es válido.',
            'message.max' => 'El mensaje de error excede el tamaño máximo permitido.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
