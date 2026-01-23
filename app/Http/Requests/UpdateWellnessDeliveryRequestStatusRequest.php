<?php

namespace App\Http\Requests;

use App\Models\WellnessDeliveryRequest;
use Illuminate\Contracts\Validation\{ValidationRule, Validator};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UpdateWellnessDeliveryRequestStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Requiere autenticación (se valida en el middleware)
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $estado = $this->input('estado');

        $rules = [
            'estado' => [
                'required',
                'string',
                'in:' . implode(',', array_keys(WellnessDeliveryRequest::ESTADOS)),
            ],
            'observaciones' => 'nullable|string|max:1000',
        ];

        // Si el estado es "entregado", la firma de recibido y cantidad_entregada son obligatorias
        if ($estado === 'entregado') {
            $rules['firma_recibido'] = 'required|string';
            $rules['cantidad_entregada'] = 'required|integer|min:1';
        }

        // Si el estado es "cancelado", no se requiere firma

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'estado.required' => 'El estado es obligatorio',
            'estado.in' => 'El estado seleccionado no es válido',
            'firma_recibido.required' => 'La firma de recibido es obligatoria cuando el estado es "entregado"',
            'firma_recibido.string' => 'La firma de recibido debe ser una cadena de texto',
            'cantidad_entregada.required' => 'La cantidad entregada es obligatoria cuando el estado es "entregado"',
            'cantidad_entregada.integer' => 'La cantidad entregada debe ser un número entero',
            'cantidad_entregada.min' => 'La cantidad entregada debe ser al menos 1',
            'observaciones.max' => 'Las observaciones no pueden exceder 1000 caracteres',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'estado' => 'estado',
            'firma_recibido' => 'firma de recibido',
            'cantidad_entregada' => 'cantidad entregada',
            'observaciones' => 'observaciones',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Validar que la firma de recibido no esté vacía después de trim (si es requerida)
            if ($this->input('estado') === 'entregado' && $this->filled('firma_recibido')) {
                $firma = trim($this->input('firma_recibido'));
                if (empty($firma)) {
                    $validator->errors()->add('firma_recibido', 'La firma de recibido no puede estar vacía');
                }
            }
        });
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::warning('Errores de validación en UpdateWellnessDeliveryRequestStatusRequest', [
            'input' => $this->except(['firma_recibido']), // No loggear la firma completa por seguridad
            'errors' => $validator->errors()->toArray(),
            'id' => $this->route('id'),
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()->toArray(),
            ], 422)
        );
    }
}

