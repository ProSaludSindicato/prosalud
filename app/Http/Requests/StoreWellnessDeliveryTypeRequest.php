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
            'fecha_desde' => 'nullable|date|date_format:Y-m-d|required_with:fecha_hasta',
            'fecha_hasta' => 'nullable|date|date_format:Y-m-d|required_with:fecha_desde|after_or_equal:fecha_desde',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del tipo de entrega es obligatorio.',
            'nombre.max' => 'El nombre no puede exceder 200 caracteres.',
            'modo_acceso.required' => 'El modo de acceso es obligatorio (listado o abierto).',
            'modo_acceso.in' => 'El modo de acceso debe ser "listado" (con Excel de permitidos) o "abierto" (cualquier afiliado).',
            'fecha_desde.required_with' => 'Si indica fecha hasta, debe indicar fecha desde (o deje ambas vacías para tipo siempre activo).',
            'fecha_desde.date_format' => 'La fecha desde debe estar en formato YYYY-MM-DD.',
            'fecha_hasta.required_with' => 'Si indica fecha desde, debe indicar fecha hasta (o deje ambas vacías para tipo siempre activo).',
            'fecha_hasta.date_format' => 'La fecha hasta debe estar en formato YYYY-MM-DD.',
            'fecha_hasta.after_or_equal' => 'La fecha hasta debe ser igual o posterior a la fecha desde.',
        ];
    }
}
