<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportWellnessExcelRequest extends FormRequest
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
        $validCostCenters = [
            'Bello',
            'Rionegro',
            'La Maria asistencial',
            'La Maria VIH',
            'La Maria Cosalud',
            'La Maria Enterritorio',
            'Carisma',
            'Admon',
        ];

        $validStatuses = ['pending', 'in_progress', 'resolved', 'rejected'];

        return [
            'cost_center' => [
                'sometimes',
                'string',
                Rule::in($validCostCenters),
            ],
            'requester_id' => [
                'sometimes',
                'integer',
                'exists:users,id',
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in($validStatuses),
            ],
            'fecha_desde' => [
                'sometimes',
                'nullable',
                'date',
            ],
            'fecha_hasta' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:fecha_desde',
            ],
            'include_images' => [
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
            'cost_center.in' => 'El centro de costos seleccionado no es válido.',
            'requester_id.exists' => 'El solicitante seleccionado no existe.',
            'status.in' => 'El estado seleccionado no es válido.',
            'fecha_hasta.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'cost_center' => 'centro de costos',
            'requester_id' => 'solicitante',
            'status' => 'estado',
            'fecha_desde' => 'fecha de inicio',
            'fecha_hasta' => 'fecha de fin',
        ];
    }
}
