<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UpdateWellnessRequestRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $centroCostos = $this->input('centroCostos');
        $requiereDetalles = $this->input('requiereDetalles');

        // Definir sedes válidas por centro de costos
        $sedesValidas = $this->getSedesValidas($centroCostos);

        $rules = [
            'nombreActividad' => 'sometimes|required|string|max:200',
            'descripcionActividad' => 'nullable|string|max:300',
            'centroCostos' => 'sometimes|required|string|in:Bello,Rionegro,La Maria,Admon',
            'fechaPropuesta' => [
                'sometimes',
                'required',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'horaInicio' => 'nullable|date_format:H:i',
            'horaFin' => [
                'nullable',
                'date_format:H:i',
            ],
            'numeroParticipantes' => 'nullable|integer|min:1|max:10000',
            'requiereDetalles' => 'sometimes|required|boolean',
            'estado' => 'sometimes|required|string|in:pending,in_progress,resolved,rejected',
        ];

        // Validación condicional de sedes (solo si se proporciona centroCostos)
        if ($centroCostos) {
            if ($sedesValidas) {
                $rules['sedes'] = [
                    'required',
                    'array',
                    'min:1',
                ];
                $rules['sedes.*'] = [
                    'required',
                    'string',
                    'in:' . implode(',', $sedesValidas),
                ];
            } else {
                $rules['sedes'] = 'nullable|array';
            }
        } else {
            $rules['sedes'] = 'nullable|array';
        }

        // Validación condicional de detalles
        if ($requiereDetalles !== null) {
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
            'fechaPropuesta.after_or_equal' => 'La fecha propuesta no puede ser anterior a hoy',
            'horaInicio.date_format' => 'La hora de inicio debe estar en formato HH:mm',
            'horaFin.date_format' => 'La hora de fin debe estar en formato HH:mm',
            'horaFin.after' => 'La hora de fin debe ser posterior a la hora de inicio',
            'numeroParticipantes.integer' => 'El número de participantes debe ser un número entero',
            'numeroParticipantes.min' => 'El número de participantes debe ser al menos 1',
            'numeroParticipantes.max' => 'El número de participantes no puede exceder 10000',
            'requiereDetalles.required' => 'El campo requiereDetalles es obligatorio',
            'requiereDetalles.boolean' => 'El campo requiereDetalles debe ser true o false',
            'estado.required' => 'El campo estado es obligatorio',
            'estado.in' => 'El estado seleccionado no es válido. Debe ser: pending, in_progress, resolved o rejected',
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
            'estado' => 'estado',
            'detalles' => 'detalles',
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
     * Handle a failed validation attempt.
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::error('Errores de validación en UpdateWellnessRequestRequest', [
            'input' => $this->except(['detalles']),
            'errors' => $validator->errors()->toArray()
        ]);

        // Determinar código de estado apropiado
        $statusCode = 422;
        $message = 'Datos inválidos';

        // Si hay errores básicos de formato, usar 400
        $errors = $validator->errors()->toArray();
        $basicErrors = ['nombreActividad', 'centroCostos', 'fechaPropuesta', 'requiereDetalles'];
        if (count(array_intersect_key($errors, array_flip($basicErrors))) > 0) {
            $statusCode = 400;
            $message = 'Error de validación';
        }

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $errors,
            ], $statusCode)
        );
    }

    /**
     * Get valid locations for a cost center
     */
    private function getSedesValidas(?string $centroCostos): ?array
    {
        return match($centroCostos) {
            'Bello' => ['Niquia', 'Autopista'],
            'Rionegro' => ['Principal'],
            'La Maria' => ['Castilla', 'Transmisibles', 'Sede la 33'],
            'Admon' => ['Principal'],
            default => null,
        };
    }

    /**
     * Transform validated Spanish field names to English for backend processing
     * 
     * @return array
     */
    public function getTransformedData(): array
    {
        $data = $this->validated();
        
        $transformed = [];
        
        if (isset($data['nombreActividad'])) {
            $transformed['activity_name'] = $data['nombreActividad'];
        }
        
        if (isset($data['descripcionActividad'])) {
            $transformed['activity_description'] = $data['descripcionActividad'] ?? null;
        }
        
        if (isset($data['centroCostos'])) {
            $transformed['cost_center'] = $data['centroCostos'];
        }
        
        if (isset($data['sedes'])) {
            $transformed['locations'] = $data['sedes'] ?? [];
        }
        
        if (isset($data['fechaPropuesta'])) {
            $transformed['proposed_date'] = $data['fechaPropuesta'];
        }
        
        if (isset($data['horaInicio'])) {
            $transformed['start_time'] = $data['horaInicio'] ?? null;
        }
        
        if (isset($data['horaFin'])) {
            $transformed['end_time'] = $data['horaFin'] ?? null;
        }
        
        if (isset($data['numeroParticipantes'])) {
            $transformed['participant_count'] = $data['numeroParticipantes'] ?? null;
        }
        
        if (isset($data['requiereDetalles'])) {
            $transformed['requires_details'] = $data['requiereDetalles'];
        }
        
        if (isset($data['estado'])) {
            $transformed['status'] = $data['estado'];
        }
        
        return $transformed;
    }

    /**
     * Get transformed details data
     * 
     * @return array
     */
    public function getTransformedDetails(): array
    {
        $details = [];
        
        if ($this->has('detalles') && is_array($this->input('detalles'))) {
            foreach ($this->input('detalles') as $detalle) {
                $details[] = [
                    'type' => $detalle['tipo'],
                    'quantity' => $detalle['cantidad'],
                ];
            }
        }
        
        return $details;
    }
}

