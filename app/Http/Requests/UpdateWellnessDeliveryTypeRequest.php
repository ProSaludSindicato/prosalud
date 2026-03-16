<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWellnessDeliveryTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|string|max:200',
            'activo' => 'sometimes|boolean',
            'modo_acceso' => 'sometimes|string|in:listado,abierto',
            'fecha_desde' => 'nullable|sometimes|date|date_format:Y-m-d|required_with:fecha_hasta',
            'fecha_hasta' => 'nullable|sometimes|date|date_format:Y-m-d|required_with:fecha_desde|after_or_equal:fecha_desde',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.max' => 'El nombre no puede exceder 200 caracteres.',
            'modo_acceso.in' => 'El modo de acceso debe ser "listado" o "abierto".',
            'fecha_desde.date_format' => 'La fecha desde debe estar en formato YYYY-MM-DD.',
            'fecha_hasta.date_format' => 'La fecha hasta debe estar en formato YYYY-MM-DD.',
            'fecha_hasta.after_or_equal' => 'La fecha hasta debe ser igual o posterior a la fecha desde.',
        ];
    }
}
