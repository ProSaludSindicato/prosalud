<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreWellnessActivityRealizedRequest extends FormRequest
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
            // wellness_request_id comes from URL parameter, not from body
            'fecha_realizada' => 'required|date|date_format:Y-m-d',
            'ubicacion_real' => 'required|string|max:500',
            'numero_asistentes_real' => 'required|integer|min:1',
            'descripcion_realizada' => 'required|string|max:500',
            'obsequio_entregado' => 'nullable|string|max:255',
            'evidencias' => 'required|array|min:1|max:20',
            'evidencias.*' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
            'listado_asistencia' => 'required|file|mimes:pdf,xls,xlsx|max:10240',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'fecha_realizada.required' => 'La fecha realizada es obligatoria',
            'fecha_realizada.date' => 'La fecha realizada debe ser una fecha válida',
            'fecha_realizada.date_format' => 'La fecha realizada debe tener el formato YYYY-MM-DD',
            'ubicacion_real.required' => 'La ubicación real es obligatoria',
            'numero_asistentes_real.required' => 'El número de asistentes real es obligatorio',
            'numero_asistentes_real.min' => 'El número de asistentes real debe ser mayor a 0',
            'descripcion_realizada.required' => 'La descripción realizada es obligatoria',
            'descripcion_realizada.max' => 'La descripción realizada no puede exceder 500 caracteres',
            'obsequio_entregado.max' => 'El obsequio entregado no puede exceder 255 caracteres',
            'evidencias.required' => 'Debe incluir al menos una imagen de evidencia',
            'evidencias.min' => 'Debe incluir al menos una imagen de evidencia',
            'evidencias.max' => 'No puede incluir más de 20 imágenes',
            'evidencias.*.image' => 'Los archivos de evidencia deben ser imágenes',
            'evidencias.*.mimes' => 'Las imágenes deben ser: jpeg, jpg, png, gif o webp',
            'evidencias.*.max' => 'Cada imagen no puede exceder 5MB',
            'listado_asistencia.required' => 'El listado de asistencia es obligatorio',
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
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
