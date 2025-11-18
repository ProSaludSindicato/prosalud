<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Allow public voting
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Voter information
            'voter.documentType' => 'required|string|max:10',
            'voter.documentNumber' => 'required|string|max:20',
            'voter.hospital' => 'required|string|max:100',
            'voter.position' => 'required|string|max:100',

            // Candidate information
            'candidate.id' => 'required|string|max:20',
            'candidate.name' => 'required|string|max:200',
            'candidate.position' => 'required|string|max:100',
            'candidate.hospital' => 'required|string|max:100',

            // Timestamp
            'timestamp' => 'required|date|date_format:Y-m-d\TH:i:s.v\Z',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'voter.documentType.required' => 'El tipo de documento del votante es obligatorio.',
            'voter.documentNumber.required' => 'El número de documento del votante es obligatorio.',
            'voter.hospital.required' => 'El hospital del votante es obligatorio.',
            'voter.position.required' => 'El cargo del votante es obligatorio.',

            'candidate.id.required' => 'El ID del candidato es obligatorio.',
            'candidate.name.required' => 'El nombre del candidato es obligatorio.',
            'candidate.position.required' => 'El cargo del candidato es obligatorio.',
            'candidate.hospital.required' => 'El hospital del candidato es obligatorio.',

            'timestamp.required' => 'La fecha y hora del voto es obligatoria.',
            'timestamp.date' => 'La fecha y hora del voto debe ser una fecha válida.',
            'timestamp.date_format' => 'La fecha y hora del voto debe estar en formato ISO 8601.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'voter.documentType' => 'tipo de documento del votante',
            'voter.documentNumber' => 'número de documento del votante',
            'voter.hospital' => 'hospital del votante',
            'voter.position' => 'cargo del votante',

            'candidate.id' => 'ID del candidato',
            'candidate.name' => 'nombre del candidato',
            'candidate.position' => 'cargo del candidato',
            'candidate.hospital' => 'hospital del candidato',

            'timestamp' => 'fecha y hora del voto',
        ];
    }
}
