<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateInventoryReportRequest extends FormRequest
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
            'reportType' => [
                'required',
                'string',
                Rule::in(['strategic', 'operational', 'critical_stock']),
            ],
            'dateRange' => 'sometimes|array',
            'dateRange.start' => 'required_with:dateRange|date',
            'dateRange.end' => 'required_with:dateRange|date|after_or_equal:dateRange.start',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'reportType.required' => 'El tipo de reporte es obligatorio.',
            'reportType.in' => 'El tipo de reporte debe ser: strategic, operational o critical_stock.',
            'dateRange.start.required_with' => 'La fecha de inicio es obligatoria cuando se especifica un rango de fechas.',
            'dateRange.end.required_with' => 'La fecha de fin es obligatoria cuando se especifica un rango de fechas.',
            'dateRange.end.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
        ];
    }
}

