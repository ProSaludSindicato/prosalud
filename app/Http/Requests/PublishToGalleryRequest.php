<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PublishToGalleryRequest extends FormRequest
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
            // wellness_request_id is optional in body (comes from URL), but if provided, validate it
            'wellness_request_id' => 'sometimes|integer|exists:wellness_requests,id',
            'evidencias_seleccionadas' => 'required|array|min:1',
            'evidencias_seleccionadas.*' => 'required|integer|exists:wellness_activity_evidences,id',
            'evidencias_orden' => 'sometimes|array', // Array with evidence_id => order mapping
            'evidencias_orden.*' => 'integer|min:1', // Order values
            'imagen_principal_id' => 'sometimes|integer|exists:wellness_activity_evidences,id',
            'title' => 'required|string|max:255',
            'category' => 'required|string|in:Salud,Bienestar,Capacitación,Recreación,Cultura,Deporte',
            'description' => 'nullable|string|max:500',
            'is_visible' => 'sometimes|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'wellness_request_id.exists' => 'La solicitud de bienestar no existe',
            'evidencias_seleccionadas.required' => 'Debe seleccionar al menos una evidencia',
            'evidencias_seleccionadas.min' => 'Debe seleccionar al menos una evidencia',
            'evidencias_seleccionadas.*.exists' => 'Una o más evidencias seleccionadas no existen',
            'title.required' => 'El título es obligatorio',
            'title.max' => 'El título no puede exceder 255 caracteres',
            'category.required' => 'La categoría es obligatoria',
            'category.in' => 'La categoría debe ser una de: Salud, Bienestar, Capacitación, Recreación, Cultura, Deporte',
            'description.max' => 'La descripción no puede exceder 500 caracteres',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
