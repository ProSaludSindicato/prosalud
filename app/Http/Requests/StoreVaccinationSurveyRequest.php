<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\App as LaravelApp;

class StoreVaccinationSurveyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Asegurar mensajes de validación en español
        LaravelApp::setLocale('es');

        $data = $this->all();

        // Normalizar tipo_documento y nombres/apellidos
        $normalized = [];

        if (isset($data['tipo_documento']) && is_string($data['tipo_documento'])) {
            $normalized['tipo_documento'] = strtoupper(trim($data['tipo_documento']));
        }

        foreach (['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $normalized[$field] = mb_strtoupper(trim($data[$field]), 'UTF-8');
            }
        }

        // Asegurar que campos opcionales de fechas se conviertan a null si vienen como cadena vacía
        foreach (['fecha_aplicacion_srp', 'fecha_aplicacion_sr', 'fecha_aplicacion_fiebre_amarilla'] as $dateField) {
            if (array_key_exists($dateField, $data) && $data[$dateField] === '') {
                $normalized[$dateField] = null;
            }
        }

        if (!empty($normalized)) {
            $this->merge($normalized);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Identificación del afiliado
            'tipo_documento' => 'required|string|in:CC,CE,PT',
            'numero_documento' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],

            // Datos personales (autocompletados)
            'fecha_nacimiento' => 'required|date|date_format:Y-m-d|before:today',
            'primer_nombre' => 'required|string|max:100',
            'segundo_nombre' => 'nullable|string|max:150',
            'primer_apellido' => 'required|string|max:100',
            'segundo_apellido' => 'nullable|string|max:150',

            // Fechas de vacunación (opcionales)
            'fecha_aplicacion_srp' => 'nullable|date|date_format:Y-m-d',
            'fecha_aplicacion_sr' => 'nullable|date|date_format:Y-m-d',
            'fecha_aplicacion_fiebre_amarilla' => 'nullable|date|date_format:Y-m-d',

            // Firma digital
            'firma' => ['required', 'string', 'regex:/^data:image\/png;base64,/'],
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

    /**
     * Custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'tipo_documento' => 'tipo de documento',
            'numero_documento' => 'número de documento',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'primer_nombre' => 'primer nombre',
            'segundo_nombre' => 'segundo nombre',
            'primer_apellido' => 'primer apellido',
            'segundo_apellido' => 'segundo apellido',
            'fecha_aplicacion_srp' => 'fecha de aplicación SRP',
            'fecha_aplicacion_sr' => 'fecha de aplicación SR',
            'fecha_aplicacion_fiebre_amarilla' => 'fecha de aplicación Fiebre Amarilla',
            'firma' => 'firma digital',
        ];
    }
}
