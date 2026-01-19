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

        // Si el estado es REJECTED, la razón de rechazo es requerida
        if ($this->input('status') === 'REJECTED') {
            $rules['rejection_reason'] = 'required|string|max:1000';
        } else {
            $rules['rejection_reason'] = 'nullable|string|max:1000';
        }

        // Validar actividades si vienen en el request (para certificados con actividades)
        if ($this->has('actividades')) {
            $rules['actividades'] = 'required|array|min:1';
            $rules['actividades.*'] = 'required|string|min:1|max:500';
        }

        // Validar compensaciones si vienen en el request (opcionales, solo si están presentes)
        // Estos campos son opcionales porque el sistema intentará obtenerlos del Excel si no se proporcionan
        if ($this->has('t_basicos') || $this->has('t_auxilios')) {
            $rules['t_basicos'] = 'nullable|integer|min:0';
            $rules['t_auxilios'] = 'nullable|integer|min:0';
        }

        if ($this->hasFile('attachments')) {
            $rules['attachments'] = 'nullable|array|max:4';
            // Permitir hasta 20MB para archivos comprimidos, la validación individual se hace en withValidator
            $rules['attachments.*'] = 'file|max:20480|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,zip,rar';
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
            'rejection_reason.required' => 'La razón de rechazo es obligatoria cuando se rechaza una solicitud.',
            'rejection_reason.string' => 'La razón de rechazo debe ser una cadena de texto.',
            'rejection_reason.max' => 'La razón de rechazo no puede exceder 1000 caracteres.',
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
            't_basicos.integer' => 'El valor de T. Basicos debe ser un número entero.',
            't_basicos.min' => 'El valor de T. Basicos no puede ser negativo.',
            't_auxilios.integer' => 'El valor de T. Auxilios debe ser un número entero.',
            't_auxilios.min' => 'El valor de T. Auxilios no puede ser negativo.',
            'attachments.array' => 'Los archivos adjuntos deben ser un array.',
            'attachments.max' => 'No se pueden adjuntar más de 4 archivos.',
            'attachments.*.file' => 'Cada archivo adjunto debe ser un archivo válido.',
            'attachments.*.max' => 'Cada archivo adjunto no puede exceder 20MB. Los archivos comprimidos (.zip, .rar) pueden pesar hasta 20MB, mientras que otros archivos pueden pesar hasta 5MB.',
            'attachments.*.mimes' => 'Los archivos adjuntos solo pueden ser: pdf, doc, docx, xls, xlsx, jpg, jpeg, png, webp, zip, rar.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'status' => 'estado',
            'rejection_reason' => 'razón de rechazo',
            'email_subject' => 'asunto del correo',
            'email_body' => 'cuerpo del correo',
            'actividades' => 'actividades',
            'actividades.*' => 'actividad',
            't_basicos' => 'T. Basicos',
            't_auxilios' => 'T. Auxilios',
            'attachments' => 'archivos adjuntos',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validar archivos adjuntos
            if ($this->hasFile('attachments')) {
                $attachments = $this->file('attachments');
                $totalSize = 0;
                $maxTotalSizeKB = 20480; // 20MB en KB
                $maxTotalSizeMB = 20;
                $compressedExtensions = ['zip', 'rar'];
                $hasCompressed = false;

                // Normalizar a array si es un solo archivo
                if (!is_array($attachments)) {
                    $attachments = [$attachments];
                }

                // Detectar archivos comprimidos y validar tamaños individuales
                foreach ($attachments as $index => $file) {
                    if ($file && $file->isValid()) {
                        $extension = strtolower($file->getClientOriginalExtension() ?? '');
                        $fileSize = $file->getSize(); // getSize() retorna bytes
                        $fileSizeKB = $fileSize / 1024;
                        $fileSizeMB = $fileSizeKB / 1024;

                        // Verificar si es archivo comprimido
                        if (in_array($extension, $compressedExtensions)) {
                            $hasCompressed = true;

                            // Validar tamaño individual de archivos comprimidos (máximo 20MB)
                            if ($fileSizeKB > $maxTotalSizeKB) {
                                $fileSizeMBRounded = round($fileSizeMB, 2);
                                $validator->errors()->add(
                                    'attachments.' . $index,
                                    "El archivo comprimido '{$file->getClientOriginalName()}' ({$fileSizeMBRounded}MB) excede el límite máximo permitido de {$maxTotalSizeMB}MB."
                                );
                            }
                        } else {
                            // Validar tamaño individual de archivos no comprimidos (máximo 5MB = 5120 KB)
                            $maxNonCompressedKB = 5120;
                            if ($fileSizeKB > $maxNonCompressedKB) {
                                $fileSizeMBRounded = round($fileSizeMB, 2);
                                $validator->errors()->add(
                                    'attachments.' . $index,
                                    "El archivo '{$file->getClientOriginalName()}' ({$fileSizeMBRounded}MB) excede el límite máximo permitido de 5MB."
                                );
                            }
                        }

                        $totalSize += $fileSize;
                    }
                }

                // Si hay archivos comprimidos, solo se permite un archivo en total
                if ($hasCompressed && count($attachments) > 1) {
                    $validator->errors()->add(
                        'attachments',
                        'Cuando se adjunta un archivo comprimido (.zip o .rar), solo se permite un archivo. No se pueden adjuntar múltiples archivos si uno de ellos es un comprimido.'
                    );
                }

                // Validar tamaño total (máximo 20MB = 20480 KB)
                $totalSizeKB = $totalSize / 1024;
                if ($totalSizeKB > $maxTotalSizeKB) {
                    $totalSizeMBRounded = round($totalSizeKB / 1024, 2);
                    $validator->errors()->add(
                        'attachments',
                        "El tamaño total de los archivos adjuntos ({$totalSizeMBRounded}MB) excede el límite máximo permitido de {$maxTotalSizeMB}MB. Esto podría causar problemas al enviar el correo electrónico."
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
