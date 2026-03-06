<?php

namespace App\Http\Requests;

use App\Models\WellnessDeliveryRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportWellnessDeliveryExcelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Requiere autenticación (se valida en el middleware)
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo_entrega' => ['sometimes', 'nullable'],
            'estado' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_keys(WellnessDeliveryRequest::ESTADOS)),
            ],
            'fecha_desde' => [
                'sometimes',
                'nullable',
                'date',
                'date_format:Y-m-d',
            ],
            'fecha_hasta' => [
                'sometimes',
                'nullable',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:fecha_desde',
            ],
            'include_firmas' => [
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
            'estado.in' => 'El estado seleccionado no es válido',
            'fecha_desde.date' => 'La fecha de inicio debe ser una fecha válida',
            'fecha_desde.date_format' => 'La fecha de inicio debe estar en formato YYYY-MM-DD',
            'fecha_hasta.date' => 'La fecha de fin debe ser una fecha válida',
            'fecha_hasta.date_format' => 'La fecha de fin debe estar en formato YYYY-MM-DD',
            'fecha_hasta.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio',
            'include_firmas.boolean' => 'El campo include_firmas debe ser true o false',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'tipo_entrega' => 'tipo de entrega',
            'estado' => 'estado',
            'fecha_desde' => 'fecha de inicio',
            'fecha_hasta' => 'fecha de fin',
            'include_firmas' => 'incluir firmas',
        ];
    }
}

