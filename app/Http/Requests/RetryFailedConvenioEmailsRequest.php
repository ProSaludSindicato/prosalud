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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tracking_ids' => ['nullable', 'array', 'max:1000'],
            'tracking_ids.*' => ['integer', 'exists:convenio_email_tracking,id'],
            'fechas' => ['nullable', 'array', 'max:90'],
            'fechas.*' => ['required', 'date_format:Y-m-d'],
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
            'fechas.max' => 'No se pueden seleccionar más de 90 días a la vez.',
            'fechas.*.date_format' => 'Cada día debe tener el formato YYYY-MM-DD.',
            'fecha_hasta.after_or_equal' => 'La fecha hasta debe ser igual o posterior a la fecha desde.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('tracking_ids') || $this->filled('fechas') || $this->filled('fecha_desde') || $this->filled('fecha_hasta')) {
                return;
            }

            $validator->errors()->add(
                'fechas',
                'Seleccione al menos un día con envíos fallidos para reintentar.',
            );
        });
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
