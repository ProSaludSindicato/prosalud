<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class RespondToRequestRequest extends FormRequest
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
        $rules = [
            'status' => 'required|string|in:PENDING,IN_REVIEW,REJECTED,COMPLETED',
            'email_subject' => 'required|string|max:100',
            'email_body' => 'required|string|max:5000',
        ];

        // Validar actividades si vienen en el request (para certificados con actividades)
        if ($this->has('actividades')) {
            $rules['actividades'] = 'required|array|min:1';
            $rules['actividades.*'] = 'required|string|min:1|max:500';
        }

        if ($this->hasFile('attachments')) {
            $rules['attachments'] = 'nullable|array|max:4';
            $rules['attachments.*'] = 'file|max:5120|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'El estado es obligatorio.',
            'status.in' => 'El estado debe ser uno de: PENDING, IN_REVIEW, REJECTED, COMPLETED.',
            'email_subject.required' => 'El asunto del correo es obligatorio.',
            'email_subject.max' => 'El asunto del correo no puede exceder 100 caracteres.',
            'email_body.required' => 'El cuerpo del correo es obligatorio.',
            'email_body.max' => 'El cuerpo del correo no puede exceder 5000 caracteres.',
            'actividades.required' => 'Debe incluir al menos una actividad.',
            'actividades.array' => 'Las actividades deben ser un array.',
            'actividades.min' => 'Debe incluir al menos una actividad.',
            'actividades.*.required' => 'Cada actividad es obligatoria.',
            'actividades.*.string' => 'Cada actividad debe ser texto.',
            'actividades.*.min' => 'Cada actividad debe tener al menos 1 carácter.',
            'actividades.*.max' => 'Cada actividad no puede exceder 500 caracteres.',
            'attachments.array' => 'Los archivos adjuntos deben ser un array.',
            'attachments.max' => 'No se pueden adjuntar más de 4 archivos.',
            'attachments.*.file' => 'Cada archivo adjunto debe ser un archivo válido.',
            'attachments.*.max' => 'Cada archivo adjunto no puede exceder 5MB.',
            'attachments.*.mimes' => 'Los archivos adjuntos solo pueden ser: pdf, doc, docx, xls, xlsx, jpg, jpeg, png, webp.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'status' => 'estado',
            'email_subject' => 'asunto del correo',
            'email_body' => 'cuerpo del correo',
            'actividades' => 'actividades',
            'actividades.*' => 'actividad',
            'attachments' => 'archivos adjuntos',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validar tamaño total de archivos adjuntos (máximo 20MB = 20480 KB)
            if ($this->hasFile('attachments')) {
                $attachments = $this->file('attachments');
                $totalSize = 0;
                $maxTotalSizeKB = 20480; // 20MB en KB
                $maxTotalSizeMB = 20;

                // Normalizar a array si es un solo archivo
                if (!is_array($attachments)) {
                    $attachments = [$attachments];
                }

                // Calcular tamaño total
                foreach ($attachments as $file) {
                    if ($file && $file->isValid()) {
                        $totalSize += $file->getSize(); // getSize() retorna bytes
                    }
                }

                // Convertir bytes a KB
                $totalSizeKB = $totalSize / 1024;

                // Validar tamaño total
                if ($totalSizeKB > $maxTotalSizeKB) {
                    $totalSizeMB = round($totalSizeKB / 1024, 2);
                    $validator->errors()->add(
                        'attachments',
                        "El tamaño total de los archivos adjuntos ({$totalSizeMB}MB) excede el límite máximo permitido de {$maxTotalSizeMB}MB. Esto podría causar problemas al enviar el correo electrónico."
                    );
                }
            }

            // Validar que actividades no esté vacío si viene en el request
            if ($this->has('actividades')) {
                $actividades = $this->input('actividades', []);
                if (empty($actividades) || (is_array($actividades) && count(array_filter($actividades, fn($a) => !empty(trim($a ?? '')))) === 0)) {
                    $validator->errors()->add('actividades', 'Debe incluir al menos una actividad.');
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
        Log::error('Errores de validación en RespondToRequestRequest', [
            'input' => $this->except(['attachments']),
            'errors' => $validator->errors()->toArray(),
        ]);

        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422));
    }
}
