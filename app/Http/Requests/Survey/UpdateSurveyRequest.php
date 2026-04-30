<?php

namespace App\Http\Requests\Survey;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:2000',
            'status' => 'sometimes|in:draft,active,closed',
            'access_type' => 'sometimes|in:public,authenticated,restricted',
            'allowed_affiliate_statuses' => 'sometimes|nullable|array|min:1',
            'allowed_affiliate_statuses.*' => 'string|in:activo,retirado',
            'requires_signature' => 'sometimes|boolean',
            'allows_multiple_responses' => 'sometimes|boolean',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date|after_or_equal:start_date',
            'hospital_ids' => 'sometimes|nullable|array',
            'hospital_ids.*' => 'integer|exists:hospitals,id',
            'questions' => 'sometimes|array|min:1',
            'questions.*.type' => 'required_with:questions|in:text,textarea,single_choice,multiple_choice,date,number,scale,ranking,yes_no',
            'questions.*.label' => 'required_with:questions|string|max:500',
            'questions.*.help_text' => 'nullable|string|max:1000',
            'questions.*.is_required' => 'boolean',
            'questions.*.order' => 'integer|min:0',
            'questions.*.ranking_unique_priority' => 'nullable|boolean',
            'questions.*.options' => 'nullable|array|min:1',
            'questions.*.options.*.value' => 'required|string|max:255',
            'questions.*.options.*.label' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'access_type.in' => 'El tipo de acceso debe ser: público, autenticado o restringido.',
            'questions.min' => 'La encuesta debe tener al menos una pregunta.',
            'questions.*.type.in' => 'El tipo de pregunta no es válido.',
            'questions.*.label.required_with' => 'El texto de cada pregunta es obligatorio.',
        ];
    }

    protected function failedValidation(Validator $validator): void
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
