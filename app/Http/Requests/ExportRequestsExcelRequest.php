<?php

namespace App\Http\Requests;

use App\Constants\RequestTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportRequestsExcelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize request_type to canonical value
        $requestType = $this->input('request_type');
        if ($requestType && $requestType !== 'all') {
            $normalizedRequestType = RequestTypes::normalize($requestType);
            if ($normalizedRequestType !== $requestType) {
                $this->merge(['request_type' => $normalizedRequestType]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $validRequestTypes = array_merge(RequestTypes::all(), ['all']);

        return [
            'request_type' => [
                'sometimes',
                'string',
                Rule::in($validRequestTypes),
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
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'request_type.in' => 'El tipo de solicitud seleccionado no es válido.',
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
            'request_type' => 'tipo de solicitud',
            'date_range.include_all' => 'incluir todos los registros',
            'date_range.start_date' => 'fecha de inicio',
            'date_range.end_date' => 'fecha de fin',
        ];
    }
}

