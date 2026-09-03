<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SubmitConvenioSigningSatisfactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'score' => 'required|integer|min:1|max:5',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'score.required' => 'Debe indicar una calificación de 1 a 5.',
            'score.integer' => 'La calificación debe ser un número entero.',
            'score.min' => 'La calificación mínima es 1.',
            'score.max' => 'La calificación máxima es 5.',
        ];
    }

    public function score(): int
    {
        return (int) $this->validated('score');
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
