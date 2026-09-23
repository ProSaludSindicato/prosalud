<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RequestAffiliateResignBulkRequest extends FormRequest
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
            'tracking_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'tracking_ids.*' => ['integer', 'distinct', 'exists:convenio_email_tracking,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tracking_ids.required' => 'Seleccione al menos un convenio.',
            'tracking_ids.min' => 'Seleccione al menos un convenio.',
            'tracking_ids.max' => 'No se pueden solicitar más de 1000 nuevas firmas a la vez.',
            'tracking_ids.*.exists' => 'Uno o más registros no existen.',
            'tracking_ids.*.distinct' => 'Hay convenios duplicados en la selección.',
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
