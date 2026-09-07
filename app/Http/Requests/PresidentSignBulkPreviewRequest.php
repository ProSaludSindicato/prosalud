<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class PresidentSignBulkPreviewRequest extends FormRequest
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
            'scope' => ['required', 'string', Rule::in(['all', 'date_range'])],
            'date_from' => ['nullable', 'date', 'required_if:scope,date_range'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'required_if:scope,date_range'],
            'include_errors' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scope.required' => 'Seleccione el alcance del lote.',
            'scope.in' => 'El alcance del lote no es válido.',
            'date_from.required_if' => 'Indique la fecha inicial del rango.',
            'date_to.required_if' => 'Indique la fecha final del rango.',
            'date_to.after_or_equal' => 'La fecha final debe ser posterior o igual a la inicial.',
            'include_errors.boolean' => 'El indicador de incluir errores debe ser verdadero o falso.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('include_errors')) {
            $this->merge([
                'include_errors' => filter_var(
                    $this->input('include_errors'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                ) ?? false,
            ]);
        }
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
