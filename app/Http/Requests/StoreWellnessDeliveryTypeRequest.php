<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWellnessDeliveryTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:200',
            'activo' => 'boolean',
            'modo_acceso' => 'required|string|in:listado,abierto',
            'fecha_desde' => 'required|date|date_format:Y-m-d',
            'fecha_hasta' => 'required|date|date_format:Y-m-d|after_or_equal:fecha_desde',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del tipo de entrega es obligatorio.',
            'nombre.max' => 'El nombre no puede exceder 200 caracteres.',
            'modo_acceso.required' => 'El modo de acceso es obligatorio (listado o abierto).',
            'modo_acceso.in' => 'El modo de acceso debe ser "listado" (con Excel de permitidos) o "abierto" (cualquier afiliado).',
            'fecha_desde.required' => 'La fecha desde es obligatoria.',
            'fecha_desde.date_format' => 'La fecha desde debe estar en formato YYYY-MM-DD.',
            'fecha_hasta.required' => 'La fecha hasta es obligatoria.',
            'fecha_hasta.date_format' => 'La fecha hasta debe estar en formato YYYY-MM-DD.',
            'fecha_hasta.after_or_equal' => 'La fecha hasta debe ser igual o posterior a la fecha desde.',
        ];
    }
}
