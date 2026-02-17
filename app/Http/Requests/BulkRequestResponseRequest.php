<?php

namespace App\Http\Requests;

use App\Constants\{RequestStatuses, RequestSubtypes, RequestTypes};
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BulkRequestResponseRequest extends FormRequest
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
        $validRequestTypes = array_merge(RequestTypes::all(), ['all']);
        $validStatuses = [
            RequestStatuses::PENDING,
            RequestStatuses::IN_REVIEW,
            RequestStatuses::COMPLETED,
            RequestStatuses::REJECTED,
            'all',
        ];

        return [
            'request_type' => [
                'sometimes',
                'string',
                Rule::in($validRequestTypes),
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in($validStatuses),
            ],
            'subtype' => [
                'sometimes',
                'string',
                function ($attribute, $value, $fail) {
                    if ($value === 'all' || empty($value)) {
                        return;
                    }
                    // Validar que el subtipo sea válido para algún tipo de solicitud
                    $allSubtypes = RequestSubtypes::all();
                    if (!in_array($value, $allSubtypes, true)) {
                        $fail('El subtipo seleccionado no es válido.');
                    }
                },
            ],
            'date_range.include_all' => [
                'sometimes',
                'boolean',
            ],
            'date_range.start_date' => [
                'required_if:date_range.include_all,false',
                'nullable',
                'date',
            ],
            'date_range.end_date' => [
                'required_if:date_range.include_all,false',
                'nullable',
                'date',
                'after_or_equal:date_range.start_date',
            ],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize request_type to canonical value before validation
        $requestType = $this->input('request_type');
        if ($requestType && $requestType !== 'all') {
            $normalizedRequestType = RequestTypes::normalize($requestType);
            if ($normalizedRequestType !== $requestType) {
                $this->merge(['request_type' => $normalizedRequestType]);
            }
        }
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'request_type.in' => 'El tipo de solicitud seleccionado no es válido.',
            'request_type.string' => 'El tipo de solicitud debe ser una cadena de texto.',
            'status.in' => 'El estado seleccionado no es válido.',
            'status.string' => 'El estado debe ser una cadena de texto.',
            'subtype.string' => 'El subtipo debe ser una cadena de texto.',
            'date_range.start_date.required_if' => 'La fecha de inicio es requerida cuando no se incluyen todos los registros.',
            'date_range.end_date.required_if' => 'La fecha de fin es requerida cuando no se incluyen todos los registros.',
            'date_range.end_date.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
            'date_range.start_date.date' => 'La fecha de inicio debe tener un formato de fecha válido.',
            'date_range.end_date.date' => 'La fecha de fin debe tener un formato de fecha válido.',
            'date_range.include_all.boolean' => 'El campo include_all debe ser verdadero o falso.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors()->toArray();
        $requestType = $this->input('request_type', 'no proporcionado');
        $validTypes = array_merge(RequestTypes::all(), ['all']);
        $validTypesList = implode(', ', $validTypes);

        // Mejorar mensaje de error para request_type
        if (isset($errors['request_type'])) {
            $errors['request_type'] = [
                "El tipo de solicitud '{$requestType}' no es válido. Valores válidos: {$validTypesList}"
            ];
        }

        Log::warning('Errores de validación en BulkRequestResponseRequest', [
            'input' => $this->all(),
            'request_type_received' => $requestType,
            'valid_request_types' => $validTypes,
            'errors' => $errors,
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Error de validación en los parámetros enviados',
                'errors' => $errors,
                'request_type_received' => $requestType,
                'valid_request_types' => $validTypes,
            ], 422)
        );
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'request_type' => 'tipo de solicitud',
            'status' => 'estado',
            'subtype' => 'subtipo de solicitud',
            'date_range.include_all' => 'incluir todos los registros',
            'date_range.start_date' => 'fecha de inicio',
            'date_range.end_date' => 'fecha de fin',
        ];
    }
}

