<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreWellnessListDeliveryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'wellness_delivery_type_id' => ['required', 'integer', 'exists:wellness_delivery_types,id'],
            'documento_afiliado' => ['required', 'string', 'max:50'],
            'nombre_afiliado' => ['nullable', 'string', 'max:200'],
            'hospital' => ['nullable', 'string', 'max:150'],
            'claves' => ['required', 'array', 'min:1'],
            'claves.*' => ['required', 'string', 'max:300'],
            'firma' => ['required', 'string'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'wellness_delivery_type_id.required' => 'Debe seleccionar la campaña.',
            'wellness_delivery_type_id.exists' => 'La campaña seleccionada no existe.',
            'documento_afiliado.required' => 'El documento del afiliado es obligatorio.',
            'claves.required' => 'Seleccione al menos un beneficiario a entregar.',
            'claves.min' => 'Seleccione al menos un beneficiario a entregar.',
            'firma.required' => 'La firma del afiliado es obligatoria.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
