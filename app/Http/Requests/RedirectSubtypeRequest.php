<?php

namespace App\Http\Requests;

use App\Constants\{RequestSubtypes, RequestTypes};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RedirectSubtypeRequest extends FormRequest
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
        // Obtener el tipo de solicitud desde la ruta
        $requestForm = $this->route('request');
        $requestType = $requestForm->request_type ?? null;
        
        $rules = [
            'subtype' => ['required', 'string'],
        ];
        
        // Si tenemos el tipo de solicitud, validar que el subtipo es válido
        if ($requestType && RequestTypes::hasSubtypes($requestType)) {
            $validSubtypes = RequestSubtypes::forRequestType($requestType);
            $rules['subtype'][] = Rule::in($validSubtypes);
        }
        
        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'subtype.required' => 'El subtipo es requerido',
            'subtype.in' => 'El subtipo seleccionado no es válido para este tipo de solicitud',
        ];
    }
}

