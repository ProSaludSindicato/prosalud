<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportVaccinationSurveysExcelRequest extends FormRequest
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
            'date_range.include_all' => ['sometimes', 'boolean'],
            'date_range.start_date' => [
                'required_if:date_range.include_all,false',
                'nullable',
                'date',
            ],
            'date_range.end_date' => [
                'required_if:date_range.include_all,false',
                'nullable',
                'date',
                'after_or_equal:date_range.start_date',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'date_range.start_date.required_if' => 'La fecha de inicio es requerida cuando no se incluyen todos los registros.',
            'date_range.end_date.required_if' => 'La fecha de fin es requerida cuando no se incluyen todos los registros.',
            'date_range.end_date.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
        ];
    }

    public function attributes(): array
    {
        return [
            'date_range.include_all' => 'incluir todos los registros',
            'date_range.start_date' => 'fecha de inicio',
            'date_range.end_date' => 'fecha de fin',
        ];
    }
}
