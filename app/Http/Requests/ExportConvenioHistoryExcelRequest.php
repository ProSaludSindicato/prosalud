<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ExportConvenioHistoryExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'documento' => ['nullable', 'string', 'max:50'],
            'estado' => ['nullable', 'string', 'in:pendiente,enviado,fallido,verificacion'],
            'signing_estado' => ['nullable', 'string', 'in:pendiente_firma,firmado_afiliado,firmando_presidente,pendiente_revision,error_firma_presidente,completado,rechazado'],
            'estado_filtro' => ['nullable', 'string', 'in:todos,pendiente,enviado,fallido,verificacion,test,firma_pendiente_firma,firma_firmado_afiliado,firma_pendiente_revision,firma_completado,firma_error_presidente,firma_rechazado'],
            'is_test' => ['nullable', 'boolean'],
            'sede' => ['nullable', 'string', 'max:255'],
            'nombre_convenio' => ['nullable', 'string', 'max:255'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'periodo' => ['nullable', 'string', 'regex:/^(todos|\d{4}[12])$/'],
            'calificacion' => ['nullable', 'string', 'in:1,2,3,4,5,sin_calificar'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.in' => 'El estado de envío seleccionado no es válido.',
            'signing_estado.in' => 'El estado de firma seleccionado no es válido.',
            'estado_filtro.in' => 'El filtro de estado seleccionado no es válido.',
            'fecha_hasta.after_or_equal' => 'La fecha hasta debe ser igual o posterior a la fecha desde.',
            'calificacion.in' => 'La calificación seleccionada no es válida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'búsqueda',
            'documento' => 'documento',
            'estado' => 'estado de envío',
            'signing_estado' => 'estado de firma',
            'estado_filtro' => 'filtro de estado',
            'sede' => 'sede',
            'fecha_desde' => 'fecha desde',
            'fecha_hasta' => 'fecha hasta',
            'periodo' => 'periodo',
            'calificacion' => 'calificación',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Errores de validación',
            'errors' => $validator->errors(),
        ], 422));
    }
}
