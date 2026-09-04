<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RetryFailedConvenioEmailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('tracking_ids')) {
            return;
        }

        if (! $this->filled('fecha_desde') && ! $this->filled('fecha_hasta')) {
            $today = now()->toDateString();

            $this->merge([
                'fecha_desde' => $today,
                'fecha_hasta' => $today,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tracking_ids' => ['nullable', 'array', 'max:1000'],
            'tracking_ids.*' => ['integer', 'exists:convenio_email_tracking,id'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'sede' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tracking_ids.max' => 'No se pueden reintentar más de 1000 convenios a la vez.',
            'tracking_ids.*.exists' => 'Uno o más registros no existen.',
            'fecha_hasta.after_or_equal' => 'La fecha hasta debe ser igual o posterior a la fecha desde.',
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
