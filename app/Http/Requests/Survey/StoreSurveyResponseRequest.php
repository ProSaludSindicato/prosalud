<?php

namespace App\Http\Requests\Survey;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSurveyResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'respondent_document_type' => 'nullable|string|in:CC,CE,TI,PA,PT,RC,NUIP,PE',
            'respondent_document_number' => 'nullable|string|max:50',
            'respondent_name' => 'nullable|string|max:255',
            'fecha_expedicion' => 'nullable|string|max:50',
            'hospital' => 'nullable|string|max:255',
            'signature' => 'nullable|string',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer|exists:survey_questions,id',
            'answers.*.value' => 'nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'answers.required' => 'Las respuestas son obligatorias.',
            'answers.*.question_id.required' => 'El identificador de pregunta es obligatorio.',
            'answers.*.question_id.exists' => 'La pregunta indicada no existe.',
        ];
    }

    public function attributes(): array
    {
        return [
            'respondent_document_type' => 'tipo de documento',
            'respondent_document_number' => 'número de documento',
            'respondent_name' => 'nombre',
            'fecha_expedicion' => 'fecha de expedición',
            'hospital' => 'hospital',
            'signature' => 'firma digital',
            'answers' => 'respuestas',
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
