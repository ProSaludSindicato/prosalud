<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ChangeWellnessEventVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_visible' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_visible.boolean' => 'El campo de visibilidad debe ser verdadero o falso.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        // Loguear payload y errores de validación
        Log::error('Validación fallida en ChangeWellnessEventVisibilityRequest', [
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->all(),
            'path' => $this->path(),
            'method' => $this->method(),
            'ip' => $this->ip(),
            'user_id' => optional($this->user())->id ?? null,
        ]);

        $response = response()->json([
            'message' => 'La solicitud contiene errores de validación.',
            'errors' => $validator->errors(),
        ], 422);

        throw new HttpResponseException($response);
    }
}
