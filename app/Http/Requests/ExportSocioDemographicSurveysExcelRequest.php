<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportSocioDemographicSurveysExcelRequest extends FormRequest
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
            'survey_type' => [
                'sometimes',
                'string',
                Rule::in(['all', 'active_affiliate', 'new_entry', 'bulk_entry']),
            ],
            'date_range.include_all' => [
                'sometimes',
                'boolean',
            ],
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
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'ano' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'mes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'hospitals' => ['sometimes', 'nullable', 'array'],
            'hospitals.*' => ['string', 'max:255'],
            'hospital' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'numero_documento' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tipo_documento' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nombre' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profesion' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'include_signatures' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'survey_type.in' => 'El tipo de encuesta seleccionado no es válido. Valores permitidos: all, active_affiliate, new_entry, bulk_entry.',
            'date_range.start_date.required_if' => 'La fecha de inicio es requerida cuando no se incluyen todos los registros.',
            'date_range.end_date.required_if' => 'La fecha de fin es requerida cuando no se incluyen todos los registros.',
            'date_range.end_date.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'survey_type' => 'tipo de encuesta',
            'date_range.include_all' => 'incluir todos los registros',
            'date_range.start_date' => 'fecha de inicio',
            'date_range.end_date' => 'fecha de fin',
            'hospital' => 'hospital',
            'profesion' => 'proceso',
        ];
    }
}
