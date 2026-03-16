<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class StoreKitBienestarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Público, no requiere autenticación
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            // Tipo de entrega: opcional si hay un solo tipo activo; obligatorio si hay varios (el usuario encargado selecciona).
            'wellness_delivery_type_id' => 'nullable|integer|exists:wellness_delivery_types,id',

            // Información del afiliado (debe coincidir con la autenticación)
            'documento_afiliado' => 'required|string|max:50',
            'nombre_afiliado' => 'required|string|max:200',
            'hospital' => 'nullable|string|max:100',
            'fecha_expedicion' => 'nullable|string|max:20',

            // Beneficiarios (opcional; si se envía array, cada elemento debe tener al menos beneficiario)
            'beneficiarios' => 'nullable|array',
            'beneficiarios.*.beneficiario' => 'required|string|max:200',
            'beneficiarios.*.parentesco' => 'nullable|string|max:50',
            'beneficiarios.*.edad' => 'nullable|string|max:10',

            // Firma (obligatoria)
            'firma' => 'required|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'documento_afiliado.required' => 'El documento del afiliado es obligatorio',
            'documento_afiliado.max' => 'El documento del afiliado no puede exceder 50 caracteres',
            'nombre_afiliado.required' => 'El nombre del afiliado es obligatorio',
            'nombre_afiliado.max' => 'El nombre del afiliado no puede exceder 200 caracteres',
            'hospital.max' => 'El hospital no puede exceder 100 caracteres',
            'fecha_expedicion.max' => 'La fecha de expedición no puede exceder 20 caracteres',
            'beneficiarios.array' => 'Los beneficiarios deben ser un array',
            'beneficiarios.*.beneficiario.required' => 'El nombre del beneficiario es obligatorio',
            'beneficiarios.*.beneficiario.max' => 'El nombre del beneficiario no puede exceder 200 caracteres',
            'beneficiarios.*.parentesco.max' => 'El parentesco no puede exceder 50 caracteres',
            'beneficiarios.*.edad.max' => 'La edad no puede exceder 10 caracteres',
            'firma.required' => 'La firma es obligatoria',
            'firma.string' => 'La firma debe ser una cadena de texto',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'documento_afiliado' => 'documento del afiliado',
            'nombre_afiliado' => 'nombre del afiliado',
            'hospital' => 'hospital',
            'fecha_expedicion' => 'fecha de expedición',
            'beneficiarios' => 'beneficiarios',
            'firma' => 'firma',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Validar que la firma no esté vacía después de trim
            if ($this->filled('firma')) {
                $firma = trim($this->input('firma'));
                if (empty($firma)) {
                    $validator->errors()->add('firma', 'La firma no puede estar vacía');
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
        Log::warning('Errores de validación en StoreKitBienestarRequest', [
            'input' => $this->except(['firma']), // No loggear la firma completa por seguridad
            'errors' => $validator->errors()->toArray(),
            'ip_address' => $this->ip(),
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
