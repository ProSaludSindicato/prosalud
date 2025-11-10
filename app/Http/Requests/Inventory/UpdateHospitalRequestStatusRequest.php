<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHospitalRequestStatusRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => 'required|in:pending,approved,preparing,shipped,delivered,rejected',
            'actor' => 'nullable|string|max:255',
            'description' => 'nullable|string',
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
            'status.required' => 'El estado es obligatorio',
            'status.in' => 'El estado seleccionado no es válido',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hospitalRequest = $this->route('hospital_request');

            if (is_string($hospitalRequest)) {
                $hospitalRequest = \App\Models\HospitalRequest::find($hospitalRequest);
            }

            if (!$hospitalRequest) {
                $validator->errors()->add('hospital_request', 'La solicitud de hospital no existe.');
                return;
            }
            $newStatus = $this->input('status');

            if ($hospitalRequest && !$hospitalRequest->canTransitionTo($newStatus)) {
                $validator->errors()->add(
                    'status',
                    "No se puede cambiar el estado de '{$hospitalRequest->status}' a '{$newStatus}'"
                );
            }
        });
    }
}
