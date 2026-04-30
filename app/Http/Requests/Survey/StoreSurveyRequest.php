<?php

namespace App\Http\Requests\Survey;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'status' => 'sometimes|in:draft,active,closed',
            'access_type' => 'required|in:public,authenticated,restricted',
            'allowed_affiliate_statuses' => 'nullable|array|min:1',
            'allowed_affiliate_statuses.*' => 'string|in:activo,retirado',
            'requires_signature' => 'boolean',
            'allows_multiple_responses' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'hospital_ids' => 'required_if:access_type,restricted|nullable|array',
            'hospital_ids.*' => 'integer|exists:hospitals,id',
            'questions' => 'required|array|min:1',
            'questions.*.type' => 'required|in:text,textarea,single_choice,multiple_choice,date,number,scale,ranking,yes_no',
            'questions.*.label' => 'required|string|max:500',
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
            'title.required' => 'El título de la encuesta es obligatorio.',
            'access_type.required' => 'El tipo de acceso es obligatorio.',
            'access_type.in' => 'El tipo de acceso debe ser: público, autenticado o restringido.',
            'hospital_ids.required_if' => 'Debe seleccionar al menos un hospital para encuestas de acceso restringido.',
            'questions.required' => 'La encuesta debe tener al menos una pregunta.',
            'questions.min' => 'La encuesta debe tener al menos una pregunta.',
            'questions.*.type.required' => 'Cada pregunta debe tener un tipo.',
            'questions.*.type.in' => 'El tipo de pregunta no es válido.',
            'questions.*.label.required' => 'El texto de cada pregunta es obligatorio.',
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'título',
            'description' => 'descripción',
            'access_type' => 'tipo de acceso',
            'requires_signature' => 'requiere firma',
            'allows_multiple_responses' => 'permite múltiples respuestas',
            'start_date' => 'fecha de inicio',
            'end_date' => 'fecha de cierre',
            'hospital_ids' => 'hospitales',
            'questions' => 'preguntas',
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
