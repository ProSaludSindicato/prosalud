<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateWellnessActivityRealizedRequest extends FormRequest
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
        return [
            'fecha_realizada' => 'sometimes|date|date_format:Y-m-d',
            'ubicacion_real' => 'sometimes|string|max:500',
            'numero_asistentes_real' => 'sometimes|integer|min:1',
            'descripcion_realizada' => 'sometimes|string|max:500',
            'obsequio_entregado' => 'nullable|string|max:255',
            'evidencias' => 'sometimes|array|max:20',
            'evidencias.*' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
            'evidencias_seleccionadas' => 'sometimes|string', // JSON array string
            'evidencias_orden' => 'sometimes|array', // Array with evidence_id => order mapping
            'evidencias_orden.*' => 'integer|min:1', // Order values
            'imagen_principal_id' => 'sometimes|integer|exists:wellness_activity_evidences,id',
            'evidencias_eliminadas' => 'sometimes|string', // JSON array string
            'listado_asistencia' => 'sometimes|file|mimes:pdf,xls,xlsx|max:10240',
            'eliminar_listado_asistencia' => 'sometimes|string|in:true,false',
            'publicado_en_galeria' => 'sometimes|boolean', // Allow explicitly setting to false (not to publish)
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'fecha_realizada.date' => 'La fecha realizada debe ser una fecha válida',
            'fecha_realizada.date_format' => 'La fecha realizada debe tener el formato YYYY-MM-DD',
            'numero_asistentes_real.min' => 'El número de asistentes real debe ser mayor a 0',
            'descripcion_realizada.max' => 'La descripción realizada no puede exceder 500 caracteres',
            'obsequio_entregado.max' => 'El obsequio entregado no puede exceder 255 caracteres',
            'evidencias.max' => 'No puede incluir más de 20 imágenes',
            'evidencias.*.image' => 'Los archivos de evidencia deben ser imágenes',
            'evidencias.*.mimes' => 'Las imágenes deben ser: jpeg, jpg, png, gif o webp',
            'evidencias.*.max' => 'Cada imagen no puede exceder 5MB',
            'listado_asistencia.file' => 'El listado de asistencia debe ser un archivo',
            'listado_asistencia.mimes' => 'El listado de asistencia debe ser PDF o Excel (.pdf, .xls, .xlsx)',
            'listado_asistencia.max' => 'El listado de asistencia no puede exceder 10MB',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422));
    }
}
