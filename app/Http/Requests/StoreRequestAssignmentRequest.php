<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRequestAssignmentRequest extends FormRequest
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
        return [
            'assignments' => 'required|array',
            'assignments.*' => 'required|array',
            'assignments.*.*' => 'required|string',
            'subtype_assignments' => 'nullable|array',
            'subtype_assignments.*' => 'required|array',
            'subtype_assignments.*.*' => 'required|array',
            'subtype_assignments.*.*.*' => 'required|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'assignments.required' => 'Las asignaciones son requeridas',
            'assignments.array' => 'Las asignaciones deben ser un objeto',
            'assignments.*.required' => 'Cada tipo de solicitud debe tener asignaciones',
            'assignments.*.array' => 'Las asignaciones de cada tipo deben ser un arreglo',
            'assignments.*.*.required' => 'Cada usuario asignado es requerido',
            'assignments.*.*.string' => 'Cada ID de usuario debe ser una cadena de texto',
            'subtype_assignments.array' => 'Las asignaciones por subtipo deben ser un objeto',
            'subtype_assignments.*.required' => 'Cada tipo de solicitud debe tener asignaciones de subtipos',
            'subtype_assignments.*.array' => 'Las asignaciones de subtipos deben ser un objeto',
            'subtype_assignments.*.*.required' => 'Cada subtipo debe tener asignaciones',
            'subtype_assignments.*.*.array' => 'Las asignaciones de cada subtipo deben ser un arreglo',
            'subtype_assignments.*.*.*.required' => 'Cada usuario asignado es requerido',
            'subtype_assignments.*.*.*.string' => 'Cada ID de usuario debe ser una cadena de texto',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422));
    }
}
