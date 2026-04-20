<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkPresidentSignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'tracking_ids' => ['required', 'array', 'min:1', 'max:500'],
            'tracking_ids.*' => ['required', 'integer', 'exists:convenio_email_tracking,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tracking_ids.required' => 'Debe proporcionar al menos un convenio para firmar.',
            'tracking_ids.array' => 'El campo tracking_ids debe ser un arreglo.',
            'tracking_ids.min' => 'Debe proporcionar al menos un convenio para firmar.',
            'tracking_ids.max' => 'No se pueden procesar más de 500 convenios por solicitud.',
            'tracking_ids.*.integer' => 'Los IDs de convenio deben ser números enteros.',
            'tracking_ids.*.exists' => 'Uno o más IDs de convenio no existen.',
        ];
    }
}
