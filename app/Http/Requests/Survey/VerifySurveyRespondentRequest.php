<?php

namespace App\Http\Requests\Survey;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifySurveyRespondentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'respondent_document_type' => 'required|string|in:CC,CE,TI,PA,PT,RC,NUIP,PE',
            'respondent_document_number' => 'required|string|max:50',
            'fecha_expedicion' => 'required|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'respondent_document_type.required' => 'Seleccione el tipo de documento.',
            'respondent_document_number.required' => 'Ingrese el número de documento.',
            'fecha_expedicion.required' => 'Ingrese la fecha de expedición del documento.',
        ];
    }

    public function attributes(): array
    {
        return [
            'respondent_document_type' => 'tipo de documento',
            'respondent_document_number' => 'número de documento',
            'fecha_expedicion' => 'fecha de expedición',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Revise los datos ingresados.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
