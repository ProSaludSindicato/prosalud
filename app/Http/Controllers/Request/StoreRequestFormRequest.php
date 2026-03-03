<?php

namespace App\Http\Controllers\Request;

use App\Constants\{RequestSubtypes, RequestTypes, RequestStatuses};
use App\Models\RequestForm;
use App\Rules\RecaptchaRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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
            'recaptcha_token' => ['required', new RecaptchaRule()],
        ];

        // Validaciones específicas para actualizar-datos-personales
        if ('actualizar-datos-personales' === $requestType) {
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

                // Campos de contacto de emergencia
                'payload.nombreContactoEmergencia' => 'nullable|string|max:255',
                'payload.relacionContactoEmergencia' => 'nullable|string|max:100',
                'payload.telefonoContactoEmergencia' => 'nullable|string|max:20',

                // Campos condicionales - Nivel educativo
                'payload.nivelEducativo' => 'nullable|string|in:primaria,bachiller,tecnico,tecnologo,profesional,especialista,maestria,doctorado',

                // Campos condicionales - Cuenta bancaria
                // tipoCuenta es opcional porque si no cambia, el frontend no lo envía
                'payload.numeroCuenta' => 'nullable|string|max:255',
                'payload.tipoCuenta' => 'nullable|string|in:ahorros,corriente',
                'payload.banco' => 'nullable|string|in:bancolombia,davivienda,bbva,bogota,occidente,popular,av_villas,caja_social,colpatria,agrario,cooperativo,otros',

                // Campos condicionales - EPS y AFP
                'payload.eps' => 'nullable|string|in:sura,nueva_eps,sanitas,coomeva,compensar,famisanar,savia,aliansalud,otros',
                'payload.afp' => 'nullable|string|in:proteccion,porvenir,colfondos,old_mutual,skandia,otros',

                // Beneficiarios nuevos
                'payload.beneficiariosNuevos' => 'nullable|array',
                'payload.beneficiariosNuevos.*' => 'required|array',
                'payload.beneficiariosNuevos.*.tipo_documento' => 'required|string|max:50',
                'payload.beneficiariosNuevos.*.documento' => 'required|string|max:50',
                'payload.beneficiariosNuevos.*.nombres' => 'required|string|max:255',
                'payload.beneficiariosNuevos.*.apellidos' => 'required|string|max:255',
                'payload.beneficiariosNuevos.*.fecha_nacimiento' => 'required|date|date_format:Y-m-d',
                'payload.beneficiariosNuevos.*.parentesco' => 'nullable|string|max:100',
                'payload.beneficiariosNuevos.*.sexo' => 'nullable|string|max:10',

                // Beneficiarios eliminados
                'payload.beneficiariosEliminados' => 'nullable|array',
                'payload.beneficiariosEliminados.*' => 'required|array',
                'payload.beneficiariosEliminados.*.tipo_documento' => 'required|string|max:50',
                'payload.beneficiariosEliminados.*.documento' => 'required|string|max:50',
                'payload.beneficiariosEliminados.*.nombres' => 'required|string|max:255',
                'payload.beneficiariosEliminados.*.apellidos' => 'required|string|max:255',
                'payload.beneficiariosEliminados.*.parentesco' => 'nullable|string|max:100',
                'payload.beneficiariosEliminados.*.sexo' => 'nullable|string|max:10',

                // Archivos condicionales
                'files.certificacionBancaria' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
                'files.diplomaEducativo' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
                'files.actaGrado' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
                'files.certificadoEps' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
                'files.certificadoAfp' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,gif,webp',
            ]);
        }

        // Validaciones específicas para certificado-convenio
        if ('certificado-convenio' === $requestType) {
            $rules = array_merge($rules, [
                'payload.proceso' => 'nullable|string|max:255',
                'payload.dondeRealizaProceso' => 'nullable|string|max:255',
                'payload.infoCertificado' => 'nullable|array',
                'payload.infoCertificado.fechaIngresoRetiro' => 'nullable',
                'payload.infoCertificado.valorCompensaciones' => 'nullable',
                'payload.infoCertificado.paraSubsidioVivienda' => 'nullable',
                'payload.infoCertificado.paraSubsidioDesempleo' => 'nullable',
                'payload.infoCertificado.dirigidoAEntidad' => 'nullable',
                'payload.infoCertificado.dirigidoFondoPensiones' => 'nullable',
                'payload.infoCertificado.dirigidoBancolombia' => 'nullable',
                'payload.infoCertificado.adicionarActividades' => 'nullable',
                'payload.infoCertificado.otros' => 'nullable',
                'payload.dirigidoAQuien' => 'nullable|string|max:500',
                'payload.otrosDescripcion' => 'nullable|string|max:1000',
                'files.actividadesPdf' => 'nullable|file|max:4096|mimes:pdf',
                'files.adjuntarArchivoAdicional' => 'nullable|file|max:4096|mimes:pdf,doc,docx,jpeg,jpg,png,webp',
            ]);
        }

        // Validaciones específicas para verificacion-pagos
        if (RequestTypes::VERIFICACION_PAGOS === $requestType) {
            $validSubtypes = RequestSubtypes::forRequestType(RequestTypes::VERIFICACION_PAGOS);
            $rules = array_merge($rules, [
                'payload.solicitudRelacionadaCon' => [
                    'required',
                    'string',
                    function ($attribute, $value, $fail) use ($validSubtypes) {
                        if (empty($value)) {
                            $fail('El campo "Su solicitud está relacionada con" es obligatorio para verificaciones de pago.');
                            return;
                        }
                        if (!in_array($value, $validSubtypes, true)) {
                            $fail('El subtipo de solicitud seleccionado no es válido. Los valores válidos son: ' . implode(', ', $validSubtypes));
                        }
                    },
                ],
                // Campos adicionales para verificacion-pagos
                'payload.proceso' => 'nullable|string|max:255',
                'payload.dondeRealizaProceso' => 'nullable|string|max:255',
                'payload.mesAnoNovedad' => 'nullable|string|max:50',
                'payload.detalleNovedad' => 'nullable|string|max:1000',
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
            $allFiles = $this->allFiles();

            // Validación: evitar múltiples solicitudes de compensación del mismo tipo en proceso
            if (in_array($requestType, [RequestTypes::COMPENSACION_ANUAL, RequestTypes::COMPENSACION_DESCANSO], true)) {
                $documentNumber = $this->input('id_number');

                if ($documentNumber) {
                    $existingRequest = RequestForm::query()
                        ->where('document_number', $documentNumber)
                        ->where('request_type', $requestType)
                        ->whereIn('status', [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW])
                        ->latest('created_at')
                        ->first();

                    if ($existingRequest) {
                        $validator->errors()->add(
                            'request_type',
                            'Actualmente ya cuenta con una solicitud en proceso con ID ' . $existingRequest->id . ' para este mismo tipo de trámite. Debe esperar a recibir una respuesta antes de realizar una nueva solicitud del mismo tipo.'
                        );
                    }
                }
            }

            // Validación adicional para verificacion-pagos
            if (RequestTypes::VERIFICACION_PAGOS === $requestType) {
                $payload = $this->input('payload', []);
                $solicitudRelacionadaCon = $payload['solicitudRelacionadaCon'] ?? null;

                if (empty($solicitudRelacionadaCon)) {
                    $validator->errors()->add(
                        'payload.solicitudRelacionadaCon',
                        'El campo "Su solicitud está relacionada con" es obligatorio para verificaciones de pago.'
                    );
                } else {
                    $validSubtypes = RequestSubtypes::forRequestType(RequestTypes::VERIFICACION_PAGOS);
                    if (!in_array($solicitudRelacionadaCon, $validSubtypes, true)) {
                        $validator->errors()->add(
                            'payload.solicitudRelacionadaCon',
                            'El subtipo de solicitud seleccionado no es válido. Los valores válidos son: ' . implode(', ', $validSubtypes)
                        );
                    }
                }
            }

            // Validar tamaño total de archivos (máximo 20MB = 20480 KB)
            $totalSize = 0;
            $maxTotalSizeKB = 20480; // 20MB en KB
            $maxTotalSizeMB = 20;

            // Recopilar todos los archivos
            $filesToCheck = [];
            foreach ($allFiles as $key => $file) {
                if (is_array($file)) {
                    // Si es un array de archivos
                    foreach ($file as $singleFile) {
                        if ($singleFile instanceof \Illuminate\Http\UploadedFile && $singleFile->isValid()) {
                            $filesToCheck[] = $singleFile;
                        }
                    }
                } elseif ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                    $filesToCheck[] = $file;
                }
            }

            // Calcular tamaño total
            foreach ($filesToCheck as $file) {
                $totalSize += $file->getSize(); // getSize() retorna bytes
            }

            // Convertir bytes a KB
            $totalSizeKB = $totalSize / 1024;

            // Validar tamaño total
            if ($totalSizeKB > $maxTotalSizeKB) {
                $totalSizeMB = round($totalSizeKB / 1024, 2);
                $validator->errors()->add(
                    'files',
                    "El tamaño total de los archivos adjuntos ({$totalSizeMB}MB) excede el límite máximo permitido de {$maxTotalSizeMB}MB. Esto podría causar problemas al enviar el correo electrónico."
                );
            }

            // Validación: Afiliados con estado "Retirado" no pueden realizar ciertos trámites
            // Note: request_type is already normalized in prepareForValidation()
            $restrictedRequestTypes = [
                RequestTypes::COMPENSACION_DESCANSO,
                RequestTypes::COMPENSACION_ANUAL,
                RequestTypes::INCAPACIDADES_LICENCIAS,
                RequestTypes::SOLICITUD_MICROCREDITO,
                'permisos-turnos', // Tipo mencionado en labels pero no definido como constante
            ];

            if (in_array($requestType, $restrictedRequestTypes, true)) {
                $documento = $this->input('id_number');

                if ($documento) {
                    try {
                        $certificadoService = app(\App\Services\CertificadoConvenioService::class);
                        $afiliadoData = $certificadoService->obtenerDatosAfiliado($documento);

                        if ($afiliadoData && isset($afiliadoData['afiliado']['estado'])) {
                            $estadoAfiliado = strtolower(trim($afiliadoData['afiliado']['estado']));

                            if ($estadoAfiliado === 'retirado') {
                                // request_type is already normalized, so we only need to handle canonical types
                                $requestTypeLabel = match($requestType) {
                                    RequestTypes::COMPENSACION_DESCANSO => 'Compensación por descanso',
                                    RequestTypes::COMPENSACION_ANUAL => 'Compensación anual diferida',
                                    RequestTypes::INCAPACIDADES_LICENCIAS => 'Incapacidades y licencias',
                                    RequestTypes::SOLICITUD_MICROCREDITO => 'Solicitud de microcrédito',
                                    'permisos-turnos' => 'Permisos y cambio de turnos',
                                    default => 'este trámite',
                                };

                                $validator->errors()->add(
                                    'request_type',
                                    "Los afiliados con estado 'Retirado' no pueden realizar la solicitud de {$requestTypeLabel}. Este trámite está disponible únicamente para afiliados activos."
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        // Si no se puede obtener el estado del afiliado, registrar el error pero no bloquear la solicitud
                        // Esto permite que el sistema continúe funcionando aunque haya problemas temporales con el servicio
                        Log::warning('No se pudo obtener estado del afiliado para validación de trámites restringidos', [
                            'documento' => $documento,
                            'request_type' => $requestType,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            if ('actualizar-datos-personales' === $requestType) {
                $payload = $this->input('payload', []);

                // Helper para verificar si existe un archivo
                $hasFile = function ($fileKey) use ($allFiles) {
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

                // Validación condicional: Si se envía numeroCuenta, banco y certificacionBancaria son requeridos
                // tipoCuenta es opcional porque si no cambia, el frontend no lo envía
                if (!empty($payload['numeroCuenta'])) {
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

            // Validaciones específicas para certificado-convenio
            if ('certificado-convenio' === $requestType) {
                $payload = $this->input('payload', []);
                $documento = $this->input('id_number');

                // Obtener estado del afiliado si tenemos el documento
                $estadoAfiliado = null;
                if ($documento) {
                    try {
                        $certificadoService = app(\App\Services\CertificadoConvenioService::class);
                        $afiliadoData = $certificadoService->obtenerDatosAfiliado($documento);
                        if ($afiliadoData && isset($afiliadoData['afiliado']['estado'])) {
                            $estadoAfiliado = $afiliadoData['afiliado']['estado'];
                        }
                    } catch (\Exception $e) {
                        // Si no se puede obtener el estado, continuar sin él
                        // Las validaciones que no dependen del estado seguirán funcionando
                        Log::warning('No se pudo obtener estado del afiliado para validación', [
                            'documento' => $documento,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Preparar datos para validación
                // Asegurar que los archivos estén en la estructura correcta para el servicio
                // El servicio busca en $data['files']['actividadesPdf']
                $filesForValidation = [];

                // Caso 1: Archivos vienen como array anidado files[actividadesPdf] -> allFiles['files']['actividadesPdf']
                if (isset($allFiles['files']) && is_array($allFiles['files'])) {
                    $filesForValidation = $allFiles['files'];
                }
                // Caso 2: Archivos vienen con notación de punto files.actividadesPdf -> allFiles['files.actividadesPdf']
                // Laravel convierte files[actividadesPdf] a files.actividadesPdf en algunos casos
                else {
                    // Buscar archivos con prefijo 'files.'
                    foreach ($allFiles as $key => $file) {
                        if (strpos($key, 'files.') === 0 && $file instanceof \Illuminate\Http\UploadedFile) {
                            $fileKey = substr($key, 6); // Remover 'files.' prefix
                            $filesForValidation[$fileKey] = $file;
                        }
                    }
                }

                $validationData = array_merge($payload, [
                    'files' => $filesForValidation,
                ]);

                // Llamar al método de validación del servicio
                $certificadoService = app(\App\Services\CertificadoConvenioService::class);
                $validationErrors = $certificadoService->validarCertificadoConvenio($validationData, $estadoAfiliado);

                // Agregar errores al validador en el campo correcto
                foreach ($validationErrors as $error) {
                    // Si el error es sobre actividadesPdf, agregarlo a files.actividadesPdf
                    if (stripos($error, 'actividadesPdf') !== false) {
                        $validator->errors()->add('files.actividadesPdf', $error);
                    } else {
                        // Otros errores van a payload.infoCertificado
                        $validator->errors()->add('payload.infoCertificado', $error);
                    }
                }

                // Validaciones adicionales de archivos
                // Validar actividadesPdf si viene
                if (isset($allFiles['files']['actividadesPdf']) || isset($allFiles['actividadesPdf'])) {
                    $actividadesPdf = $allFiles['files']['actividadesPdf'] ?? $allFiles['actividadesPdf'] ?? null;
                    if ($actividadesPdf instanceof \Illuminate\Http\UploadedFile) {
                        // Validar que sea PDF
                        $mimeType = $actividadesPdf->getMimeType();
                        if ($mimeType !== 'application/pdf') {
                            $validator->errors()->add('files.actividadesPdf', 'El archivo de actividades debe ser un PDF');
                        }
                        // Validar tamaño (ya está en las reglas, pero verificamos aquí también)
                        if ($actividadesPdf->getSize() > 4 * 1024 * 1024) {
                            $validator->errors()->add('files.actividadesPdf', 'El archivo de actividades no puede exceder 4MB');
                        }
                    }
                }

                // Validar adjuntarArchivoAdicional si viene
                if (isset($allFiles['files']['adjuntarArchivoAdicional']) || isset($allFiles['adjuntarArchivoAdicional'])) {
                    $archivoAdicional = $allFiles['files']['adjuntarArchivoAdicional'] ?? $allFiles['adjuntarArchivoAdicional'] ?? null;
                    if ($archivoAdicional instanceof \Illuminate\Http\UploadedFile) {
                        // Validar tipos permitidos
                        $mimeType = $archivoAdicional->getMimeType();
                        $extension = strtolower($archivoAdicional->getClientOriginalExtension());
                        $allowedMimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png', 'image/webp'];
                        $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];

                        if (!in_array($mimeType, $allowedMimes) && !in_array($extension, $allowedExtensions)) {
                            $validator->errors()->add('files.adjuntarArchivoAdicional', 'El archivo adicional debe ser PDF, Word o imagen (JPG, PNG, WEBP)');
                        }
                        // Validar tamaño
                        if ($archivoAdicional->getSize() > 4 * 1024 * 1024) {
                            $validator->errors()->add('files.adjuntarArchivoAdicional', 'El archivo adicional no puede exceder 4MB');
                        }
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
            'recaptcha_token.required' => 'La verificación de reCAPTCHA es requerida.',
        ];

        // Mensajes específicos para actualizar-datos-personales
        if ('actualizar-datos-personales' === $this->input('request_type')) {
            $messages = array_merge($messages, [
                'payload.proceso.required' => 'El proceso es obligatorio.',
                'payload.dondeRealizaProceso.required' => 'El campo donde realiza el proceso es obligatorio.',
                'payload.nivelEducativo.in' => 'El nivel educativo seleccionado no es válido.',
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

        // Mensajes específicos para verificacion-pagos
        if (RequestTypes::VERIFICACION_PAGOS === $this->input('request_type')) {
            $messages = array_merge($messages, [
                'payload.solicitudRelacionadaCon.required' => 'El campo "Su solicitud está relacionada con" es obligatorio para verificaciones de pago.',
                'payload.solicitudRelacionadaCon.string' => 'El campo "Su solicitud está relacionada con" debe ser un texto válido.',
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

        if ('actualizar-datos-personales' === $this->input('request_type')) {
            $attributes = array_merge($attributes, [
                'payload.proceso' => 'proceso',
                'payload.dondeRealizaProceso' => 'donde realiza el proceso',
                'payload.estadoCivil' => 'estado civil',
                'payload.direccion' => 'dirección',
                'payload.municipio' => 'municipio',
                'payload.telefonoFijo' => 'teléfono fijo',
                'payload.celular' => 'celular',
                'payload.correo' => 'correo electrónico',
                'payload.nombreContactoEmergencia' => 'nombre contacto de emergencia',
                'payload.relacionContactoEmergencia' => 'relación contacto de emergencia',
                'payload.telefonoContactoEmergencia' => 'teléfono contacto de emergencia',
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

        if (RequestTypes::VERIFICACION_PAGOS === $this->input('request_type')) {
            $attributes = array_merge($attributes, [
                'payload.solicitudRelacionadaCon' => 'su solicitud está relacionada con',
            ]);
        }

        return $attributes;
    }

    /**
     * Prepare the data for validation.
     * Laravel automatically converts FormData payload[campo] to payload.campo,
     * and files[certificacionBancaria] to files.certificacionBancaria.
     * This method ensures the data structure is consistent for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize request_type to canonical value
        $requestType = $this->input('request_type');
        if ($requestType) {
            $normalizedRequestType = RequestTypes::normalize($requestType);
            if ($normalizedRequestType !== $requestType) {
                $this->merge(['request_type' => $normalizedRequestType]);
            }
        }

        // Laravel automatically handles FormData payload[campo] as payload.campo
        // When we access $this->input('payload'), it should already be an array
        // But we need to ensure it's properly structured for validation

        $allInput = $this->all();

        // Build payload array from payload.* keys
        // This handles cases where payload[campo] comes as payload.campo
        $payloadFromKeys = [];
        foreach ($allInput as $key => $value) {
            // Laravel converts payload[campo] to 'payload.campo' in the input
            if (0 === strpos($key, 'payload.')) {
                $payloadKey = substr($key, 8); // Remove 'payload.' prefix
                $payloadFromKeys[$payloadKey] = $value;
            }
        }

        // Get existing payload if it exists
        $existingPayload = $this->input('payload', []);
        if (!is_array($existingPayload)) {
            $existingPayload = [];
        }

        // Merge existing payload with keys found from payload.* pattern
        // This ensures all fields are captured even if Laravel didn't convert them properly
        $payload = array_merge($existingPayload, $payloadFromKeys);

        // Always merge payload, even if empty, to ensure structure is consistent
        $this->merge(['payload' => $payload]);

        // Convert payload.infoCertificado from JSON string to array if needed
        $payload = $this->input('payload', []);
        if (isset($payload['infoCertificado']) && is_string($payload['infoCertificado'])) {
            $decoded = json_decode($payload['infoCertificado'], true);
            // Only replace if JSON decode was successful and resulted in an array
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload['infoCertificado'] = $decoded;
                $this->merge(['payload' => $payload]);
            }
        }

        // Log payload for verificacion-pagos to help debug
        if (RequestTypes::VERIFICACION_PAGOS === $this->input('request_type')) {
            Log::debug('Payload procesado para verificacion-pagos', [
                'payload' => $payload,
                'solicitudRelacionadaCon' => $payload['solicitudRelacionadaCon'] ?? 'NO ENCONTRADO',
                'all_input_keys' => array_keys($allInput),
            ]);
        }
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::error('Errores de validación en StoreRequestFormRequest', [
            'input' => $this->all(),
            'errors' => $validator->errors()->toArray(),
        ]);

        throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422));
    }
}
