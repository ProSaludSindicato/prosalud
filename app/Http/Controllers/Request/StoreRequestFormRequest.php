<?php

namespace App\Http\Controllers\Request;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreRequestFormRequest extends FormRequest
{
    /**
     * Stop validation on first failure.
     */
    protected $stopOnFirstFailure = true;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     * Laravel automatically converts FormData payload[campo] to payload.campo,
     * and files[certificacionBancaria] to files.certificacionBancaria.
     * This method ensures the data structure is consistent for validation.
     */
    protected function prepareForValidation(): void
    {
        // Laravel automatically handles FormData payload[campo] as payload.campo
        // When we access $this->input('payload'), it should already be an array
        // But we need to ensure it's properly structured for validation

        $allInput = $this->all();

        // Build payload array from payload.* keys if payload is not already an array
        if (!isset($allInput['payload']) || !is_array($allInput['payload'])) {
            $payload = [];
            foreach ($allInput as $key => $value) {
                // Laravel converts payload[campo] to 'payload.campo' in the input
                if (strpos($key, 'payload.') === 0) {
                    $payloadKey = substr($key, 8); // Remove 'payload.' prefix
                    $payload[$payloadKey] = $value;
                }
            }
            if (!empty($payload)) {
                $this->merge(['payload' => $payload]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $requestType = $this->input('request_type');
        $rules = [
            'request_type' => 'required|string|max:255',
            'id_type' => 'required|string|max:255',
            'id_number' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone_number' => 'required|string|max:255',
            'payload' => 'nullable|array',
            'files' => 'nullable|array',
            'files.*' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
        ];

        // Validaciones específicas para actualizar-datos-personales
        if ($requestType === 'actualizar-datos-personales') {
            $payload = $this->input('payload', []);
            
            $rules = array_merge($rules, [
                // Campos siempre requeridos
                'payload.proceso' => 'required|string|max:255',
                'payload.dondeRealizaProceso' => 'required|string|max:255',

                // Campos opcionales del payload
                'payload.estadoCivil' => 'nullable|string|in:soltero,casado,union_libre,divorciado,viudo',
                'payload.direccion' => 'nullable|string|max:500',
                'payload.municipio' => 'nullable|string|max:255',
                'payload.telefonoFijo' => 'nullable|string|max:20',
                'payload.celular' => 'nullable|string|max:20',
                'payload.correo' => 'nullable|email|max:255',
                'payload.tallaUniforme' => 'nullable|string|in:xs,s,m,l,xl,xxl,xxxl,4xl,5xl',
                'payload.tallaCalzado' => 'nullable|string|max:10',
                
                // Campos condicionales - Nivel educativo
                'payload.nivelEducativo' => 'nullable|string|in:primaria,secundaria,tecnico,tecnologo,pregrado,especializacion,maestria,doctorado',
                
                // Campos condicionales - Cuenta bancaria (si se envía numeroCuenta, los demás son requeridos)
                'payload.numeroCuenta' => 'nullable|string|max:255',
                'payload.tipoCuenta' => 'nullable|string|in:ahorros,corriente',
                'payload.banco' => 'nullable|string|in:bancolombia,davivienda,bbva,bogota,occidente,popular,av_villas,caja_social,colpatria,agrario,cooperativo,otros',
                
                // Campos condicionales - EPS y AFP
                'payload.eps' => 'nullable|string|in:sura,nueva_eps,sanitas,coomeva,compensar,famisanar,savia,aliansalud,otros',
                'payload.afp' => 'nullable|string|in:proteccion,porvenir,colfondos,old_mutual,skandia,otros',
                
                // Archivos condicionales
                'files.certificacionBancaria' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
                'files.diplomaEducativo' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
                'files.actaGrado' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
                'files.certificadoEps' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
                'files.certificadoAfp' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
            ]);
        }

        return $rules;
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $requestType = $this->input('request_type');
            
            if ($requestType === 'actualizar-datos-personales') {
                $payload = $this->input('payload', []);
                $allFiles = $this->allFiles();
                
                // Helper para verificar si existe un archivo
                $hasFile = function($fileKey) use ($allFiles) {
                    // Extraer el nombre del archivo si viene con notación files.archivo
                    $actualKey = str_replace('files.', '', $fileKey);
                    
                    // Verificar si está directamente en allFiles (files.certificacionBancaria)
                    if (isset($allFiles[$fileKey])) {
                        return true;
                    }
                    
                    // Verificar si está en el array files (files[certificacionBancaria])
                    if (isset($allFiles['files']) && is_array($allFiles['files']) && isset($allFiles['files'][$actualKey])) {
                        return true;
                    }
                    
                    // Verificar con el nombre del archivo directamente
                    if (isset($allFiles[$actualKey])) {
                        return true;
                    }
                    
                    return false;
                };
                
                // Validación condicional: Si se envía numeroCuenta, tipoCuenta, banco y certificacionBancaria son requeridos
                if (!empty($payload['numeroCuenta'])) {
                    if (empty($payload['tipoCuenta'])) {
                        $validator->errors()->add('payload.tipoCuenta', 'El tipo de cuenta es obligatorio cuando se actualiza el número de cuenta.');
                    }
                    if (empty($payload['banco'])) {
                        $validator->errors()->add('payload.banco', 'El banco es obligatorio cuando se actualiza el número de cuenta.');
                    }
                    if (!$hasFile('files.certificacionBancaria') && !$hasFile('certificacionBancaria')) {
                        $validator->errors()->add('files.certificacionBancaria', 'La certificación bancaria es obligatoria cuando se actualiza el número de cuenta.');
                    }
                }
                
                // Validación condicional: Si se actualiza nivelEducativo, diploma y acta son requeridos
                if (!empty($payload['nivelEducativo'])) {
                    if (!$hasFile('files.diplomaEducativo') && !$hasFile('diplomaEducativo')) {
                        $validator->errors()->add('files.diplomaEducativo', 'El diploma educativo es obligatorio cuando se actualiza el nivel educativo.');
                    }
                    if (!$hasFile('files.actaGrado') && !$hasFile('actaGrado')) {
                        $validator->errors()->add('files.actaGrado', 'El acta de grado es obligatoria cuando se actualiza el nivel educativo.');
                    }
                }
                
                // Validación condicional: Si se cambia EPS, certificadoEps es requerido
                if (!empty($payload['eps'])) {
                    if (!$hasFile('files.certificadoEps') && !$hasFile('certificadoEps')) {
                        $validator->errors()->add('files.certificadoEps', 'El certificado de EPS es obligatorio cuando se actualiza la EPS.');
                    }
                }
                
                // Validación condicional: Si se cambia AFP, certificadoAfp es requerido
                if (!empty($payload['afp'])) {
                    if (!$hasFile('files.certificadoAfp') && !$hasFile('certificadoAfp')) {
                        $validator->errors()->add('files.certificadoAfp', 'El certificado de AFP es obligatorio cuando se actualiza la AFP.');
                    }
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        $messages = [
            'request_type.required' => 'El tipo de solicitud es obligatorio.',
            'id_type.required' => 'El tipo de identificación es obligatorio.',
            'id_number.required' => 'El número de identificación es obligatorio.',
            'name.required' => 'El nombre es obligatorio.',
            'last_name.required' => 'El apellido es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe tener un formato válido.',
            'phone_number.required' => 'El número de teléfono es obligatorio.',
            'files.*.max' => 'El archivo no puede exceder 4MB.',
            'files.*.mimes' => 'El archivo debe ser PDF, Word o imagen (JPG, PNG, GIF, WEBP).',
        ];

        // Mensajes específicos para actualizar-datos-personales
        if ($this->input('request_type') === 'actualizar-datos-personales') {
            $messages = array_merge($messages, [
                'payload.proceso.required' => 'El proceso es obligatorio.',
                'payload.dondeRealizaProceso.required' => 'El campo donde realiza el proceso es obligatorio.',
                'payload.nivelEducativo.in' => 'El nivel educativo seleccionado no es válido.',
                'payload.tipoCuenta.required_with' => 'El tipo de cuenta es obligatorio cuando se actualiza el número de cuenta.',
                'payload.tipoCuenta.in' => 'El tipo de cuenta seleccionado no es válido.',
                'payload.banco.required_with' => 'El banco es obligatorio cuando se actualiza el número de cuenta.',
                'payload.banco.in' => 'El banco seleccionado no es válido.',
                'payload.eps.in' => 'La EPS seleccionada no es válida.',
                'payload.afp.in' => 'La AFP seleccionada no es válida.',
                'payload.estadoCivil.in' => 'El estado civil seleccionado no es válido.',
                'payload.correo.email' => 'El correo electrónico debe tener un formato válido.',
                'payload.tallaUniforme.in' => 'La talla de uniforme seleccionada no es válida.',
                'files.certificacionBancaria.required_with' => 'La certificación bancaria es obligatoria cuando se actualiza el número de cuenta.',
                'files.certificacionBancaria.max' => 'La certificación bancaria no puede exceder 4MB.',
                'files.certificacionBancaria.mimes' => 'La certificación bancaria debe ser PDF, Word o imagen.',
                'files.diplomaEducativo.required_with' => 'El diploma educativo es obligatorio cuando se actualiza el nivel educativo.',
                'files.diplomaEducativo.max' => 'El diploma educativo no puede exceder 4MB.',
                'files.diplomaEducativo.mimes' => 'El diploma educativo debe ser PDF, Word o imagen.',
                'files.actaGrado.required_with' => 'El acta de grado es obligatoria cuando se actualiza el nivel educativo.',
                'files.actaGrado.max' => 'El acta de grado no puede exceder 4MB.',
                'files.actaGrado.mimes' => 'El acta de grado debe ser PDF, Word o imagen.',
                'files.certificadoEps.required_with' => 'El certificado de EPS es obligatorio cuando se actualiza la EPS.',
                'files.certificadoEps.max' => 'El certificado de EPS no puede exceder 4MB.',
                'files.certificadoEps.mimes' => 'El certificado de EPS debe ser PDF, Word o imagen.',
                'files.certificadoAfp.required_with' => 'El certificado de AFP es obligatorio cuando se actualiza la AFP.',
                'files.certificadoAfp.max' => 'El certificado de AFP no puede exceder 4MB.',
                'files.certificadoAfp.mimes' => 'El certificado de AFP debe ser PDF, Word o imagen.',
            ]);
        }

        return $messages;
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        $attributes = [
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

        if ($this->input('request_type') === 'actualizar-datos-personales') {
            $attributes = array_merge($attributes, [
                'payload.proceso' => 'proceso',
                'payload.dondeRealizaProceso' => 'donde realiza el proceso',
                'payload.estadoCivil' => 'estado civil',
                'payload.direccion' => 'dirección',
                'payload.municipio' => 'municipio',
                'payload.telefonoFijo' => 'teléfono fijo',
                'payload.celular' => 'celular',
                'payload.correo' => 'correo electrónico',
                'payload.tallaUniforme' => 'talla de uniforme',
                'payload.nivelEducativo' => 'nivel educativo',
                'payload.numeroCuenta' => 'número de cuenta',
                'payload.tipoCuenta' => 'tipo de cuenta',
                'payload.banco' => 'banco',
                'payload.eps' => 'EPS',
                'payload.afp' => 'AFP',
                'files.certificacionBancaria' => 'certificación bancaria',
                'files.diplomaEducativo' => 'diploma educativo',
                'files.actaGrado' => 'acta de grado',
                'files.certificadoEps' => 'certificado de EPS',
                'files.certificadoAfp' => 'certificado de AFP',
            ]);
        }

        return $attributes;
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
