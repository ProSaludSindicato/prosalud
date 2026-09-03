<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SubmitAffiliateConvenioSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('convenio_signing.affiliate_submitted_max_kb', 12288);

        return [
            'pdf' => 'required|file|mimes:pdf|max:'.$maxKb,
            'audit_log' => 'required|json',
            'terms_accepted' => 'required|accepted',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pdf.required' => 'No se recibió el archivo PDF.',
            'pdf.mimes' => 'El archivo debe ser un PDF válido.',
            'pdf.max' => 'El PDF excede el tamaño máximo permitido.',
            'audit_log.required' => 'La bitácora de firma es obligatoria.',
            'audit_log.json' => 'La bitácora de firma no tiene un formato válido.',
            'terms_accepted.required' => 'Debe aceptar los términos y condiciones y la política de tratamiento de datos.',
            'terms_accepted.accepted' => 'Debe aceptar los términos y condiciones y la política de tratamiento de datos.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $auditLog = $this->input('audit_log');
        if (is_string($auditLog)) {
            $this->merge([
                'audit_log' => $auditLog,
            ]);
        }
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $decoded = json_decode((string) $this->input('audit_log'), true);
            if (! is_array($decoded)) {
                $validator->errors()->add('audit_log', 'La bitácora de firma no tiene un formato válido.');

                return;
            }

            if (! isset($decoded['sessionId'], $decoded['startedAt'], $decoded['events'], $decoded['summary'])) {
                $validator->errors()->add('audit_log', 'La bitácora de firma está incompleta.');

                return;
            }

            if (! is_array($decoded['events'])) {
                $validator->errors()->add('audit_log', 'Los eventos de la bitácora no son válidos.');

                return;
            }

            if (count($decoded['events']) > 200) {
                $validator->errors()->add('audit_log', 'La bitácora excede el número máximo de eventos permitidos.');

                return;
            }

            foreach ($decoded['events'] as $index => $event) {
                if (! is_array($event) || ! isset($event['type'], $event['timestamp'])) {
                    $validator->errors()->add('audit_log', "El evento #{$index} de la bitácora no es válido.");

                    return;
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedAuditLog(): array
    {
        $decoded = json_decode((string) $this->input('audit_log'), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
