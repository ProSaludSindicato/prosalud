<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PresidentSignBulkRequest extends FormRequest
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
            'tracking_ids' => ['required', 'array', 'min:1', 'max:200'],
            'tracking_ids.*' => ['integer', 'distinct', 'exists:convenio_email_tracking,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tracking_ids.required' => 'Seleccione al menos un convenio para firmar.',
            'tracking_ids.min' => 'Seleccione al menos un convenio para firmar.',
            'tracking_ids.max' => 'No se pueden firmar más de 200 convenios a la vez.',
            'tracking_ids.*.exists' => 'Uno o más registros no existen.',
            'tracking_ids.*.distinct' => 'Hay convenios duplicados en la selección.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tracking_ids' => 'convenios',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Errores de validación',
            'errors' => $validator->errors(),
        ], 422));
    }
}
