<?php

namespace App\Http\Controllers\Request;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreRequestFormRequest extends FormRequest
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
            'request_type' => 'required|string|max:255',
            'id_type' => 'required|string|max:255',
            'id_number' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone_number' => 'required|string|max:255',
            'payload' => 'nullable|array',
            'files' => 'nullable|array',
            'files.*' => 'nullable|file|max:10240',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'request_type.required' => 'El tipo de solicitud es obligatorio.',
            'id_type.required' => 'El tipo de identificación es obligatorio.',
            'id_number.required' => 'El número de identificación es obligatorio.',
            'name.required' => 'El nombre es obligatorio.',
            'last_name.required' => 'El apellido es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe tener un formato válido.',
            'phone_number.required' => 'El número de teléfono es obligatorio.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'request_type' => 'tipo de solicitud',
            'id_type' => 'tipo de identificación',
            'id_number' => 'número de identificación',
            'name' => 'nombre',
            'last_name' => 'apellido',
            'email' => 'correo electrónico',
            'phone_number' => 'número de teléfono',
            'payload' => 'datos adicionales',
            'files' => 'archivos',
        ];
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::error('Errores de validación en StoreRequestFormRequest', [
            'input' => $this->all(),
            'errors' => $validator->errors()->toArray()
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
