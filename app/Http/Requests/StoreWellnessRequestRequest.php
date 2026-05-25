<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class StoreWellnessRequestRequest extends FormRequest
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
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $centroCostos = $this->input('centroCostos');
        $requiereDetalles = $this->input('requiereDetalles', false);

        $sedesValidas = $this->getSedesValidas($centroCostos);

        $rules = [
            'nombreActividad' => 'required|string|max:200',
            'descripcionActividad' => 'nullable|string|max:300',
            'centroCostos' => 'required|string|in:Bello,Rionegro,La Maria asistencial,La Maria VIH,La Maria Cosalud,La Maria Enterritorio,Carisma,Admon',
            'fechaPropuesta' => [
                'required',
                'date',
                'date_format:Y-m-d',
            ],
            'horaInicio' => 'nullable|date_format:H:i',
            'horaFin' => [
                'nullable',
                'date_format:H:i',
            ],
            'numeroParticipantes' => 'nullable|integer|min:1|max:10000',
            'requiereDetalles' => 'required|boolean',
            'solicitanteId' => 'required|integer|exists:users,id',
        ];

        // Validación condicional de sedes
        if ($centroCostos && $sedesValidas) {
            $rules['sedes'] = [
                'required',
                'array',
                'min:1',
            ];
            $rules['sedes.*'] = [
                'required',
                'string',
                'in:'.implode(',', $sedesValidas),
            ];
        } else {
            $rules['sedes'] = 'nullable|array';
        }

        // Validación condicional de detalles
        if ($requiereDetalles) {
            $rules['detalles'] = [
                'required',
                'array',
                'min:1',
                'max:5',
            ];
            $rules['detalles.*.tipo'] = 'required|string|max:100';
            $rules['detalles.*.cantidad'] = 'required|integer|min:1|max:10000';
        } else {
            $rules['detalles'] = 'nullable|array';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'nombreActividad.required' => 'El nombre de la actividad es obligatorio',
            'nombreActividad.max' => 'El nombre de la actividad no puede exceder 200 caracteres',
            'descripcionActividad.max' => 'La descripción de la actividad no puede exceder 300 caracteres',
            'centroCostos.required' => 'El centro de costos es obligatorio',
            'centroCostos.in' => 'El centro de costos seleccionado no es válido',
            'sedes.required' => 'Debe seleccionar al menos una sede para este centro de costos',
            'sedes.array' => 'Las sedes deben ser un array',
            'sedes.min' => 'Debe seleccionar al menos una sede',
            'sedes.*.required' => 'Cada sede es obligatoria',
            'sedes.*.in' => 'La sede seleccionada no pertenece al centro de costos especificado',
            'fechaPropuesta.required' => 'La fecha propuesta es obligatoria',
            'fechaPropuesta.date' => 'La fecha propuesta debe ser una fecha válida',
            'fechaPropuesta.date_format' => 'La fecha propuesta debe estar en formato YYYY-MM-DD',
            'horaInicio.date_format' => 'La hora de inicio debe estar en formato HH:mm',
            'horaFin.date_format' => 'La hora de fin debe estar en formato HH:mm',
            'horaFin.after' => 'La hora de fin debe ser posterior a la hora de inicio',
            'numeroParticipantes.integer' => 'El número de participantes debe ser un número entero',
            'numeroParticipantes.min' => 'El número de participantes debe ser al menos 1',
            'numeroParticipantes.max' => 'El número de participantes no puede exceder 10000',
            'requiereDetalles.required' => 'El campo requiereDetalles es obligatorio',
            'requiereDetalles.boolean' => 'El campo requiereDetalles debe ser true o false',
            'detalles.required' => 'Debe agregar al menos un detalle cuando requiereDetalles es true',
            'detalles.array' => 'Los detalles deben ser un array',
            'detalles.min' => 'Debe agregar al menos un detalle cuando requiereDetalles es true',
            'detalles.max' => 'Se excedió el límite de 5 detalles/souvenirs',
            'detalles.*.tipo.required' => 'El tipo de detalle es obligatorio',
            'detalles.*.tipo.max' => 'El tipo de detalle no puede exceder 100 caracteres',
            'detalles.*.cantidad.required' => 'La cantidad del detalle es obligatoria',
            'detalles.*.cantidad.integer' => 'La cantidad debe ser un número entero',
            'detalles.*.cantidad.min' => 'La cantidad debe ser mayor a 0',
            'detalles.*.cantidad.max' => 'La cantidad no puede exceder 10000',
            'solicitanteId.required' => 'El ID del solicitante es obligatorio',
            'solicitanteId.exists' => 'El usuario solicitante no existe en el sistema',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'nombreActividad' => 'nombre de la actividad',
            'descripcionActividad' => 'descripción de la actividad',
            'centroCostos' => 'centro de costos',
            'sedes' => 'sedes',
            'fechaPropuesta' => 'fecha propuesta',
            'horaInicio' => 'hora de inicio',
            'horaFin' => 'hora de fin',
            'numeroParticipantes' => 'número de participantes',
            'requiereDetalles' => 'requiere detalles',
            'detalles' => 'detalles',
            'solicitanteId' => 'ID del solicitante',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Validar que horaFin sea posterior a horaInicio cuando ambas están presentes
            if ($this->filled('horaInicio') && $this->filled('horaFin')) {
                try {
                    $horaInicio = Carbon::createFromFormat('H:i', $this->input('horaInicio'));
                    $horaFin = Carbon::createFromFormat('H:i', $this->input('horaFin'));

                    if ($horaFin->lte($horaInicio)) {
                        $validator->errors()->add('horaFin', 'La hora de fin debe ser posterior a la hora de inicio');
                    }
                } catch (\Exception $e) {
                    // Si hay error al parsear, la validación de formato ya lo capturará
                }
            }
        });
    }

    /**
     * Transform validated Spanish field names to English for backend processing.
     */
    public function getTransformedData(): array
    {
        $data = $this->validated();

        // Ensure requester_id is an integer
        $requesterId = is_numeric($data['solicitanteId'])
            ? (int) $data['solicitanteId']
            : $data['solicitanteId'];

        return [
            'activity_name' => $data['nombreActividad'],
            'activity_description' => $data['descripcionActividad'] ?? null,
            'cost_center' => $data['centroCostos'],
            'locations' => $data['sedes'] ?? [],
            'proposed_date' => $data['fechaPropuesta'],
            'start_time' => $data['horaInicio'] ?? null,
            'end_time' => $data['horaFin'] ?? null,
            'participant_count' => $data['numeroParticipantes'] ?? null,
            'requires_details' => $data['requiereDetalles'],
            'requester_id' => $requesterId,
        ];
    }

    /**
     * Get transformed details data.
     */
    public function getTransformedDetails(): array
    {
        $details = [];

        if ($this->input('requiereDetalles') && $this->has('detalles')) {
            foreach ($this->input('detalles', []) as $detalle) {
                $details[] = [
                    'type' => $detalle['tipo'],
                    'quantity' => $detalle['cantidad'],
                ];
            }
        }

        return $details;
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::error('Errores de validación en StoreWellnessRequestRequest', [
            'input' => $this->except(['detalles']),
            'errors' => $validator->errors()->toArray(),
        ]);

        // Determinar código de estado apropiado
        $statusCode = 422;
        $message = 'Datos inválidos';

        // Si hay errores básicos de formato, usar 400
        $errors = $validator->errors()->toArray();
        $basicErrors = ['nombreActividad', 'centroCostos', 'fechaPropuesta', 'solicitanteId', 'requiereDetalles'];
        if (count(array_intersect_key($errors, array_flip($basicErrors))) > 0) {
            $statusCode = 400;
            $message = 'Error de validación';
        }

        throw new HttpResponseException(response()->json(['success' => false, 'message' => $message, 'errors' => $errors], $statusCode));
    }

    /**
     * Get valid locations for a cost center.
     */
    private function getSedesValidas(?string $centroCostos): ?array
    {
        return match ($centroCostos) {
            'Bello' => ['Niquia', 'Autopista'],
            'Rionegro' => ['Jorge Humberto', 'Gilberto Mejía'],
            'La Maria asistencial' => ['Castilla', 'La 33'],
            'La Maria VIH' => ['Castilla', 'La 33'],
            'La Maria Cosalud' => ['Castilla', 'La 33'],
            'La Maria Enterritorio' => ['Castilla', 'La 33'],
            'Carisma' => ['Principal'],
            'Admon' => ['Principal'],
            default => null,
        };
    }
}
