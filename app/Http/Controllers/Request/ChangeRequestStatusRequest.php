<?php

namespace App\Http\Controllers\Request;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class ChangeRequestStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'status' => 'required|string|in:PENDING,IN_REVIEW,REJECTED,COMPLETED',
        ];

        // Si el estado es REJECTED, la razón de rechazo es requerida
        if ($this->input('status') === 'REJECTED') {
            $rules['rejection_reason'] = 'required|string|max:1000';
        } else {
            $rules['rejection_reason'] = 'nullable|string|max:1000';
        }

        // Razón opcional para cambios de estado (por ejemplo, IN_REVIEW)
        $rules['status_reason'] = 'nullable|string|max:1000';

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'El estado es obligatorio.',
            'status.string' => 'El estado debe ser una cadena de texto.',
            'status.in' => 'El estado debe ser "PENDING", "IN_REVIEW", "REJECTED" o "COMPLETED".',
            'rejection_reason.required' => 'La razón de rechazo es obligatoria cuando se rechaza una solicitud.',
            'rejection_reason.string' => 'La razón de rechazo debe ser una cadena de texto.',
            'rejection_reason.max' => 'La razón de rechazo no puede exceder 1000 caracteres.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'status' => 'estado',
            'rejection_reason' => 'razón de rechazo',
            'status_reason' => 'razón de cambio de estado',
        ];
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::error('Errores de validación en ChangeRequestStatusRequest', [
            'input' => $this->all(),
            'errors' => $validator->errors()->toArray(),
        ]);

        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422));
    }
}
