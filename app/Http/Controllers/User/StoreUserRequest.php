<?php

namespace App\Http\Controllers\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Log;

class StoreUserRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|max:255',
            // La contraseña no se define en la creación; el usuario la configurará mediante un flujo de invitación
            'is_active' => 'boolean',
            'role' => 'required|string|exists:roles,name',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.string' => 'El nombre debe ser una cadena de texto.',
            'name.max' => 'El nombre no puede exceder los 255 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe tener un formato válido.',
            'email.unique' => 'Este usuario ya está registrado.',
            'email.max' => 'El correo electrónico no puede exceder los 255 caracteres.',
            'is_active.boolean' => 'El estado activo debe ser verdadero o falso.',
            'role.required' => 'El rol es obligatorio.',
            'role.string' => 'El rol debe ser una cadena de texto.',
            'role.exists' => 'El rol seleccionado no existe.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'is_active' => 'estado activo',
            'role' => 'rol',
        ];
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::error('Errores de validación en StoreUserRequest', [
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

