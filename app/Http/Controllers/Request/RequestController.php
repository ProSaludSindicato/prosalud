<?php

namespace App\Http\Controllers\Request;

use App\Constants\{RequestStatuses, RequestTypes, RequestSubtypes};
use App\Domain\RequestForm\RequestFormDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\{RespondToRequestRequest, RespondToCertificadoConCompensacionesRequest, RedirectSubtypeRequest};
use App\Mail\{RequestFormReceived, RequestFormResponse};
use App\Models\{RequestForm, RequestResponse, RequestResponseAttachment, RequestSubtypeAssignment, RequestTypeAssignment};
use App\Services\{AuditLogService, BulkRequestResponseService, CertificadoConvenioAutomaticoService, ExcelReaderService, RequestAssignmentService, RequestExcelExportService};
use App\Services\CertificadoConvenioService;
use App\Http\Requests\{BulkRequestResponseRequest, ExportRequestsExcelRequest, ProcessBulkResponseRequest};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log, Mail, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Carbon\Carbon;

class RequestController extends Controller
{
    public function __construct(
        private AuditLogService $auditLogService,
        private RequestAssignmentService $assignmentService,
        private RequestExcelExportService $excelExportService,
        private CertificadoConvenioAutomaticoService $certificadoAutomaticoService,
        private ExcelReaderService $excelReaderService,
        private CertificadoConvenioService $certificadoService,
        private BulkRequestResponseService $bulkResponseService,
    ) {
    }

    public function store(StoreRequestFormRequest $request): JsonResponse
    {
        // Nota: La verificación de reCAPTCHA ya se realizó en StoreRequestFormRequest
        // mediante RecaptchaRule. Los tokens de reCAPTCHA Enterprise solo pueden
        // usarse una vez, por lo que no debemos verificar nuevamente aquí.
        // Si llegamos a este punto, significa que la validación pasó exitosamente.

        Log::info('Procesando solicitud después de validación exitosa de reCAPTCHA', [
            'request_type' => $request->input('request_type'),
            'ip' => $request->ip(),
            'has_recaptcha_token' => $request->has('recaptcha_token'),
        ]);

        $dto = RequestFormDTO::fromArray($request->validated());
        $requestData = $dto->toArray();

        $requestData['status'] = RequestStatuses::PENDING;

        $originalFilesForEmail = $this->extractOriginalFiles($request);

        $filesMetadata = $this->processAndStoreFiles($request);

        if (!empty($filesMetadata)) {
            $requestData['files'] = $filesMetadata;
        }

        $requestForm = new RequestForm($requestData);
        $requestForm->created_at = now();
        $requestForm->save();
        $requestForm->refresh(); // Ensure files metadata is loaded

        Log::info('Nueva solicitud procesada', [
            'request_id' => $requestForm->id,
            'request_type' => $requestForm->request_type,
            'affiliate_info' => [
                'document_type' => $requestForm->document_type,
                'document_number' => $requestForm->document_number,
                'full_name' => $requestForm->full_name,
                'email' => $requestForm->email,
                'phone_number' => $requestForm->phone_number,
            ],
            'timestamp' => $requestForm->formatted_created_at,
            'status' => $requestForm->status,
            'payload' => $this->getPayloadSummary($requestForm->payload),
            'files_count' => is_array($requestForm->files) ? count($requestForm->files) : 0,
            'files_keys' => is_array($requestForm->files) ? array_keys($requestForm->files) : [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->auditLogService->logBusinessProcess('request_form', 'created', $this->auditLogService->addRequestContext($request, [
            'request_id' => $requestForm->id,
            'request_type' => $requestForm->request_type,
            'affiliate_document' => $requestForm->document_number,
            'affiliate_email' => $requestForm->email,
        ]));

        // Enviar correo de confirmación de forma asíncrona después de enviar la respuesta HTTP
        // Esto evita que el envío de correo bloquee la respuesta al frontend
        $requestFormForEmail = $requestForm;
        dispatch(function () use ($requestFormForEmail, $originalFilesForEmail) {
            try {
                $mail = Mail::to($requestFormForEmail->email);

                // Agregar CC para solicitudes de microcrédito
                if ($requestFormForEmail->request_type === RequestTypes::SOLICITUD_MICROCREDITO) {
                    $mail->cc('ceiisas@hotmail.com');
                }

                // Agregar CC para solicitudes de retiro sindical
                if ($requestFormForEmail->request_type === RequestTypes::SOLICITUD_RETIRO_SINDICAL || $requestFormForEmail->request_type === 'retiro-sindical') {
                    $mail->cc('talentohumano@sindicatoprosalud.com');
                }

                $mail->send(new RequestFormReceived($requestFormForEmail, $originalFilesForEmail));

                Log::info('Correo de confirmación de solicitud enviado exitosamente', [
                    'request_id' => $requestFormForEmail->id,
                    'email' => $requestFormForEmail->email,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error enviando correo de confirmación de solicitud', [
                    'request_id' => $requestFormForEmail->id,
                    'email' => $requestFormForEmail->email,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        })->afterResponse();

        $response = [
            'success' => true,
            'message' => 'Solicitud recibida exitosamente',
            'data' => [
                'id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'status' => $requestForm->status,
                'created_at' => $requestForm->formatted_created_at,
                'files' => $this->formatFilesMetadata($requestForm->files, $requestForm->id),
                'files_count' => is_array($requestForm->files) ? count($requestForm->files) : 0,
            ],
        ];

        // For 'actualizar-datos-personales' requests, also include 'request' key for backward compatibility
        if ('actualizar-datos-personales' === $requestForm->request_type) {
            $response['request'] = [
                'id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'status' => $requestForm->status,
                'created_at' => $requestForm->created_at->toIso8601String(),
            ];
            $response['message'] = 'Solicitud de actualización de datos personales recibida correctamente';
        }

        // Procesar automáticamente certificados de convenio simples (solo fecha ingreso/retiro y/o dirigido a entidad)
        // O con compensaciones si el afiliado está activo y tiene registro en el Excel
        if (RequestTypes::CERTIFICADO_CONVENIO === $requestForm->request_type) {
            Log::info('RequestController: Verificando procesamiento automático para certificado de convenio', [
                'request_id' => $requestForm->id,
                'document_number' => $requestForm->document_number,
                'payload' => $requestForm->payload,
            ]);

            $debeProcesarAutomatico = $this->debeProcesarCertificadoAutomatico($requestForm);
            $tieneValorCompensaciones = $this->tieneValorCompensaciones($requestForm);
            $esParaSubsidioVivienda = $this->esParaSubsidioVivienda($requestForm);

            Log::info('RequestController: Resultados de verificación de procesamiento automático', [
                'request_id' => $requestForm->id,
                'debeProcesarAutomatico' => $debeProcesarAutomatico,
                'tieneValorCompensaciones' => $tieneValorCompensaciones,
                'esParaSubsidioVivienda' => $esParaSubsidioVivienda,
            ]);

            // Si es para subsidio de vivienda, intentar obtener compensaciones automáticamente del Excel
            // ya que el certificado de subsidio de vivienda requiere compensaciones
            if ($esParaSubsidioVivienda) {
                // Intentar obtener compensaciones automáticamente del Excel
                $puedeProcesarConCompensaciones = $this->puedeProcesarCertificadoConCompensaciones($requestForm);

                if ($puedeProcesarConCompensaciones['puede_procesar'] && !empty($puedeProcesarConCompensaciones['compensaciones'])) {
                    Log::info('Certificado de subsidio de vivienda con compensaciones detectado, iniciando procesamiento automático', [
                        'request_id' => $requestForm->id,
                        'documento' => $requestForm->document_number,
                    ]);

                    $certificadoService = $this->certificadoAutomaticoService;
                    $requestFormId = $requestForm->id;
                    $compensaciones = $puedeProcesarConCompensaciones['compensaciones'];

                    dispatch(function () use ($certificadoService, $requestFormId, $compensaciones) {
                        try {
                            Log::info('Ejecutando procesamiento automático de certificado de subsidio de vivienda con compensaciones (background)', [
                                'request_id' => $requestFormId,
                                'compensaciones_recibidas' => $compensaciones,
                            ]);

                            $requestFormActualizado = RequestForm::find($requestFormId);
                            if ($requestFormActualizado) {
                                $certificadoService->procesarConRequestFormExistenteYCompensaciones($requestFormActualizado, $compensaciones);
                            } else {
                                Log::error('RequestForm no encontrado en background job', [
                                    'request_id' => $requestFormId,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            Log::error('Error en procesamiento automático de certificado de subsidio de vivienda con compensaciones (background)', [
                                'request_id' => $requestFormId,
                                'compensaciones' => $compensaciones,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    })->afterResponse();
                    // Salir temprano para evitar procesamiento duplicado
                    return response()->json($response, 201);
                } else {
                    // Si no hay compensaciones disponibles, procesar sin ellas (los campos quedarán vacíos)
                    Log::info('Certificado de subsidio de vivienda sin compensaciones disponibles, procesando sin compensaciones', [
                        'request_id' => $requestForm->id,
                        'documento' => $requestForm->document_number,
                        'razon' => $puedeProcesarConCompensaciones['razon'] ?? 'Compensaciones no disponibles',
                    ]);
                }
            }

            if ($tieneValorCompensaciones) {
                // Verificar si se puede procesar automáticamente con compensaciones
                $puedeProcesarConCompensaciones = $this->puedeProcesarCertificadoConCompensaciones($requestForm);

                if ($puedeProcesarConCompensaciones['puede_procesar']) {
                    Log::info('Certificado de convenio con compensaciones detectado, iniciando procesamiento automático', [
                        'request_id' => $requestForm->id,
                        'documento' => $requestForm->document_number,
                    ]);

                    // Procesar de forma asíncrona después de enviar la respuesta HTTP
                    $certificadoService = $this->certificadoAutomaticoService;
                    $requestFormId = $requestForm->id;
                    $compensaciones = $puedeProcesarConCompensaciones['compensaciones'];

                    dispatch(function () use ($certificadoService, $requestFormId, $compensaciones) {
                        try {
                            Log::info('Ejecutando procesamiento automático de certificado con compensaciones (background)', [
                                'request_id' => $requestFormId,
                                'compensaciones_recibidas' => $compensaciones,
                            ]);

                            // Recargar el RequestForm desde la BD para asegurar que tenemos la versión más reciente
                            $requestFormActualizado = RequestForm::find($requestFormId);
                            if ($requestFormActualizado) {
                                $certificadoService->procesarConRequestFormExistenteYCompensaciones($requestFormActualizado, $compensaciones);
                            } else {
                                Log::error('RequestForm no encontrado en background job', [
                                    'request_id' => $requestFormId,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            Log::error('Error en procesamiento automático de certificado con compensaciones (background)', [
                                'request_id' => $requestFormId,
                                'compensaciones' => $compensaciones,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    })->afterResponse();
                } else {
                    // No se puede procesar automáticamente, dejar pendiente
                    Log::info('Certificado de convenio con compensaciones no puede procesarse automáticamente, quedará pendiente', [
                        'request_id' => $requestForm->id,
                        'documento' => $requestForm->document_number,
                        'razon' => $puedeProcesarConCompensaciones['razon'] ?? 'desconocida',
                    ]);
                }
            } elseif ($debeProcesarAutomatico) {
                Log::info('Certificado de convenio simple detectado, iniciando procesamiento automático', [
                    'request_id' => $requestForm->id,
                    'documento' => $requestForm->document_number,
                ]);

                // Procesar de forma asíncrona después de enviar la respuesta HTTP
                // Esto no requiere workers independientes
                $certificadoService = $this->certificadoAutomaticoService;
                $requestFormId = $requestForm->id;

                dispatch(function () use ($certificadoService, $requestFormId) {
                    try {
                        // Recargar el RequestForm desde la BD para asegurar que tenemos la versión más reciente
                        $requestFormActualizado = RequestForm::find($requestFormId);
                        if ($requestFormActualizado) {
                            $certificadoService->procesarConRequestFormExistente($requestFormActualizado);
                        }
                    } catch (\Throwable $e) {
                        Log::error('Error en procesamiento automático de certificado (background)', [
                            'request_id' => $requestFormId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                })->afterResponse();
            }
        }

        return response()->json($response, 201);
    }

    /**
     * Display a listing of requests
     * IMPORTANT: This is an administrative endpoint with authentication and permissions.
     * Contact information (email, phone_number) should NOT be obfuscated for administrative processes.
     * Obfuscation should only apply to public endpoints without authentication.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = RequestForm::query();

        // Filter by user assignments (unless user is admin)
        if (!$user->hasRole('admin')) {
            $userId = $user->id;

            // Get all request types assigned to this user
            $assignedTypes = RequestTypeAssignment::where('user_id', $userId)
                ->pluck('request_type')
                ->toArray();

            // Get all subtypes assigned to this user, grouped by request type
            $assignedSubtypes = RequestSubtypeAssignment::where('user_id', $userId)
                ->get()
                ->groupBy('request_type')
                ->map(function ($assignments) {
                    return $assignments->pluck('subtype')->toArray();
                })
                ->toArray();

            // This prevents users with permissions but no assignments from seeing all requests
            if (empty($assignedTypes) && empty($assignedSubtypes)) {
                $query->whereRaw('1 = 0'); // Always false condition - returns no results
            } else {
                // Build query conditions
                $query->where(function ($q) use ($assignedTypes, $assignedSubtypes) {
                    // Handle types without subtypes
                    $typesWithoutSubtypes = array_filter($assignedTypes, function ($type) {
                        return !RequestTypes::hasSubtypes($type);
                    });

                    if (!empty($typesWithoutSubtypes)) {
                        // For each type, also include its aliases/variants
                        $typesToSearch = [];
                        foreach ($typesWithoutSubtypes as $type) {
                            $typesToSearch[] = $type;
                            // Add aliases/variants for backward compatibility
                            if ($type === RequestTypes::INCAPACIDADES_LICENCIAS) {
                                $typesToSearch[] = 'incapacidad-licencia';
                                $typesToSearch[] = 'incapacidad-laboral';
                            }
                            if ($type === RequestTypes::SOLICITUD_RETIRO_SINDICAL) {
                                $typesToSearch[] = 'retiro-sindical';
                            }
                        }
                        $q->whereIn('request_type', array_unique($typesToSearch));
                    }

                    // Handle types with subtypes
                    $typesWithSubtypes = array_filter($assignedTypes, function ($type) {
                        return RequestTypes::hasSubtypes($type);
                    });

                    foreach ($typesWithSubtypes as $type) {
                        $q->orWhere(function ($typeQ) use ($type) {
                            // If user has type assignment, they can see ALL requests of that type
                            // regardless of subtype assignments (type assignment has priority)
                            $typeQ->where('request_type', $type);
                        });
                    }

                    // Handle cases where user only has subtype assignments (no type assignment)
                    // IMPORTANT: Only filter by subtypes if user does NOT have type assignment
                    foreach ($assignedSubtypes as $type => $subtypes) {
                        if (!in_array($type, $assignedTypes)) {
                            // User only has subtype assignments, filter by specific subtypes
                            $q->orWhere(function ($typeQ) use ($type, $subtypes) {
                                $typeQ->where('request_type', $type);
                                $typeQ->where(function ($subtypeQ) use ($subtypes) {
                                    foreach ($subtypes as $subtype) {
                                        // Normalize both values for flexible comparison
                                        // Handle variations like "COMPENSACIÓN ANUAL DIFERIDA" vs "COMPENSACIÓN ANUAL DIFERIDA Y/O DESCANSO"
                                        $normalizedSubtype = $this->normalizeSubtypeValue($subtype);
                                        $subtypeQ->orWhere(function ($sq) use ($normalizedSubtype) {
                                            // Normalize the DB value the same way (remove Y/O DESCANSO, uppercase, trim)
                                            // Then use bidirectional LIKE comparison to handle variations
                                            $sq->whereRaw(
                                                "REPLACE(REPLACE(UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.solicitudRelacionadaCon')))), ' Y/O DESCANSO', ''), 'Y/O DESCANSO', '') LIKE ? OR ? LIKE CONCAT('%', REPLACE(REPLACE(UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.solicitudRelacionadaCon')))), ' Y/O DESCANSO', ''), 'Y/O DESCANSO', ''), '%')",
                                                [$normalizedSubtype . '%', $normalizedSubtype]
                                            );
                                        });
                                    }
                                });
                            });
                        }
                    }
                });
            }
        }

        // Search by name, email, or document number
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Filter by request type
        if ($request->has('request_type')) {
            $requestType = RequestTypes::normalize($request->get('request_type'));
            $query->where('request_type', $requestType);
        }

        // Order by created_at desc by default
        $query->orderBy('created_at', 'desc');

        // Eager load responses with attachments and responder for better performance
        $requests = $query->with('responses.attachments', 'responses.responder')->get();

        Log::info('Lista de solicitudes consultada', [
            'total_requests' => $requests->count(),
            'filters' => $request->only(['search', 'status', 'request_type']),
        ]);

        // Return data WITHOUT obfuscation for administrative users
        // This endpoint requires authentication and 'requests.view' permission
        // IMPORTANT: Contact information (email, phone_number) should NOT be obfuscated for administrative processes
        return response()->json([
            'success' => true,
            'data' => $requests->map(function ($request) {
                // Get raw attributes to avoid any accessor transformations
                $attributes = $request->getAttributes();

                return [
                    'id' => $request->id,
                    'request_type' => $request->request_type,
                    'document_type' => $request->document_type,
                    'document_number' => $request->document_number,
                    'name' => $request->name,
                    'last_name' => $request->last_name,
                    'full_name' => $request->full_name,
                    // Contact information returned WITHOUT obfuscation for administrative processes
                    // Use getRawOriginal() to get raw value directly from database, bypassing any accessors or transformations
                    'email' => $request->getRawOriginal('email') ?? $request->getAttribute('email'),
                    'phone_number' => $request->getRawOriginal('phone_number') ?? $request->getAttribute('phone_number'),
                    'status' => $request->status,
                    'payload' => $request->payload,
                    'created_at' => $request->created_at?->toIso8601String(),
                    'formatted_created_at' => $request->formatted_created_at,
                    'processed_at' => $request->processed_at?->toIso8601String(),
                    'formatted_processed_at' => $request->formatted_processed_at,
                    'validated_at' => $request->validated_at?->toIso8601String(),
                    'validated_by' => $request->validator?->email,
                    'responses' => $request->responses->map(function ($response) {
                        $attachments = $response->relationLoaded('attachments')
                            ? $response->attachments
                            : $response->attachments()->get();

                        // Load responder if not already loaded
                        $responder = $response->relationLoaded('responder')
                            ? $response->responder
                            : $response->responder;

                    // Handle created_at - cast should convert it to Carbon, but handle both cases defensively
                    $responseCreatedAt = $response->created_at;
                    if ($responseCreatedAt instanceof \DateTime || $responseCreatedAt instanceof \Carbon\Carbon) {
                        $responseCreatedAt = $responseCreatedAt->toIso8601String();
                    } elseif (is_string($responseCreatedAt) && !empty($responseCreatedAt)) {
                        // If it's still a string, try to parse and format it
                        try {
                            $responseCreatedAt = \Carbon\Carbon::parse($responseCreatedAt)->toIso8601String();
                        } catch (\Exception $e) {
                            $responseCreatedAt = $responseCreatedAt; // Keep as is if parsing fails
                        }
                    } else {
                        $responseCreatedAt = null;
                    }

                    return [
                        'id' => $response->id,
                        'status' => $response->status,
                        'email_subject' => $response->email_subject,
                        'email_body' => $response->email_body,
                        'created_at' => $responseCreatedAt,
                        'responded_by' => $responder ? [
                            'id' => $responder->id,
                            'name' => $responder->name,
                            'email' => $responder->email,
                        ] : null,
                        'attachments' => $this->formatResponseAttachments($attachments, $response->id),
                        'attachments_count' => $attachments->count(),
                    ];
                }),
                    'responses_count' => $request->responses->count(),
                    'files' => $this->formatFilesMetadata($request->files, $request->id),
                    'files_count' => is_array($request->files) ? count($request->files) : 0,
                ];
            }),
        ]);
    }

    /**
     * Display the specified request
     * IMPORTANT: This is an administrative endpoint with authentication and permissions.
     * Contact information (email, phone_number) should NOT be obfuscated for administrative processes.
     * Obfuscation should only apply to public endpoints without authentication.
     */
    public function show(RequestForm $request): JsonResponse
    {
        $user = request()->user();

        // SECURITY FIX: Validate that user has access to this specific request
        // This prevents users with permissions but no assignments from accessing requests
        if (!$user->hasRole('admin') && !$this->assignmentService->canUserAccessRequest($user, $request)) {
            Log::warning('Intento de acceso no autorizado a solicitud', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'request_id' => $request->id,
                'request_type' => $request->request_type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta solicitud',
            ], 403);
        }

        // Load responses with attachments, responder and validator relationships
        $request->load('responses.attachments', 'responses.responder', 'validator');

        Log::info('Solicitud consultada', [
            'request_id' => $request->id,
            'request_type' => $request->request_type,
            'status' => $request->status,
            'responses_count' => $request->responses->count(),
            'affiliate_info' => [
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
            ],
        ]);

        // Return data WITHOUT obfuscation for administrative users
        // This endpoint requires authentication and 'requests.view' permission
        // Use getAttribute() to get raw value from database, bypassing any accessors that might obfuscate
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $request->id,
                'request_type' => $request->request_type,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'name' => $request->name,
                'last_name' => $request->last_name,
                'full_name' => $request->full_name,
                // Contact information returned WITHOUT obfuscation for administrative processes
                // Use getRawOriginal() to get raw value directly from database, bypassing any accessors or transformations
                'email' => $request->getRawOriginal('email') ?? $request->getAttribute('email'),
                'phone_number' => $request->getRawOriginal('phone_number') ?? $request->getAttribute('phone_number'),
                'payload' => json_encode($request->payload ?? (object) [], JSON_UNESCAPED_UNICODE),
                'status' => $request->status,
                'created_at' => $request->created_at?->toIso8601String(),
                'formatted_created_at' => $request->formatted_created_at,
                'processed_at' => $request->processed_at?->toIso8601String(),
                'formatted_processed_at' => $request->formatted_processed_at,
                'validated_at' => $request->validated_at?->toIso8601String(),
                'validated_by' => $request->validator?->email,
                'responses' => $request->responses->map(function ($response) {
                    $attachments = $response->relationLoaded('attachments')
                        ? $response->attachments
                        : $response->attachments()->get();

                    // Load responder if not already loaded
                    $responder = $response->relationLoaded('responder')
                        ? $response->responder
                        : $response->responder;

                    // Handle created_at - cast should convert it to Carbon, but handle both cases defensively
                    $responseCreatedAt = $response->created_at;
                    if ($responseCreatedAt instanceof \DateTime || $responseCreatedAt instanceof \Carbon\Carbon) {
                        $responseCreatedAt = $responseCreatedAt->toIso8601String();
                    } elseif (is_string($responseCreatedAt) && !empty($responseCreatedAt)) {
                        // If it's still a string, try to parse and format it
                        try {
                            $responseCreatedAt = \Carbon\Carbon::parse($responseCreatedAt)->toIso8601String();
                        } catch (\Exception $e) {
                            $responseCreatedAt = $responseCreatedAt; // Keep as is if parsing fails
                        }
                    } else {
                        $responseCreatedAt = null;
                    }

                    return [
                        'id' => $response->id,
                        'status' => $response->status,
                        'email_subject' => $response->email_subject,
                        'email_body' => $response->email_body,
                        'created_at' => $responseCreatedAt,
                        'responded_by' => $responder ? [
                            'id' => $responder->id,
                            'name' => $responder->name,
                            'email' => $responder->email,
                        ] : null,
                        'attachments' => $this->formatResponseAttachments($attachments, $response->id),
                        'attachments_count' => $attachments->count(),
                    ];
                }),
                'responses_count' => $request->responses->count(),
                'files' => $this->formatFilesMetadata($request->files, $request->id),
                'files_count' => is_array($request->files) ? count($request->files) : 0,
            ],
        ]);
    }

    /**
     * Validate a request.
     * Marks the request as validated by the authenticated user.
     */
    public function validate(RequestForm $request): JsonResponse
    {
        $user = auth()->user();

        // SECURITY FIX: Validate that user has access to this specific request
        if (!$user->hasRole('admin') && !$this->assignmentService->canUserAccessRequest($user, $request)) {
            Log::warning('Intento de validación no autorizada de solicitud', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'request_id' => $request->id,
                'request_type' => $request->request_type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta solicitud',
            ], 403);
        }

        // Check if request is already validated
        if ($request->validated_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'La solicitud ya ha sido validada',
            ], 422);
        }

        // Update request with validation information
        $request->validated_at = now();
        $request->validated_by = $user->id;
        $request->save();

        // Load relationships for response
        $request->load('validator', 'responses.attachments');

        Log::info('Solicitud validada', [
            'request_id' => $request->id,
            'validated_by' => $user->id,
            'validated_by_email' => $user->email,
        ]);

        // Return response in the expected format
        return response()->json([
            'success' => true,
            'message' => 'Solicitud validada exitosamente',
            'data' => [
                'id' => $request->id,
                'request_type' => $request->request_type,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'name' => $request->name,
                'last_name' => $request->last_name,
                'full_name' => $request->full_name,
                'email' => $request->getRawOriginal('email') ?? $request->getAttribute('email'),
                'phone_number' => $request->getRawOriginal('phone_number') ?? $request->getAttribute('phone_number'),
                'payload' => $request->payload,
                'status' => $request->status,
                'created_at' => $request->created_at?->toIso8601String(),
                'formatted_created_at' => $request->formatted_created_at,
                'processed_at' => $request->processed_at?->toIso8601String(),
                'formatted_processed_at' => $request->formatted_processed_at,
                'validated_at' => $request->validated_at?->toIso8601String(),
                'validated_by' => $request->validator?->email,
                'responses_count' => $request->responses->count(),
                'files_count' => is_array($request->files) ? count($request->files) : 0,
            ],
        ]);
    }

    /**
     * Download a file from a request form.
     */
    public function downloadFile(RequestForm $request, string $fileKey): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $user = request()->user();

        // SECURITY FIX: Validate that user has access to this specific request
        if (!$user->hasRole('admin') && !$this->assignmentService->canUserAccessRequest($user, $request)) {
            Log::warning('Intento de descarga de archivo no autorizada de solicitud', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'request_id' => $request->id,
                'request_type' => $request->request_type,
                'file_key' => $fileKey,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta solicitud',
            ], 403);
        }

        $files = $request->files ?? [];

        if (!isset($files[$fileKey])) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo no encontrado',
            ], 404);
        }

        $fileMetadata = $files[$fileKey];
        $disk = $fileMetadata['disk'] ?? 'prosalud-private';
        $path = $fileMetadata['path'] ?? null;

        if (!$path || !Storage::disk($disk)->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo no existe en el almacenamiento',
            ], 404);
        }

        try {
            $fileContent = Storage::disk($disk)->get($path);
            $originalName = $fileMetadata['original_name'] ?? $fileMetadata['original_key'] ?? 'file';
            $mimeType = $fileMetadata['mime_type'] ?? 'application/octet-stream';

            Log::info('Archivo descargado de solicitud', [
                'request_id' => $request->id,
                'file_key' => $fileKey,
                'path' => $path,
                'disk' => $disk,
            ]);

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $originalName, [
                'Content-Type' => $mimeType,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al descargar archivo de solicitud', [
                'request_id' => $request->id,
                'file_key' => $fileKey,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el archivo',
            ], 500);
        }
    }

    /**
     * Change request status.
     */
    public function changeStatus(ChangeRequestStatusRequest $statusRequest, RequestForm $request): JsonResponse
    {
        $user = request()->user();

        // SECURITY FIX: Validate that user has access to this specific request
        if (!$user->hasRole('admin') && !$this->assignmentService->canUserAccessRequest($user, $request)) {
            Log::warning('Intento de cambio de estado no autorizado de solicitud', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'request_id' => $request->id,
                'request_type' => $request->request_type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta solicitud',
            ], 403);
        }

        $status = $statusRequest->validated()['status'];

        $updateData = ['status' => $status];

        if (RequestStatuses::COMPLETED === $status || RequestStatuses::REJECTED === $status) {
            $updateData['processed_at'] = now();
        } else {
            // For other statuses, clear processed_at
            $updateData['processed_at'] = null;
        }

        $request->update($updateData);

        $statusText = $this->getStatusText($status);

        Log::info("Solicitud marcada como {$statusText}", [
            'request_id' => $request->id,
            'request_type' => $request->request_type,
            'affiliate_info' => [
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'full_name' => $request->full_name,
                'email' => $request->email,
            ],
            'new_status' => $status,
            'processed_at' => $request->processed_at,
        ]);

        $this->auditLogService->logBusinessProcess('request_form', 'status_changed', $this->auditLogService->addRequestContext($statusRequest, [
            'request_id' => $request->id,
            'request_type' => $request->request_type,
            'old_status' => $request->getOriginal('status'),
            'new_status' => $status,
            'affiliate_document' => $request->document_number,
            'affiliate_email' => $request->email,
        ]));

        return response()->json([
            'success' => true,
            'message' => "Solicitud marcada como {$statusText} exitosamente",
            'data' => [
                'id' => $request->id,
                'request_type' => $request->request_type,
                'full_name' => $request->full_name,
                'email' => $request->email,
                'status' => $request->status,
                'processed_at' => $request->processed_at,
                'formatted_processed_at' => $request->formatted_processed_at,
            ],
        ]);
    }

    /**
     * Redirigir una solicitud cambiando su subtipo.
     * Esto hace que la solicitud sea visible para los usuarios asignados al nuevo subtipo.
     */
    public function redirectSubtype(RedirectSubtypeRequest $request, RequestForm $requestForm = null): JsonResponse
    {
        $user = request()->user();
        
        // Obtener el ID desde el parámetro de ruta (route model binding puede fallar con IDs string con ceros a la izquierda)
        $requestId = $request->route('request');
        
        // Asegurar que requestId es un string
        $requestId = (string) $requestId;
        
        Log::info('Redirect subtype - buscando RequestForm', [
            'route_id' => $requestId,
            'route_id_length' => strlen($requestId),
            'route_model_binding_result' => $requestForm ? 'found' : 'not_found',
        ]);
        
        // Buscar el RequestForm manualmente para asegurar que funciona con IDs string con ceros a la izquierda
        if (!$requestForm || !$requestForm->exists) {
            $requestForm = RequestForm::where('id', $requestId)->first();
        }
        
        if (!$requestForm) {
            Log::error('RequestForm no encontrado en redirectSubtype', [
                'route_id' => $requestId,
                'searched_id' => $requestId,
                'searched_id_type' => gettype($requestId),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        }
        
        Log::info('Redirect subtype - RequestForm encontrado', [
            'request_form_id' => $requestForm->id,
            'request_form_exists' => $requestForm->exists,
            'request_type' => $requestForm->request_type,
        ]);
        
        // Validar que el usuario tiene acceso a la solicitud actual
        if (!$user->hasRole('admin') && !$this->assignmentService->canUserAccessRequest($user, $requestForm)) {
            Log::warning('Intento de redirección de subtipo no autorizado', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'request_id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta solicitud',
            ], 403);
        }
        
        $newSubtype = $request->validated()['subtype'];
        $requestType = $requestForm->request_type;
        
        // Validar que la solicitud tiene un tipo válido
        if (empty($requestType)) {
            Log::warning('Intento de redirección de subtipo en solicitud sin tipo válido', [
                'request_id' => $requestForm->id,
                'request_type_value' => $requestType,
                'request_type_type' => gettype($requestType),
                'request_form_attributes' => [
                    'id' => $requestForm->id,
                    'request_type' => $requestForm->request_type,
                    'status' => $requestForm->status,
                    'document_number' => $requestForm->document_number,
                ],
                'user_id' => $user->id,
                'user_email' => $user->email,
                'new_subtype' => $newSubtype,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'La solicitud no tiene un tipo de solicitud válido',
            ], 400);
        }
        
        // Validar que el tipo de solicitud tiene subtipos
        if (!RequestTypes::hasSubtypes($requestType)) {
            Log::warning('Intento de redirección de subtipo en tipo de solicitud sin subtipos', [
                'request_id' => $requestForm->id,
                'request_type' => $requestType,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'new_subtype' => $newSubtype,
                'valid_types_with_subtypes' => RequestTypes::withSubtypes(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Este tipo de solicitud no tiene subtipos',
            ], 400);
        }
        
        // Validar que el subtipo es válido para este tipo de solicitud
        if (!RequestSubtypes::isValid($requestType, $newSubtype)) {
            $validSubtypes = RequestSubtypes::forRequestType($requestType);
            
            Log::warning('Intento de redirección con subtipo inválido', [
                'request_id' => $requestForm->id,
                'request_type' => $requestType,
                'new_subtype' => $newSubtype,
                'valid_subtypes' => $validSubtypes,
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Subtipo no válido para este tipo de solicitud',
            ], 400);
        }
        
        // Obtener el subtipo actual
        $currentSubtype = $this->assignmentService->getSubtypeFromRequest($requestForm);
        
        // Si el subtipo es el mismo, no hacer nada
        if ($currentSubtype === $newSubtype) {
            return response()->json([
                'success' => false,
                'message' => 'La solicitud ya tiene este subtipo asignado',
            ], 400);
        }
        
        // Actualizar el payload con el nuevo subtipo
        $payload = $requestForm->payload ?? [];
        $payload['solicitudRelacionadaCon'] = $newSubtype;
        
        $requestForm->update(['payload' => $payload]);
        
        // Obtener usuarios asignados al nuevo subtipo para mostrar en la respuesta
        $assignedUsers = $this->assignmentService->getUsersAssignedToSubtype($requestType, $newSubtype);
        
        // Registrar en el log de auditoría
        $this->auditLogService->logBusinessProcess('request_form', 'subtype_redirected', $this->auditLogService->addRequestContext($request, [
            'request_id' => $requestForm->id,
            'request_type' => $requestType,
            'old_subtype' => $currentSubtype,
            'new_subtype' => $newSubtype,
            'redirected_by' => $user->id,
        ]));
        
        Log::info('Solicitud redirigida a nuevo subtipo', [
            'request_id' => $requestForm->id,
            'request_type' => $requestType,
            'old_subtype' => $currentSubtype,
            'new_subtype' => $newSubtype,
            'redirected_by' => $user->id,
            'assigned_users_count' => count($assignedUsers),
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Solicitud redirigida exitosamente',
            'data' => [
                'request_id' => $requestForm->id,
                'old_subtype' => $currentSubtype,
                'new_subtype' => $newSubtype,
                'assigned_users' => $assignedUsers,
            ],
        ]);
    }

    /**
     * Respond to a request with email and status update.
     */
    public function respond(RespondToRequestRequest $request, $requestId = null): JsonResponse
    {
        // Get the ID from the route parameter (route model binding may not work with string IDs with leading zeros)
        if (!$requestId) {
            $requestId = $request->route('request');
        }

        // Ensure requestId is a string
        $requestId = (string) $requestId;

        Log::info('Respond to request - buscando RequestForm', [
            'route_id' => $requestId,
            'route_id_length' => strlen($requestId),
        ]);

        // Find the request form manually to ensure it works with string IDs with leading zeros
        $requestForm = RequestForm::where('id', $requestId)->first();

        if (!$requestForm) {
            Log::error('RequestForm no encontrado en respond', [
                'route_id' => $requestId,
                'searched_id' => $requestId,
                'searched_id_type' => gettype($requestId),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        }

        Log::info('Respond to request - RequestForm encontrado', [
            'request_form_id' => $requestForm->id,
            'request_form_exists' => $requestForm->exists,
        ]);

        $user = request()->user();

        // SECURITY FIX: Validate that user has access to this specific request
        if (!$user->hasRole('admin') && !$this->assignmentService->canUserAccessRequest($user, $requestForm)) {
            Log::warning('Intento de respuesta no autorizada a solicitud', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'request_id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta solicitud',
            ], 403);
        }

        // Validar que no se pueda responder a ninguna solicitud si hay una actualización de correo pendiente
        // Esto aplica a TODAS las solicitudes para evitar enviar respuestas al correo equivocado
        if ($this->tieneActualizacionCorreoPendiente($requestForm)) {
            // Buscar la solicitud de actualización pendiente para incluir su ID en el mensaje
            $solicitudActualizacion = RequestForm::where('request_type', RequestTypes::ACTUALIZAR_DATOS_PERSONALES)
                ->where('document_number', $requestForm->document_number)
                ->whereIn('status', [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW])
                ->where('id', '!=', $requestForm->id)
                ->orderBy('created_at', 'desc')
                ->first();

            Log::warning('Intento de responder a solicitud con actualización de correo pendiente - rechazando', [
                'request_id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'document_number' => $requestForm->document_number,
                'solicitud_actualizacion_id' => $solicitudActualizacion->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se puede responder a esta solicitud mientras existe una solicitud pendiente o en revisión de actualización de datos personales que incluye cambio de correo electrónico.',
                'errors' => [
                    'actualizacion_pendiente' => [
                        'Primero debe resolver la solicitud de actualización de datos personales pendiente antes de responder a esta solicitud.',
                        $solicitudActualizacion ? "Solicitud de actualización ID: {$solicitudActualizacion->id}" : null,
                    ],
                ],
                'solicitud_actualizacion_id' => $solicitudActualizacion->id ?? null,
            ], 422);
        }

        // Verificar características del certificado de convenio antes de validar
        // Esto se reutiliza más adelante en el método
        $tieneActividades = false;
        $esDirigidoFondoPensiones = false;
        if ($requestForm->request_type === RequestTypes::CERTIFICADO_CONVENIO) {
            $payload = $requestForm->payload ?? [];
            if (isset($payload['infoCertificado'])) {
                $infoCertificado = $payload['infoCertificado'];
                if (is_string($infoCertificado)) {
                    $infoCertificado = json_decode($infoCertificado, true);
                }

                if (is_array($infoCertificado)) {
                    $tieneActividades = $requestForm->parseBooleanValue($infoCertificado['adicionarActividades'] ?? false);
                    $esDirigidoFondoPensiones = $requestForm->parseBooleanValue($infoCertificado['dirigidoFondoPensiones'] ?? false);
                }
            }
        }

        $validated = $request->validated();
        $status = $validated['status'];
        $emailSubject = $validated['email_subject'];
        $emailBody = $this->sanitizeHtmlContent($validated['email_body']);

        // Get attachments if provided (needed for validation of dirigidoFondoPensiones)
        // Laravel automatically handles attachments as array when sent as attachments[0], attachments[1], etc.
        $attachments = [];
        if ($request->hasFile('attachments')) {
            $files = $request->file('attachments');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if ($file && $file->isValid()) {
                        $attachments[] = $file;
                    }
                }
            } else {
                // Single file
                if ($files && $files->isValid()) {
                    $attachments[] = $files;
                }
            }
        }

        // Verificar si tiene actividades y procesarlas (ya verificamos $tieneActividades arriba)
        // Ahora solo necesitamos obtener el array de actividades si está presente
        $actividades = [];
        if ($requestForm->request_type === RequestTypes::CERTIFICADO_CONVENIO) {
            $payload = $requestForm->payload ?? [];
            if (isset($payload['infoCertificado'])) {
                $infoCertificado = $payload['infoCertificado'];
                if (is_string($infoCertificado)) {
                    $infoCertificado = json_decode($infoCertificado, true);
                }

                if (is_array($infoCertificado)) {
                    // Procesar actividades si están presentes
                    if ($tieneActividades && $request->has('actividades')) {
                        $actividadesInput = $request->input('actividades', []);

                        // Normalize actividades array - handle both array and indexed form data
                        if (is_array($actividadesInput)) {
                            // Filter out empty values and trim
                            $actividades = array_filter(
                                array_map('trim', $actividadesInput),
                                fn($actividad) => !empty($actividad)
                            );
                            // Re-index array to ensure sequential numbering
                            $actividades = array_values($actividades);
                        }

                        // Only force status to IN_REVIEW if status is PENDING (not if user explicitly set COMPLETED or REJECTED)
                        // This allows users to complete or reject certificates with activities if needed
                        if ($status === RequestStatuses::PENDING) {
                            $status = RequestStatuses::IN_REVIEW;
                        }
                    }

                    // Procesar validación de dirigido a fondo de pensiones
                    if ($esDirigidoFondoPensiones) {
                        // Validar que haya al menos 1 archivo adjunto (planillas de seguridad social)
                        if (empty($attachments)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Errores de validación',
                                'errors' => [
                                    'attachments' => ['Es requerido adjuntar las planillas de pagos de seguridad social'],
                                ],
                            ], 422);
                        }

                        // Only force status to IN_REVIEW if status is PENDING (not if user explicitly set COMPLETED or REJECTED)
                        // This allows users to complete or reject certificates directed to pension fund if needed
                        if ($status === RequestStatuses::PENDING) {
                            $status = RequestStatuses::IN_REVIEW;
                        }
                    }
                }
            }
        }

        // Prepare data for logging
        $oldStatus = $requestForm->status;
        $requestFormId = (string) $requestForm->id;

        // Si es un certificado de convenio simple (sin actividades, sin dirigido a fondo de pensiones)
        // que quedó pendiente o en revisión y debería procesarse automáticamente, generar el certificado y anexarlo
        // Esto cubre el caso donde quedó pendiente porque había una actualización de correo pendiente
        if ($requestForm->request_type === RequestTypes::CERTIFICADO_CONVENIO
            && !$tieneActividades
            && !$esDirigidoFondoPensiones
            && in_array($oldStatus, [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW])
            && $this->debeProcesarCertificadoAutomatico($requestForm)
        ) {
            try {
                Log::info('Generando certificado automáticamente para solicitud pendiente que quedó bloqueada por actualización de correo', [
                    'request_id' => $requestFormId,
                    'documento' => $requestForm->document_number,
                ]);

                // Extraer información del payload para generar el certificado
                $payload = $requestForm->payload ?? [];
                $dirigidoAEntidad = null;

                // Extraer dirigidoAEntidad del payload
                if (isset($payload['dirigidoAQuien']) && !empty($payload['dirigidoAQuien'])) {
                    $dirigidoAEntidad = $payload['dirigidoAQuien'];
                } elseif (isset($payload['infoCertificado'])) {
                    $infoCertificado = $payload['infoCertificado'];
                    if (is_string($infoCertificado)) {
                        $infoCertificado = json_decode($infoCertificado, true);
                    }
                    if (is_array($infoCertificado) && isset($infoCertificado['dirigidoAQuien'])) {
                        $dirigidoAEntidad = $infoCertificado['dirigidoAQuien'];
                    }
                }

                // Verificar tipo de certificado para determinar parámetros
                $esParaBancolombia = false;
                $esParaSubsidioVivienda = false;
                $esParaSubsidioDesempleo = false;
                $esOtros = false;

                if (isset($payload['infoCertificado'])) {
                    $infoCertificado = $payload['infoCertificado'];
                    if (is_string($infoCertificado)) {
                        $infoCertificado = json_decode($infoCertificado, true);
                    }
                    if (is_array($infoCertificado)) {
                        $esParaBancolombia = $requestForm->parseBooleanValue($infoCertificado['dirigidoBancolombia'] ?? false);
                        $esParaSubsidioVivienda = $requestForm->parseBooleanValue($infoCertificado['paraSubsidioVivienda'] ?? false);
                        $esParaSubsidioDesempleo = $requestForm->parseBooleanValue($infoCertificado['paraSubsidioDesempleo'] ?? false);
                        $esOtros = $requestForm->parseBooleanValue($infoCertificado['otros'] ?? false);
                    }
                }

                // Generar certificado PDF
                $certificadoResult = $this->certificadoService->generarCertificadoPDF(
                    $requestForm->document_number,
                    $dirigidoAEntidad,
                    null, // sin compensaciones
                    $esParaBancolombia,
                    $esParaSubsidioVivienda,
                    $esParaSubsidioDesempleo,
                    $esOtros
                );

                // Agregar el certificado generado a los adjuntos
                // El certificado se guardará directamente en los anexos de la respuesta, no en los archivos de la solicitud
                if (file_exists($certificadoResult['ruta'])) {
                    // Generar nombre de archivo en formato estándar con consecutivo
                    $consecutivo = $certificadoResult['consecutivo'] ?? '';
                    $documentoNormalizado = preg_replace('/[^0-9]/', '', $requestForm->document_number);
                    $nombreArchivoEstandar = "Certificado_Sindicato_ProSalud_{$documentoNormalizado}_{$consecutivo}.pdf";

                    // Leer contenido del archivo
                    $fileContent = file_get_contents($certificadoResult['ruta']);
                    $fileSize = filesize($certificadoResult['ruta']);

                    // Crear un archivo temporal en el directorio temporal del sistema
                    $tempPath = tempnam(sys_get_temp_dir(), 'cert_auto_');
                    file_put_contents($tempPath, $fileContent);

                    // Crear instancia UploadedFile (usando modo test para evitar validación)
                    $uploadedFile = new \Illuminate\Http\UploadedFile(
                        $tempPath,
                        $nombreArchivoEstandar,
                        'application/pdf',
                        UPLOAD_ERR_OK,
                        true // test mode - permite crear desde archivo existente
                    );

                    $attachments[] = $uploadedFile;

                    // Modificar emailSubject y emailBody para incluir el consecutivo si no lo tienen
                    if (!empty($consecutivo)) {
                        // Verificar si el asunto ya incluye el consecutivo
                        if (stripos($emailSubject, $consecutivo) === false) {
                            $emailSubject = "Certificado de Convenio - Consecutivo {$consecutivo}";
                        }

                        // Verificar si el cuerpo ya incluye el consecutivo
                        if (stripos($emailBody, $consecutivo) === false) {
                            $fecha = Carbon::now(config('app.timezone', 'America/Bogota'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
                            $emailBody = "Adjunto encontrará su certificado en formato PDF con el siguiente consecutivo: {$consecutivo}.\n\nEste certificado ha sido generado automáticamente y contiene la información solicitada sobre su convenio.\n\nFecha de generación: {$fecha}\nConsecutivo: {$consecutivo}";
                        }
                    }

                    Log::info('Certificado generado automáticamente y agregado a adjuntos', [
                        'request_id' => $requestFormId,
                        'certificado_ruta' => $certificadoResult['ruta'],
                        'certificado_nombre_original' => $certificadoResult['nombre'],
                        'certificado_nombre_estandar' => $nombreArchivoEstandar,
                        'consecutivo' => $consecutivo,
                        'file_size' => $fileSize,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Error al generar certificado automáticamente para solicitud pendiente', [
                    'request_id' => $requestFormId,
                    'documento' => $requestForm->document_number,
                    'error' => $e->getMessage(),
                    'error_trace' => $e->getTraceAsString(),
                ]);

                // No fallar toda la solicitud, pero registrar el error
                // El usuario aún puede responder sin el certificado adjunto
            }
        }

        // Generate certificate with activities if needed
        if ($tieneActividades && !empty($actividades)) {
            try {
                Log::info('Generando certificado con actividades', [
                    'request_id' => $requestFormId,
                    'documento' => $requestForm->document_number,
                    'actividades_count' => count($actividades),
                ]);

                // Get dirigidoAEntidad from payload if available
                $dirigidoAEntidad = null;
                $payload = $requestForm->payload ?? [];
                if (isset($payload['dirigidoAQuien']) && !empty($payload['dirigidoAQuien'])) {
                    $dirigidoAEntidad = $payload['dirigidoAQuien'];
                }

                // Generate PDF certificate with activities
                $certificadoResult = $this->certificadoService->generarCertificadoPDFConActividades(
                    $requestForm->document_number,
                    $actividades,
                    null, // consecutivo will be generated
                    $dirigidoAEntidad
                );

                // Add the generated certificate to attachments
                // El certificado se guardará directamente en los anexos de la respuesta, no en los archivos de la solicitud
                // Create an UploadedFile-like object for the Mail class
                if (file_exists($certificadoResult['ruta'])) {
                    // Read file content
                    $fileContent = file_get_contents($certificadoResult['ruta']);
                    $fileSize = filesize($certificadoResult['ruta']);

                    // Create a temporary file in the system temp directory
                    $tempPath = tempnam(sys_get_temp_dir(), 'cert_actividades_');
                    file_put_contents($tempPath, $fileContent);

                    // Create UploadedFile instance (using test mode to avoid validation)
                    $uploadedFile = new \Illuminate\Http\UploadedFile(
                        $tempPath,
                        $certificadoResult['nombre'],
                        'application/pdf',
                        UPLOAD_ERR_OK,
                        true // test mode - allows creating from existing file
                    );

                    $attachments[] = $uploadedFile;

                    Log::info('Certificado con actividades generado exitosamente', [
                        'request_id' => $requestFormId,
                        'certificado_ruta' => $certificadoResult['ruta'],
                        'certificado_nombre' => $certificadoResult['nombre'],
                        'consecutivo' => $certificadoResult['consecutivo'],
                        'file_size' => $fileSize,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Error al generar certificado con actividades', [
                    'request_id' => $requestFormId,
                    'documento' => $requestForm->document_number,
                    'error' => $e->getMessage(),
                    'error_trace' => $e->getTraceAsString(),
                ]);

                // Don't fail the entire request, but log the error
                // The user can still respond without the certificate attached
            }
        }

        // Generate certificate directed to pension fund (AFP) if needed
        if ($esDirigidoFondoPensiones) {
            try {
                Log::info('Generando certificado dirigido a fondo de pensiones', [
                    'request_id' => $requestFormId,
                    'documento' => $requestForm->document_number,
                ]);

                // Generate PDF certificate directed to AFP
                // Ya no se requiere el AFP como parámetro ya que la plantilla Word tiene un valor genérico
                $certificadoResult = $this->certificadoService->generarCertificadoPDFDirigidoAFP(
                    $requestForm->document_number,
                    null, // AFP ya no se personaliza, se usa valor genérico de la plantilla
                    null // consecutivo will be generated
                );

                // Add the generated certificate to attachments
                // El certificado se guardará directamente en los anexos de la respuesta, no en los archivos de la solicitud
                // Create an UploadedFile-like object for the Mail class
                if (file_exists($certificadoResult['ruta'])) {
                    // Read file content
                    $fileContent = file_get_contents($certificadoResult['ruta']);
                    $fileSize = filesize($certificadoResult['ruta']);

                    // Create a temporary file in the system temp directory
                    $tempPath = tempnam(sys_get_temp_dir(), 'cert_afp_');
                    file_put_contents($tempPath, $fileContent);

                    // Create UploadedFile instance (using test mode to avoid validation)
                    $uploadedFile = new \Illuminate\Http\UploadedFile(
                        $tempPath,
                        $certificadoResult['nombre'],
                        'application/pdf',
                        UPLOAD_ERR_OK,
                        true // test mode - allows creating from existing file
                    );

                    $attachments[] = $uploadedFile;

                    Log::info('Certificado dirigido a AFP generado exitosamente', [
                        'request_id' => $requestFormId,
                        'certificado_ruta' => $certificadoResult['ruta'],
                        'certificado_nombre' => $certificadoResult['nombre'],
                        'consecutivo' => $certificadoResult['consecutivo'],
                        'file_size' => $fileSize,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Error al generar certificado dirigido a AFP', [
                    'request_id' => $requestFormId,
                    'documento' => $requestForm->document_number,
                    'error' => $e->getMessage(),
                    'error_trace' => $e->getTraceAsString(),
                ]);

                // Return error - this is critical for AFP certificates
                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el certificado dirigido a fondo de pensiones: ' . $e->getMessage(),
                    'error' => config('app.debug') ? $e->getMessage() : 'Error al generar el certificado',
                ], 500);
            }
        }

        // Determine recipient email address
        // For "actualizar-datos-personales" requests that are being completed/approved,
        // if the payload contains a new email (correo), use that instead of the original email.
        // This ensures the user receives the response at their new email address,
        // especially useful if they no longer have access to the old one.
        $recipientEmail = $requestForm->email;
        $isPersonalDataUpdate = $requestForm->request_type === RequestTypes::ACTUALIZAR_DATOS_PERSONALES;
        $isCompletedOrApproved = $status === RequestStatuses::COMPLETED;

        if ($isPersonalDataUpdate && $isCompletedOrApproved) {
            $payload = $requestForm->payload ?? [];
            $nuevoCorreo = $payload['correo'] ?? null;

            if (!empty($nuevoCorreo) && filter_var($nuevoCorreo, FILTER_VALIDATE_EMAIL)) {
                $recipientEmail = $nuevoCorreo;

                Log::info('Usando nuevo correo del payload para solicitud de actualización de datos personales', [
                    'request_id' => $requestFormId,
                    'email_original' => $requestForm->email,
                    'email_nuevo' => $recipientEmail,
                    'status' => $status,
                ]);
            } else {
                Log::info('No se encontró nuevo correo válido en el payload, usando correo original', [
                    'request_id' => $requestFormId,
                    'email_original' => $requestForm->email,
                    'payload_correo' => $nuevoCorreo ?? 'no presente',
                    'status' => $status,
                ]);
            }
        }

        // Get authenticated user for logging
        $user = auth()->user();
        $userId = $user ? $user->id : null;

        Log::info('Iniciando proceso de respuesta a solicitud', [
            'request_id' => $requestFormId,
            'request_type' => $requestForm->request_type,
            'user_id' => $userId,
            'user_email' => $user ? $user->email : null,
            'user_name' => $user ? $user->name : null,
            'old_status' => $oldStatus,
            'new_status' => $status,
            'email_original' => $requestForm->email,
            'email_recipient' => $recipientEmail,
            'has_attachments' => !empty($attachments),
            'attachments_count' => count($attachments),
            'tiene_actividades' => $tieneActividades,
            'actividades_count' => $tieneActividades ? count($actividades) : 0,
            'es_dirigido_fondo_pensiones' => $esDirigidoFondoPensiones,
        ]);

        // Detect compressed files (zip, rar) that need special handling
        $compressedExtensions = ['zip', 'rar'];
        $compressedFiles = [];
        $nonCompressedFiles = [];
        $hasCompressedFiles = false;

        if (!empty($attachments)) {
            foreach ($attachments as $file) {
                if ($file && $file->isValid()) {
                    $extension = strtolower($file->getClientOriginalExtension() ?? '');
                    if (in_array($extension, $compressedExtensions)) {
                        $compressedFiles[] = $file;
                        $hasCompressedFiles = true;
                    } else {
                        $nonCompressedFiles[] = $file;
                    }
                }
            }
        }

        // If there are compressed files, we need to store them first to generate URLs
        // Create RequestResponse before sending email (in transaction)
        $requestResponse = null;
        $compressedFileUrls = [];

        if ($hasCompressedFiles) {
            try {
                DB::beginTransaction();

                // Get authenticated user for traceability
                $user = auth()->user();
                $userId = $user ? $user->id : null;

                // Create RequestResponse temporarily (will be committed after email success)
                $requestResponse = RequestResponse::create([
                    'request_form_id' => $requestFormId,
                    'responded_by' => $userId,
                    'status' => $status,
                    'email_subject' => $emailSubject,
                    'email_body' => $emailBody,
                    'created_at' => now(),
                ]);

                // Store compressed files and generate Presigned URLs (48 hours)
                foreach ($compressedFiles as $file) {
                    $storedAttachment = $this->storeSingleResponseAttachment($requestResponse, $file);
                    if ($storedAttachment) {
                        try {
                            $storage = Storage::disk('prosalud-private');
                            $downloadUrl = $storage->temporaryUrl($storedAttachment['path'], now()->addHours(48));
                            $compressedFileUrls[] = [
                                'name' => $storedAttachment['original_name'],
                                'url' => $downloadUrl,
                                'expires_at' => now()->addHours(48)->toIso8601String(),
                            ];
                        } catch (\Exception $e) {
                            Log::warning('Failed to generate Presigned URL for compressed file', [
                                'response_id' => $requestResponse->id,
                                'path' => $storedAttachment['path'],
                                'error' => $e->getMessage(),
                            ]);
                            // Continue without URL - file is stored but URL generation failed
                        }
                    }
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('Error storing compressed files before email', [
                    'request_id' => $requestFormId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue without compressed file URLs - files won't be linked in email
            }
        }

        // IMPORTANT: Send email FIRST, before updating status or creating response record (if not already created)
        // This ensures that if email fails, we don't update the request status
        try {
            Log::info('Intentando enviar correo de respuesta', [
                'request_id' => $requestFormId,
                'email_to' => $recipientEmail,
                'email_original' => $requestForm->email,
                'email_subject' => $emailSubject,
                'email_body_length' => strlen($emailBody),
                'attachments_count' => count($attachments),
                'compressed_files_count' => count($compressedFiles),
                'non_compressed_files_count' => count($nonCompressedFiles),
                'has_compressed_urls' => !empty($compressedFileUrls),
            ]);

            $mail = Mail::to($recipientEmail);

            // Agregar CC para solicitudes de microcrédito
            if ($requestForm->request_type === RequestTypes::SOLICITUD_MICROCREDITO) {
                $mail->cc('ceiisas@hotmail.com');
            }

            // Agregar CC para solicitudes de retiro sindical
            if ($requestForm->request_type === RequestTypes::SOLICITUD_RETIRO_SINDICAL || $requestForm->request_type === 'retiro-sindical') {
                $mail->cc('talentohumano@sindicatoprosalud.com');
            }

            $mail->send(new RequestFormResponse(
                $requestForm,
                $emailSubject,
                $emailBody,
                $status,
                $nonCompressedFiles, // Only pass non-compressed files as attachments
                $compressedFileUrls // Pass compressed file URLs separately
            ));

            Log::info('Correo de respuesta enviado exitosamente', [
                'request_id' => $requestFormId,
                'email_recipient' => $recipientEmail,
                'email_original' => $requestForm->email,
                'status' => $status,
                'has_attachments' => !empty($attachments),
                'attachments_count' => count($attachments),
                'compressed_files_count' => count($compressedFiles),
                'non_compressed_files_count' => count($nonCompressedFiles),
            ]);
        } catch (\Throwable $e) {
            // If RequestResponse was created for compressed files, rollback
            if ($requestResponse && $hasCompressedFiles) {
                try {
                    DB::beginTransaction();
                    // Delete attachments associated with this response
                    RequestResponseAttachment::where('request_response_id', $requestResponse->id)->delete();
                    // Delete the response
                    $requestResponse->delete();
                    DB::commit();
                } catch (\Throwable $rollbackError) {
                    DB::rollBack();
                    Log::error('Error during rollback after email failure', [
                        'request_id' => $requestFormId,
                        'response_id' => $requestResponse->id,
                        'error' => $rollbackError->getMessage(),
                    ]);
                }
            }

            // Log detailed error information
            Log::error('FALLO AL ENVIAR CORREO DE RESPUESTA - NO SE ACTUALIZARÁ EL ESTADO', [
                'request_id' => $requestFormId,
                'request_type' => $requestForm->request_type,
                'email_recipient' => $recipientEmail,
                'email_original' => $requestForm->email,
                'email_subject' => $emailSubject,
                'old_status' => $oldStatus,
                'intended_new_status' => $status,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'has_attachments' => !empty($attachments),
                'attachments_count' => count($attachments),
            ]);

            // Return error - do NOT update status or create response record
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar el correo de respuesta. La solicitud no fue actualizada.',
                'error' => config('app.debug') ? $e->getMessage() : 'Error al enviar el correo electrónico',
            ], 500);
        }

        // Email was sent successfully, now update the request status
        $updateData = ['status' => $status];

        if (RequestStatuses::COMPLETED === $status || RequestStatuses::REJECTED === $status) {
            $updateData['processed_at'] = now();
        } else {
            // For other statuses, clear processed_at
            $updateData['processed_at'] = null;
        }

        $requestForm->update($updateData);
        $requestForm->refresh(); // Refresh to ensure we have the latest data

        // Get authenticated user for traceability
        $user = auth()->user();
        $userId = $user ? $user->id : null;

        // Store the response for traceability (only after email is sent successfully)
        // If RequestResponse was already created for compressed files, reuse it
        if (!$requestResponse) {
            $requestResponse = RequestResponse::create([
                'request_form_id' => $requestFormId,
                'responded_by' => $userId,
                'status' => $status,
                'email_subject' => $emailSubject,
                'email_body' => $emailBody,
                'created_at' => now(),
            ]);
        }

        // Store non-compressed attachments if any (compressed files were already stored)
        // Los certificados generados automáticamente ya se incluyen en $attachments y se guardan aquí
        if (!empty($nonCompressedFiles)) {
            $this->storeResponseAttachments($requestResponse, $nonCompressedFiles);
        }

        // Log with user information for easy searching
        Log::info('Respuesta de solicitud procesada exitosamente', [
            'request_id' => $requestFormId,
            'response_id' => $requestResponse->id,
            'request_type' => $requestForm->request_type,
            'user_id' => $userId,
            'user_email' => $user ? $user->email : null,
            'user_name' => $user ? $user->name : null,
            'affiliate_info' => [
                'document_type' => $requestForm->document_type,
                'document_number' => $requestForm->document_number,
                'full_name' => $requestForm->full_name,
                'email' => $requestForm->email,
            ],
            'old_status' => $oldStatus,
            'new_status' => $status,
            'has_attachments' => !empty($attachments),
            'attachments_count' => count($attachments),
        ]);

        $this->auditLogService->logBusinessProcess('request_form', 'responded', $this->auditLogService->addRequestContext($request, [
            'request_id' => $requestForm->id,
            'response_id' => $requestResponse->id,
            'request_type' => $requestForm->request_type,
            'responded_by_user_id' => $userId,
            'responded_by_user_email' => $user ? $user->email : null,
            'responded_by_user_name' => $user ? $user->name : null,
            'old_status' => $oldStatus,
            'new_status' => $status,
            'affiliate_document' => $requestForm->document_number,
            'affiliate_email' => $requestForm->email,
            'has_attachments' => !empty($attachments),
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Respuesta enviada exitosamente',
            'data' => [
                'id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'document_type' => $requestForm->document_type,
                'document_number' => $requestForm->document_number,
                'name' => $requestForm->name,
                'last_name' => $requestForm->last_name,
                'full_name' => $requestForm->full_name,
                'email' => $requestForm->email,
                'status' => $requestForm->status,
                'created_at' => $requestForm->created_at,
                'processed_at' => $requestForm->processed_at,
                'formatted_processed_at' => $requestForm->formatted_processed_at,
            ],
        ]);
    }

    /**
     * Respond to a certificado convenio request with manual compensation values.
     * This endpoint allows responding to pending certificado convenio requests that require
     * compensation values (T. Basicos and T. Auxilios) when they cannot be automatically
     * extracted from the Excel file (e.g., for retired affiliates or missing records).
     *
     * The endpoint will:
     * 1. Validate the request is a certificado convenio and is pending
     * 2. Calculate T. Ingresos as the sum of T. Basicos and T. Auxilios
     * 3. Generate the certificate automatically using the compensation values
     * 4. Send the response email with the certificate attached
     * 5. Update the request status
     */
    public function respondWithCompensaciones(RespondToCertificadoConCompensacionesRequest $request, $requestId = null): JsonResponse
    {
        // Get the ID from the route parameter
        if (!$requestId) {
            $requestId = $request->route('request');
        }

        // Ensure requestId is a string
        $requestId = (string) $requestId;

        Log::info('Respond with compensaciones - buscando RequestForm', [
            'route_id' => $requestId,
            'route_id_length' => strlen($requestId),
        ]);

        // Find the request form manually
        $requestForm = RequestForm::where('id', $requestId)->first();

        if (!$requestForm) {
            Log::error('RequestForm no encontrado en respondWithCompensaciones', [
                'route_id' => $requestId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        }

        $user = request()->user();

        // SECURITY FIX: Validate that user has access to this specific request
        if (!$user->hasRole('admin') && !$this->assignmentService->canUserAccessRequest($user, $requestForm)) {
            Log::warning('Intento de respuesta con compensaciones no autorizada a solicitud', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'request_id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta solicitud',
            ], 403);
        }

        // Validate that the request is a certificado convenio
        if (RequestTypes::CERTIFICADO_CONVENIO !== $requestForm->request_type) {
            Log::error('Request no es de tipo certificado convenio', [
                'request_id' => $requestId,
                'request_type' => $requestForm->request_type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Esta funcionalidad solo está disponible para certificados de convenio',
            ], 400);
        }

        // Validar que no se pueda responder a ninguna solicitud si hay una actualización de correo pendiente
        // Esto aplica a TODAS las solicitudes para evitar enviar respuestas al correo equivocado
        if ($this->tieneActualizacionCorreoPendiente($requestForm)) {
            // Buscar la solicitud de actualización pendiente para incluir su ID en el mensaje
            $solicitudActualizacion = RequestForm::where('request_type', RequestTypes::ACTUALIZAR_DATOS_PERSONALES)
                ->where('document_number', $requestForm->document_number)
                ->whereIn('status', [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW])
                ->where('id', '!=', $requestForm->id)
                ->orderBy('created_at', 'desc')
                ->first();

            Log::warning('Intento de responder a solicitud con actualización de correo pendiente - rechazando', [
                'request_id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'document_number' => $requestForm->document_number,
                'solicitud_actualizacion_id' => $solicitudActualizacion->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se puede responder a esta solicitud mientras existe una solicitud pendiente o en revisión de actualización de datos personales que incluye cambio de correo electrónico.',
                'errors' => [
                    'actualizacion_pendiente' => [
                        'Primero debe resolver la solicitud de actualización de datos personales pendiente antes de responder a esta solicitud.',
                        $solicitudActualizacion ? "Solicitud de actualización ID: {$solicitudActualizacion->id}" : null,
                    ],
                ],
                'solicitud_actualizacion_id' => $solicitudActualizacion->id ?? null,
            ], 422);
        }

        // Validate that the request is pending (or in review)
        if (!in_array($requestForm->status, [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW])) {
            Log::error('Request no está en estado pendiente o en revisión', [
                'request_id' => $requestId,
                'status' => $requestForm->status,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden responder solicitudes pendientes o en revisión con compensaciones manuales',
            ], 400);
        }

        $validated = $request->validated();
        $status = $validated['status'];
        $emailSubject = $validated['email_subject'];
        $emailBody = $this->sanitizeHtmlContent($validated['email_body']);
        $tBasicos = (int) $validated['t_basicos'];
        $tAuxilios = (int) $validated['t_auxilios'];

        // Calculate T. Ingresos as the sum of T. Basicos and T. Auxilios
        $tIngresos = $tBasicos + $tAuxilios;

        // Get authenticated user for logging
        $user = auth()->user();
        $userId = $user ? $user->id : null;

        Log::info('Iniciando proceso de respuesta con compensaciones manuales', [
            'request_id' => $requestId,
            'request_type' => $requestForm->request_type,
            'user_id' => $userId,
            'user_email' => $user ? $user->email : null,
            'user_name' => $user ? $user->name : null,
            'old_status' => $requestForm->status,
            'new_status' => $status,
            't_basicos' => $tBasicos,
            't_auxilios' => $tAuxilios,
            't_ingresos' => $tIngresos,
        ]);

        // Prepare compensaciones array
        $compensaciones = [
            't_basicos' => $tBasicos,
            't_auxilios' => $tAuxilios,
            't_ingresos' => $tIngresos,
        ];

        try {
            // Generate certificate with compensation values
            Log::info('Generando certificado con compensaciones manuales', [
                'request_id' => $requestId,
                'documento' => $requestForm->document_number,
                'compensaciones' => $compensaciones,
            ]);

            // Process the certificate using CertificadoConvenioAutomaticoService
            // Pass custom email subject and body if provided
            $resultado = $this->certificadoAutomaticoService->procesarConRequestFormExistenteYCompensaciones(
                $requestForm,
                $compensaciones,
                $emailSubject,
                $emailBody,
                $status
            );

            Log::info('Certificado generado exitosamente con compensaciones manuales', [
                'request_id' => $requestId,
                'consecutivo' => $resultado['consecutivo'] ?? null,
            ]);

            // The service already sends the email and updates the status, so we just need to return success
            return response()->json([
                'success' => true,
                'message' => 'Certificado generado y respuesta enviada exitosamente',
                'data' => [
                    'id' => $requestForm->id,
                    'request_type' => $requestForm->request_type,
                    'document_type' => $requestForm->document_type,
                    'document_number' => $requestForm->document_number,
                    'name' => $requestForm->name,
                    'last_name' => $requestForm->last_name,
                    'full_name' => $requestForm->full_name,
                    'email' => $requestForm->email,
                    'status' => $requestForm->fresh()->status,
                    'consecutivo' => $resultado['consecutivo'] ?? null,
                    'compensaciones' => $compensaciones,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error procesando respuesta con compensaciones manuales', [
                'request_id' => $requestId,
                'documento' => $requestForm->document_number,
                'compensaciones' => $compensaciones,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el certificado con compensaciones: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'Error al procesar la solicitud',
            ], 500);
        }
    }

    /**
     * Extract original files from request for email attachment
     * Only extracts multipart files, not base64 (which can't be attached)
     * Supports both files[certificacionBancaria] (FormData) and files.certificacionBancaria notation.
     */
    private function extractOriginalFiles(Request $request): array
    {
        $originalFiles = [];
        $allFiles = $request->allFiles();

        foreach ($allFiles as $key => $file) {
            // Handle nested files array (files[certificacionBancaria] from FormData)
            if (is_array($file)) {
                foreach ($file as $singleFile) {
                    if ($singleFile instanceof \Illuminate\Http\UploadedFile && $singleFile->isValid()) {
                        $originalFiles[] = $singleFile;
                    }
                }
            }
            // Handle files with dot notation (files.certificacionBancaria)
            elseif (0 === strpos($key, 'files.')) {
                if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                    $originalFiles[] = $file;
                }
            }
            // Handle single file upload
            elseif ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                $originalFiles[] = $file;
            }
        }

        return $originalFiles;
    }

    /**
     * Process and store files to private bucket
     * Handles files from JSON array or multipart form-data
     * Supports both files[certificacionBancaria] (FormData) and files.certificacionBancaria notation.
     */
    private function processAndStoreFiles(Request $request): array
    {
        $disk = 'prosalud-private';
        $fallbackDisk = 'local';
        $filesMetadata = [];

        $allFiles = $request->allFiles();

        foreach ($allFiles as $key => $file) {
            if (!is_array($file) && !($file instanceof \Illuminate\Http\UploadedFile)) {
                continue;
            }

            // Handle nested files array (files[certificacionBancaria] from FormData)
            if (is_array($file)) {
                foreach ($file as $fileKey => $singleFile) {
                    if ($singleFile instanceof \Illuminate\Http\UploadedFile && $singleFile->isValid()) {
                        $metadata = $this->storeUploadedFile($singleFile, $fileKey, $disk, $fallbackDisk);
                        if ($metadata) {
                            $filesMetadata[$fileKey] = $metadata;
                        }
                    }
                }
            }
            // Handle files with dot notation (files.certificacionBancaria)
            elseif ('files' === $key && $file instanceof \Illuminate\Http\UploadedFile) {
                // This shouldn't happen, but handle it just in case
                $metadata = $this->storeUploadedFile($file, $key, $disk, $fallbackDisk);
                if ($metadata) {
                    $filesMetadata[$key] = $metadata;
                }
            }
            // Handle direct file keys (certificacionBancaria directly)
            elseif ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                $metadata = $this->storeUploadedFile($file, $key, $disk, $fallbackDisk);
                if ($metadata) {
                    $filesMetadata[$key] = $metadata;
                }
            }
        }

        // Also check for files with dot notation (files.certificacionBancaria)
        // Laravel converts files[certificacionBancaria] to files.certificacionBancaria
        $dotNotationFiles = [];
        foreach ($allFiles as $key => $value) {
            if (0 === strpos($key, 'files.')) {
                $fileKey = substr($key, 6); // Remove 'files.' prefix
                if ($value instanceof \Illuminate\Http\UploadedFile && $value->isValid()) {
                    $dotNotationFiles[$fileKey] = $value;
                }
            }
        }

        // Process dot notation files
        foreach ($dotNotationFiles as $fileKey => $file) {
            if (!isset($filesMetadata[$fileKey])) {
                $metadata = $this->storeUploadedFile($file, $fileKey, $disk, $fallbackDisk);
                if ($metadata) {
                    $filesMetadata[$fileKey] = $metadata;
                }
            }
        }

        // Then, handle files from JSON array (base64 encoded)
        $jsonFiles = $request->input('files', []);
        if (is_array($jsonFiles) && !empty($jsonFiles)) {
            foreach ($jsonFiles as $key => $fileData) {
                // Skip if we already processed this file from multipart
                if (isset($filesMetadata[$key])) {
                    continue;
                }

                try {
                    // Handle base64 encoded files from API
                    if (is_string($fileData) && preg_match('/^data:([a-zA-Z0-9\/]+);base64,/', $fileData, $matches)) {
                        $mimeType = $matches[1];
                        $base64Data = substr($fileData, strpos($fileData, ',') + 1);
                        $fileContent = base64_decode($base64Data, true);

                        if (false === $fileContent) {
                            Log::warning('Failed to decode base64 file', [
                                'key' => $key,
                                'mime_type' => $mimeType,
                            ]);
                            continue;
                        }

                        // Determine file extension from mime type
                        $extension = $this->getExtensionFromMimeType($mimeType);
                        $filename = $this->generateDescriptiveFilenameForBase64($key, $extension);
                        $storagePath = 'request-forms/' . date('Y/m') . '/' . $filename;

                        // Store file
                        $stored = Storage::disk($disk)->put($storagePath, $fileContent);

                        if (false === $stored) {
                            Log::warning('Failed to store file in private bucket, trying fallback', [
                                'key' => $key,
                                'path' => $storagePath,
                            ]);
                            $stored = Storage::disk($fallbackDisk)->put($storagePath, $fileContent);
                            if ($stored) {
                                $disk = $fallbackDisk;
                            }
                        }

                        if ($stored) {
                            $filesMetadata[$key] = [
                                'path' => $storagePath,
                                'disk' => $disk,
                                'mime_type' => $mimeType,
                                'size' => strlen($fileContent),
                                'original_key' => $key,
                            ];
                        }
                    }
                    // Handle file upload objects
                    elseif (is_array($fileData) && isset($fileData['name']) && isset($fileData['content'])) {
                        // File data structure: {name: string, content: base64 string, mime_type?: string}
                        $fileName = $fileData['name'];
                        $content = $fileData['content'];
                        $mimeType = $fileData['mime_type'] ?? 'application/octet-stream';

                        // If content is base64, decode it
                        if (preg_match('/^data:([a-zA-Z0-9\/]+);base64,/', $content, $matches)) {
                            $mimeType = $matches[1];
                            $base64Data = substr($content, strpos($content, ',') + 1);
                            $fileContent = base64_decode($base64Data, true);
                        } else {
                            // Assume it's already base64 without prefix
                            $fileContent = base64_decode($content, true);
                        }

                        if (false === $fileContent) {
                            Log::warning('Failed to decode file content', ['key' => $key]);
                            continue;
                        }

                        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: $this->getExtensionFromMimeType($mimeType);
                        $filename = Str::uuid() . ($extension ? '.' . $extension : '');
                        $storagePath = 'request-forms/' . date('Y/m') . '/' . $filename;

                        // Store file
                        $stored = Storage::disk($disk)->put($storagePath, $fileContent);

                        if (false === $stored) {
                            Log::warning('Failed to store file in private bucket, trying fallback', [
                                'key' => $key,
                                'path' => $storagePath,
                            ]);
                            $stored = Storage::disk($fallbackDisk)->put($storagePath, $fileContent);
                            if ($stored) {
                                $disk = $fallbackDisk;
                            }
                        }

                        if ($stored) {
                            $filesMetadata[$key] = [
                                'path' => $storagePath,
                                'disk' => $disk,
                                'original_name' => $fileName,
                                'mime_type' => $mimeType,
                                'size' => strlen($fileContent),
                                'original_key' => $key,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error processing file for request form', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continue with next file instead of failing completely
                }
            }
        }

        return $filesMetadata;
    }

    /**
     * Store an uploaded file to private bucket.
     */
    private function storeUploadedFile(
        \Illuminate\Http\UploadedFile $file,
        string $key,
        string &$disk,
        string $fallbackDisk,
    ): ?array {
        try {
            $extension = $file->getClientOriginalExtension() ?: $this->getExtensionFromMimeType($file->getMimeType());
            $filename = $this->generateDescriptiveFilenameForUpload($file, $key, $extension);
            $storagePath = 'request-forms/' . date('Y/m') . '/' . $filename;

            // Store file
            $storedPath = Storage::disk($disk)->putFileAs(
                'request-forms/' . date('Y/m'),
                $file,
                $filename
            );

            $finalDisk = $disk;
            if (false === $storedPath) {
                Log::warning('Failed to store file in private bucket, trying fallback', [
                    'key' => $key,
                    'path' => $storagePath,
                ]);
                $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                    'request-forms/' . date('Y/m'),
                    $file,
                    $filename
                );
                if ($storedPath) {
                    $finalDisk = $fallbackDisk;
                } else {
                    return null;
                }
            }

            return [
                'path' => $storedPath,
                'disk' => $finalDisk,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'original_key' => $key,
            ];
        } catch (\Exception $e) {
            Log::error('Error storing uploaded file', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate a simple but descriptive filename for uploaded files
     * Format: [Key]-[UniqueId].[ext]
     * Example: CertBanc-abc123.pdf.
     */
    private function generateDescriptiveFilenameForUpload(
        \Illuminate\Http\UploadedFile $file,
        string $key,
        string $extension,
    ): string {
        // Map common keys to short abbreviations
        $keyAbbreviations = [
            'certificacionBancaria' => 'CertBanc',
            'diplomaEducativo' => 'Diploma',
            'actaGrado' => 'ActaGrado',
            'certificadoEps' => 'CertEPS',
            'certificadoAfp' => 'CertAFP',
            'cedula' => 'Cedula',
            'carnet' => 'Carnet',
            'foto' => 'Foto',
            'documento' => 'Doc',
        ];

        // Get short name from key
        $shortName = $keyAbbreviations[$key] ?? $this->formatKeyName($key);

        // Generate short unique identifier
        $uniqueId = substr(Str::uuid()->toString(), 0, 6);

        // Build simple filename: [ShortName]-[UniqueId].[ext]
        return sprintf('%s-%s.%s', $shortName, $uniqueId, $extension);
    }

    /**
     * Format key name to readable format.
     */
    private function formatKeyName(string $key): string
    {
        // Convert camelCase to PascalCase with spaces, then remove spaces
        $formatted = preg_replace('/([a-z])([A-Z])/', '$1$2', $key);
        $formatted = ucfirst($formatted);

        // Remove special characters
        $formatted = preg_replace('/[^a-zA-Z0-9]/', '', $formatted);

        return $formatted ?: 'Archivo';
    }

    /**
     * Get file extension from MIME type.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
        ];

        return $mimeToExt[$mimeType] ?? 'bin';
    }

    /**
     * Store a single attachment for a request response and return its metadata.
     * This is used for compressed files that need URLs generated before sending email.
     *
     * @param RequestResponse $requestResponse The response to attach file to
     * @param \Illuminate\Http\UploadedFile $attachment The file to store
     * @return array|null Array with 'path' and 'original_name', or null on failure
     */
    private function storeSingleResponseAttachment(RequestResponse $requestResponse, \Illuminate\Http\UploadedFile $attachment): ?array
    {
        if (!($attachment instanceof \Illuminate\Http\UploadedFile) || !$attachment->isValid()) {
            return null;
        }

        $disk = 'prosalud-private';
        $fallbackDisk = 'local';

        try {
            // Generate a descriptive filename
            $extension = $attachment->getClientOriginalExtension()
                ?: $this->getExtensionFromMimeType($attachment->getMimeType());
            $originalName = $attachment->getClientOriginalName();

            // Generate unique filename for storage
            $uniqueId = substr(Str::uuid()->toString(), 0, 8);
            $filename = "response-attachment-{$requestResponse->id}-{$uniqueId}.{$extension}";

            // Store file
            $storedPath = Storage::disk($disk)->putFileAs(
                'request-responses/' . date('Y/m'),
                $attachment,
                $filename
            );

            if (false === $storedPath) {
                Log::warning('Failed to store response attachment in private bucket, trying fallback', [
                    'response_id' => $requestResponse->id,
                    'original_name' => $originalName,
                ]);
                $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                    'request-responses/' . date('Y/m'),
                    $attachment,
                    $filename
                );
                if (false === $storedPath) {
                    Log::error('Failed to store response attachment in fallback disk', [
                        'response_id' => $requestResponse->id,
                        'original_name' => $originalName,
                    ]);
                    return null;
                }
            }

            // Create database record
            RequestResponseAttachment::create([
                'request_response_id' => $requestResponse->id,
                'path' => $storedPath,
                'original_name' => $originalName,
                'created_at' => now(),
            ]);

            Log::info('Response attachment stored successfully', [
                'response_id' => $requestResponse->id,
                'original_name' => $originalName,
                'storage_path' => $storedPath,
            ]);

            return [
                'path' => $storedPath,
                'original_name' => $originalName,
            ];
        } catch (\Exception $e) {
            Log::error('Error storing response attachment', [
                'response_id' => $requestResponse->id,
                'original_name' => $attachment->getClientOriginalName() ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Store attachments for a request response.
     * This method saves uploaded files to storage and creates database records for traceability.
     *
     * @param RequestResponse $requestResponse The response to attach files to
     * @param array $attachments Array of UploadedFile instances
     */
    private function storeResponseAttachments(RequestResponse $requestResponse, array $attachments): void
    {
        $disk = 'prosalud-private';
        $fallbackDisk = 'local';

        foreach ($attachments as $attachment) {
            if (!($attachment instanceof \Illuminate\Http\UploadedFile) || !$attachment->isValid()) {
                continue;
            }

            try {
                // Generate a descriptive filename
                $extension = $attachment->getClientOriginalExtension()
                    ?: $this->getExtensionFromMimeType($attachment->getMimeType());
                $originalName = $attachment->getClientOriginalName();

                // Generate unique filename for storage
                $uniqueId = substr(Str::uuid()->toString(), 0, 8);
                $filename = "response-attachment-{$requestResponse->id}-{$uniqueId}.{$extension}";
                $storagePath = 'request-responses/' . date('Y/m') . '/' . $filename;

                // Store file
                $storedPath = Storage::disk($disk)->putFileAs(
                    'request-responses/' . date('Y/m'),
                    $attachment,
                    $filename
                );

                if (false === $storedPath) {
                    Log::warning('Failed to store response attachment in private bucket, trying fallback', [
                        'response_id' => $requestResponse->id,
                        'original_name' => $originalName,
                    ]);
                    $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                        'request-responses/' . date('Y/m'),
                        $attachment,
                        $filename
                    );
                    if (false === $storedPath) {
                        Log::error('Failed to store response attachment in fallback disk', [
                            'response_id' => $requestResponse->id,
                            'original_name' => $originalName,
                        ]);
                        continue;
                    }
                }

                // Create database record
                RequestResponseAttachment::create([
                    'request_response_id' => $requestResponse->id,
                    'path' => $storedPath,
                    'original_name' => $originalName,
                    'created_at' => now(),
                ]);

                Log::info('Response attachment stored successfully', [
                    'response_id' => $requestResponse->id,
                    'original_name' => $originalName,
                    'storage_path' => $storedPath,
                ]);
            } catch (\Exception $e) {
                Log::error('Error storing response attachment', [
                    'response_id' => $requestResponse->id,
                    'original_name' => $attachment->getClientOriginalName() ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue with next attachment instead of failing entire operation
            }
        }
    }

    /**
     * Generate a simple but descriptive filename for base64 encoded files
     * Format: [Key]-[UniqueId].[ext].
     */
    private function generateDescriptiveFilenameForBase64(string $key, string $extension): string
    {
        // Map common keys to short abbreviations
        $keyAbbreviations = [
            'certificacionBancaria' => 'CertBanc',
            'diplomaEducativo' => 'Diploma',
            'actaGrado' => 'ActaGrado',
            'certificadoEps' => 'CertEPS',
            'certificadoAfp' => 'CertAFP',
            'cedula' => 'Cedula',
            'carnet' => 'Carnet',
            'foto' => 'Foto',
            'documento' => 'Doc',
        ];

        // Get short name from key
        $shortName = $keyAbbreviations[$key] ?? $this->formatKeyName($key);

        // Generate short unique identifier
        $uniqueId = substr(Str::uuid()->toString(), 0, 6);

        // Build simple filename: [ShortName]-[UniqueId].[ext]
        return sprintf('%s-%s.%s', $shortName, $uniqueId, $extension);
    }

    /**
     * Get a summary of payload data for logging.
     */
    private function getPayloadSummary(array $payload): array
    {
        $summary = [];

        $commonFields = [
            'proceso', 'dondeRealizaProceso', 'motivoSolicitud',
            'dirigidoAQuien', 'tipoVehiculo', 'placaVehiculo',
            'infoCertificado', 'otrosDescripcion',
        ];

        foreach ($commonFields as $field) {
            if (isset($payload[$field])) {
                $summary[$field] = $payload[$field];
            }
        }

        return $summary;
    }

    /**
     * Format files metadata for API response (without exposing sensitive data)
     * Generates temporary URLs for private bucket files.
     */
    private function formatFilesMetadata(?array $files, ?string $requestId = null): array
    {
        if (!is_array($files) || empty($files)) {
            return [];
        }

        $formatted = [];
        foreach ($files as $key => $fileMetadata) {
            $fileKey = $fileMetadata['original_key'] ?? $key;
            $disk = $fileMetadata['disk'] ?? 'prosalud-private';
            $path = $fileMetadata['path'] ?? null;

            $downloadUrl = null;
            $urlExpiresAt = null;

            if ($path && 'prosalud-private' === $disk) {
                try {
                    $storage = Storage::disk($disk);
                    $downloadUrl = $storage->temporaryUrl($path, now()->addHours(1));
                    $urlExpiresAt = now()->addHours(1)->toIso8601String();
                } catch (\Exception $e) {
                    // If temporary URL generation fails (e.g., local disk doesn't support it),
                    // fallback to the download endpoint
                    Log::warning('Failed to generate temporary URL, using download endpoint', [
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                    $downloadUrl = $requestId
                        ? url("/api/requests/{$requestId}/files/{$fileKey}")
                        : null;
                }
            } else {
                // For non-private disks or if path is missing, use download endpoint
                $downloadUrl = $requestId
                    ? url("/api/requests/{$requestId}/files/{$fileKey}")
                    : null;
            }

            $formatted[$key] = [
                'original_name' => $fileMetadata['original_name'] ?? $fileMetadata['original_key'] ?? $key,
                'mime_type' => $fileMetadata['mime_type'] ?? 'application/octet-stream',
                'size' => $fileMetadata['size'] ?? 0,
                'original_key' => $fileKey,
                'download_url' => $downloadUrl,
                'url_expires_at' => $urlExpiresAt,
            ];
        }

        return $formatted;
    }

    /**
     * Format response attachments with temporary URLs for private bucket files.
     *
     * @param \Illuminate\Database\Eloquent\Collection|null $attachments
     * @param int $responseId
     * @return array
     */
    private function formatResponseAttachments($attachments, int $responseId): array
    {
        if (!$attachments || $attachments->isEmpty()) {
            return [];
        }

        $formatted = [];
        foreach ($attachments as $attachment) {
            $downloadUrl = null;
            $urlExpiresAt = null;

            try {
                // Generate temporary URL for private bucket files (valid for 1 hour)
                $storage = Storage::disk('prosalud-private');
                $downloadUrl = $storage->temporaryUrl($attachment->path, now()->addHours(1));
                $urlExpiresAt = now()->addHours(1)->toIso8601String();
            } catch (\Exception $e) {
                // Fallback to download endpoint if temporary URL fails
                Log::warning('Failed to generate temporary URL for response attachment', [
                    'attachment_id' => $attachment->id,
                    'response_id' => $responseId,
                    'path' => $attachment->path,
                    'error' => $e->getMessage(),
                ]);
                $downloadUrl = url("/api/requests/responses/{$responseId}/attachments/{$attachment->id}");
            }

            // Handle created_at - it might be a string or DateTime object
            $createdAt = $attachment->created_at;
            if ($createdAt instanceof \DateTime || $createdAt instanceof \Carbon\Carbon) {
                $createdAt = $createdAt->toIso8601String();
            } elseif (is_string($createdAt)) {
                // If it's already a string, try to format it
                try {
                    $createdAt = \Carbon\Carbon::parse($createdAt)->toIso8601String();
                } catch (\Exception $e) {
                    $createdAt = $createdAt; // Keep as is if parsing fails
                }
            } else {
                $createdAt = null;
            }

            $formatted[] = [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'download_url' => $downloadUrl,
                'url_expires_at' => $urlExpiresAt,
                'created_at' => $createdAt,
            ];
        }

        return $formatted;
    }

    /**
     * Download a response attachment file.
     */
    public function downloadResponseAttachment(int $responseId, int $attachmentId): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $attachment = RequestResponseAttachment::where('request_response_id', $responseId)
            ->where('id', $attachmentId)
            ->first();

        if (!$attachment) {
            return response()->json([
                'success' => false,
                'message' => 'Anexo no encontrado',
            ], 404);
        }

        $disk = 'prosalud-private';
        $path = $attachment->path;

        if (!Storage::disk($disk)->exists($path)) {
            // Try fallback disk
            $disk = 'local';
            if (!Storage::disk($disk)->exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Archivo no existe en el almacenamiento',
                ], 404);
            }
        }

        try {
            $fileContent = Storage::disk($disk)->get($path);
            $mimeType = Storage::disk($disk)->mimeType($path) ?? 'application/octet-stream';

            Log::info('Anexo de respuesta descargado', [
                'response_id' => $responseId,
                'attachment_id' => $attachmentId,
                'path' => $path,
                'disk' => $disk,
            ]);

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $attachment->original_name, [
                'Content-Type' => $mimeType,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al descargar anexo de respuesta', [
                'response_id' => $responseId,
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el archivo',
            ], 500);
        }
    }

    /**
     * Export requests to Excel file.
     */
    public function exportExcel(ExportRequestsExcelRequest $request): BinaryFileResponse|JsonResponse
    {
        try {
            $user = $request->user();

            // Preparar filtros
            $dateRange = $request->input('date_range', []);
            $requestType = $request->input('request_type', 'all');
            // Normalize request_type if it's not 'all'
            if ($requestType !== 'all') {
                $requestType = RequestTypes::normalize($requestType);
            }
            $filters = [
                'request_type' => $requestType,
                'date_range' => [
                    'include_all' => $dateRange['include_all'] ?? true,
                    'start_date' => $dateRange['start_date'] ?? null,
                    'end_date' => $dateRange['end_date'] ?? null,
                ],
            ];

            // Generar reporte
            $filePath = $this->excelExportService->generateReport($filters);

            if (!file_exists($filePath)) {
                Log::error('Error generando reporte Excel de solicitudes: archivo no creado', [
                    'user_id' => $user->id,
                    'filters' => $filters,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            // Nombre del archivo
            $fileName = 'Reporte_Solicitudes_ProSalud_' . now()->setTimezone('America/Bogota')->format('Y-m-d') . '.xlsx';

            Log::info('Reporte Excel de solicitudes generado', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'filters' => $filters,
                'file_name' => $fileName,
            ]);

            // Registrar en auditoría
            $this->auditLogService->logBusinessProcess('request_form', 'excel_export', $this->auditLogService->addRequestContext($request, [
                'filters' => $filters,
                'file_name' => $fileName,
            ]));

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Error de validación al generar reporte Excel de solicitudes', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error generando reporte Excel de solicitudes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    /**
     * Verifica si existe una solicitud pendiente o en revisión de actualización de datos personales
     * que incluya actualización de correo electrónico para el mismo documento.
     *
     * @param RequestForm $requestForm La solicitud de certificado de convenio a verificar
     * @return bool true si existe una actualización de correo pendiente, false en caso contrario
     */
    private function tieneActualizacionCorreoPendiente(RequestForm $requestForm): bool
    {
        // Buscar solicitudes de actualización de datos personales pendientes o en revisión
        // para el mismo número de documento
        $solicitudActualizacion = RequestForm::where('request_type', RequestTypes::ACTUALIZAR_DATOS_PERSONALES)
            ->where('document_number', $requestForm->document_number)
            ->whereIn('status', [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW])
            ->where('id', '!=', $requestForm->id) // Excluir la solicitud actual si fuera de actualización
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$solicitudActualizacion) {
            Log::debug('tieneActualizacionCorreoPendiente: No se encontró solicitud de actualización pendiente', [
                'request_id' => $requestForm->id,
                'document_number' => $requestForm->document_number,
            ]);
            return false;
        }

        // Verificar si el payload incluye actualización de correo electrónico
        $payload = $solicitudActualizacion->payload ?? [];
        $tieneCorreo = !empty($payload['correo'] ?? null);

        Log::info('tieneActualizacionCorreoPendiente: Solicitud de actualización encontrada', [
            'request_id' => $requestForm->id,
            'solicitud_actualizacion_id' => $solicitudActualizacion->id,
            'solicitud_actualizacion_status' => $solicitudActualizacion->status,
            'tiene_correo' => $tieneCorreo,
            'correo_nuevo' => $tieneCorreo ? ($payload['correo'] ?? null) : null,
            'document_number' => $requestForm->document_number,
        ]);

        return $tieneCorreo;
    }

    /**
     * Determina si un certificado de convenio debe procesarse automáticamente
     * Se procesa automáticamente si:
     * - Tiene dirigidoBancolombia activo (permite cualquier combinación de otros campos, priorizando automatización)
     * - Tiene paraSubsidioVivienda activo (permite cualquier combinación de otros campos, priorizando automatización)
     * - Tiene solo fecha ingreso/retiro y/o dirigido a entidad (sin campos complejos)
     *
     * NO se procesa automáticamente si:
     * - Tiene "otros" activo (requiere revisión manual o creación manual por alguna particularidad)
     * - Existe una solicitud pendiente o en revisión de actualización de datos personales que incluya cambio de correo
     */
    private function debeProcesarCertificadoAutomatico(RequestForm $requestForm): bool
    {
        // Verificar primero si hay una actualización de correo pendiente
        // Si existe, no procesar automáticamente para evitar enviar el certificado al correo anterior
        if ($this->tieneActualizacionCorreoPendiente($requestForm)) {
            Log::info('debeProcesarCertificadoAutomatico: Existe actualización de correo pendiente - NO procesando automáticamente', [
                'request_id' => $requestForm->id,
                'document_number' => $requestForm->document_number,
            ]);
            return false;
        }

        $payload = $requestForm->payload ?? [];

        // Verificar si tiene infoCertificado en el payload
        if (!isset($payload['infoCertificado'])) {
            Log::debug('debeProcesarCertificadoAutomatico: No tiene infoCertificado en payload', [
                'request_id' => $requestForm->id,
                'payload_keys' => array_keys($payload),
            ]);
            return false;
        }

        // Parsear el JSON string si existe
        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
            Log::debug('debeProcesarCertificadoAutomatico: infoCertificado no es un array', [
                'request_id' => $requestForm->id,
                'infoCertificado_type' => gettype($payload['infoCertificado']),
            ]);
            return false;
        }

        // Parsear valores booleanos usando el método del modelo
        $fechaIngresoRetiro = $requestForm->parseBooleanValue($infoCertificado['fechaIngresoRetiro'] ?? false);
        $dirigidoAEntidad = $requestForm->parseBooleanValue($infoCertificado['dirigidoAEntidad'] ?? false);
        $dirigidoBancolombia = $requestForm->parseBooleanValue($infoCertificado['dirigidoBancolombia'] ?? false);
        $paraSubsidioVivienda = $requestForm->parseBooleanValue($infoCertificado['paraSubsidioVivienda'] ?? false);
        $otros = $requestForm->parseBooleanValue($infoCertificado['otros'] ?? false);

        Log::info('debeProcesarCertificadoAutomatico: Verificando opciones del certificado', [
            'request_id' => $requestForm->id,
            'fechaIngresoRetiro' => $fechaIngresoRetiro,
            'dirigidoAEntidad' => $dirigidoAEntidad,
            'dirigidoBancolombia' => $dirigidoBancolombia,
            'paraSubsidioVivienda' => $paraSubsidioVivienda,
            'otros' => $otros,
            'infoCertificado_raw' => $infoCertificado,
        ]);

        // Si tiene "otros" activo, NO procesar automáticamente sin importar otros campos
        // Esto requiere revisión manual o creación manual por alguna particularidad
        if ($otros) {
            Log::info('debeProcesarCertificadoAutomatico: Opción "Otros" detectada - NO procesando automáticamente (requiere revisión manual)', [
                'request_id' => $requestForm->id,
                'otros' => $otros,
                'otros_campos' => $infoCertificado,
            ]);
            return false;
        }

        // Si tiene dirigidoBancolombia activo, procesar automáticamente sin importar otros campos
        // Priorizando la automatización y generación automática
        if ($dirigidoBancolombia) {
            Log::info('debeProcesarCertificadoAutomatico: Certificado para Bancolombia detectado - procesando automáticamente sin importar otros campos', [
                'request_id' => $requestForm->id,
                'fechaIngresoRetiro' => $fechaIngresoRetiro,
                'dirigidoAEntidad' => $dirigidoAEntidad,
                'otros_campos' => $infoCertificado,
            ]);
            return true;
        }

        // Si tiene paraSubsidioVivienda activo, procesar automáticamente sin importar otros campos
        // Priorizando la automatización y generación automática
        if ($paraSubsidioVivienda) {
            Log::info('debeProcesarCertificadoAutomatico: Certificado para Subsidio de Vivienda detectado - procesando automáticamente sin importar otros campos', [
                'request_id' => $requestForm->id,
                'fechaIngresoRetiro' => $fechaIngresoRetiro,
                'dirigidoAEntidad' => $dirigidoAEntidad,
                'otros_campos' => $infoCertificado,
            ]);
            return true;
        }

        // Campos que NO deben estar activos para procesamiento automático (sin Bancolombia ni Subsidio de Vivienda)
        $camposNoPermitidos = [
            'valorCompensaciones',
            'paraSubsidioDesempleo',
            'paraSubsidioVivienda',
            'dirigidoFondoPensiones',
            'adicionarActividades',
            'dirigidoBancolombia',
            'otros',
        ];

        // Verificar que ningún campo no permitido esté activo
        foreach ($camposNoPermitidos as $campo) {
            $valorCampo = $requestForm->parseBooleanValue($infoCertificado[$campo] ?? false);
            if ($valorCampo) {
                Log::debug('debeProcesarCertificadoAutomatico: Campo no permitido activo', [
                    'request_id' => $requestForm->id,
                    'campo' => $campo,
                    'valor' => $infoCertificado[$campo] ?? null,
                ]);
                return false;
            }
        }

        // Si tiene fechaIngresoRetiro o dirigidoAEntidad activo, procesar automáticamente
        $resultado = $fechaIngresoRetiro || $dirigidoAEntidad;
        Log::info('debeProcesarCertificadoAutomatico: Resultado final', [
            'request_id' => $requestForm->id,
            'puede_procesar_automatico' => $resultado,
            'fechaIngresoRetiro' => $fechaIngresoRetiro,
            'dirigidoAEntidad' => $dirigidoAEntidad,
        ]);
        return $resultado;
    }

    /**
     * Verifica si el certificado tiene la opción de valor de compensaciones activa
     */
    private function tieneValorCompensaciones(RequestForm $requestForm): bool
    {
        $payload = $requestForm->payload ?? [];

        if (!isset($payload['infoCertificado'])) {
            return false;
        }

        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
            return false;
        }

        return !empty($infoCertificado['valorCompensaciones'] ?? false);
    }

    /**
     * Verifica si el certificado es para subsidio de vivienda
     *
     * @param RequestForm $requestForm
     * @return bool
     */
    private function esParaSubsidioVivienda(RequestForm $requestForm): bool
    {
        $payload = $requestForm->payload ?? [];

        if (!isset($payload['infoCertificado'])) {
            return false;
        }

        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
            return false;
        }

        return $requestForm->parseBooleanValue($infoCertificado['paraSubsidioVivienda'] ?? false);
    }

    /**
     * Verifica si se puede procesar automáticamente un certificado con compensaciones
     * Retorna array con 'puede_procesar', 'compensaciones' y 'razon'
     */
    private function puedeProcesarCertificadoConCompensaciones(RequestForm $requestForm): array
    {
        $documento = $requestForm->document_number;

        // 0. Verificar primero si tiene "otros" activo - si lo tiene, NO procesar automáticamente
        // Esto requiere revisión manual o creación manual por alguna particularidad
        $payload = $requestForm->payload ?? [];
        if (isset($payload['infoCertificado'])) {
            $infoCertificado = $payload['infoCertificado'];
            if (is_string($infoCertificado)) {
                $infoCertificado = json_decode($infoCertificado, true);
            }
            if (is_array($infoCertificado)) {
                $otros = $requestForm->parseBooleanValue($infoCertificado['otros'] ?? false);
                if ($otros) {
                    Log::info('puedeProcesarCertificadoConCompensaciones: Opción "Otros" detectada - NO procesando automáticamente (requiere revisión manual)', [
                        'request_id' => $requestForm->id,
                        'otros' => $otros,
                    ]);
                    return [
                        'puede_procesar' => false,
                        'razon' => 'Opción "Otros" seleccionada - requiere revisión manual',
                        'compensaciones' => null,
                    ];
                }
            }
        }

        // 1. Verificar que el afiliado esté activo
        try {
            $afiliadoData = $this->certificadoService->obtenerDatosAfiliado($documento);

            if (!$afiliadoData) {
                return [
                    'puede_procesar' => false,
                    'razon' => 'Afiliado no encontrado',
                    'compensaciones' => null,
                ];
            }

            $estado = strtoupper(trim($afiliadoData['afiliado']['estado'] ?? ''));
            $estaActivo = ($estado === 'ACTIVO' || $estado === 'ACTIVE');

            if (!$estaActivo) {
                return [
                    'puede_procesar' => false,
                    'razon' => 'Afiliado no está activo',
                    'compensaciones' => null,
                ];
            }

            // 2. Buscar compensaciones en el Excel
            Log::info('Buscando compensaciones en Excel para documento', [
                'documento' => $documento,
            ]);

            $compensaciones = $this->excelReaderService->buscarCompensacionPorDocumento($documento);

            Log::info('Resultado de búsqueda de compensaciones', [
                'documento' => $documento,
                'compensaciones_encontradas' => !empty($compensaciones),
                'compensaciones' => $compensaciones,
            ]);

            if (!$compensaciones) {
                return [
                    'puede_procesar' => false,
                    'razon' => 'No se encontró registro de compensaciones en el Excel',
                    'compensaciones' => null,
                ];
            }

            // 3. Verificar que los valores sean válidos
            if (!isset($compensaciones['t_basicos']) || !isset($compensaciones['t_auxilios']) || !isset($compensaciones['t_ingresos'])) {
                Log::warning('Datos de compensaciones incompletos', [
                    'documento' => $documento,
                    'compensaciones' => $compensaciones,
                ]);
                return [
                    'puede_procesar' => false,
                    'razon' => 'Datos de compensaciones incompletos',
                    'compensaciones' => null,
                ];
            }

            // 4. Verificar que los valores no sean cero
            if ($compensaciones['t_basicos'] == 0 && $compensaciones['t_auxilios'] == 0 && $compensaciones['t_ingresos'] == 0) {
                Log::warning('Datos de compensaciones están en cero', [
                    'documento' => $documento,
                    'compensaciones' => $compensaciones,
                ]);
                return [
                    'puede_procesar' => false,
                    'razon' => 'Los valores de compensaciones están en cero',
                    'compensaciones' => null,
                ];
            }

            Log::info('Compensaciones válidas encontradas, se puede procesar automáticamente', [
                'documento' => $documento,
                't_basicos' => $compensaciones['t_basicos'],
                't_auxilios' => $compensaciones['t_auxilios'],
                't_ingresos' => $compensaciones['t_ingresos'],
            ]);

            return [
                'puede_procesar' => true,
                'razon' => null,
                'compensaciones' => $compensaciones,
            ];
        } catch (\Throwable $e) {
            Log::error('Error verificando compensaciones para certificado', [
                'documento' => $documento,
                'error' => $e->getMessage(),
            ]);

            return [
                'puede_procesar' => false,
                'razon' => 'Error al verificar compensaciones: ' . $e->getMessage(),
                'compensaciones' => null,
            ];
        }
    }

    /**
     * Guarda un certificado generado en los archivos de la solicitud
     * Similar al método en CertificadoConvenioAutomaticoService
     *
     * @param string $requestId ID de la solicitud
     * @param string $rutaPdf Ruta local del archivo PDF
     * @param string $nombreArchivo Nombre del archivo
     * @return array Metadatos del archivo guardado
     * @throws \Exception Si no se puede leer o guardar el archivo
     */
    private function guardarCertificadoEnSolicitud(string $requestId, string $rutaPdf, string $nombreArchivo): array
    {
        try {
            // Leer el contenido del PDF
            $contenidoPDF = file_get_contents($rutaPdf);
            if ($contenidoPDF === false) {
                throw new \Exception("No se pudo leer el archivo PDF desde: {$rutaPdf}");
            }

            // Crear un nombre único para el archivo
            $nombreSinExtension = pathinfo($nombreArchivo, PATHINFO_FILENAME);
            $extension = pathinfo($nombreArchivo, PATHINFO_EXTENSION) ?: 'pdf';
            $nombreUnico = "certificado-convenio-actividades-{$requestId}-" . Str::random(8) . ".{$extension}";

            // Guardar en el bucket privado
            $disk = 'prosalud-private';
            $directorio = 'request-forms/' . date('Y/m');
            $rutaStorage = "{$directorio}/{$nombreUnico}";

            $guardado = Storage::disk($disk)->put($rutaStorage, $contenidoPDF);

            if (!$guardado) {
                // Intentar con disco de fallback
                $disk = 'local';
                $guardado = Storage::disk($disk)->put($rutaStorage, $contenidoPDF);

                if (!$guardado) {
                    throw new \Exception("No se pudo guardar el certificado en storage");
                }
            }

            return [
                'path' => $rutaStorage,
                'disk' => $disk,
                'original_name' => $nombreArchivo,
                'mime_type' => 'application/pdf',
                'size' => strlen($contenidoPDF),
                'original_key' => 'certificado_convenio_actividades',
            ];
        } catch (\Exception $e) {
            Log::error('Error guardando certificado en archivos de solicitud', [
                'request_id' => $requestId,
                'ruta_pdf' => $rutaPdf,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get pending personal data update requests.
     * This endpoint returns all requests with type "actualizar-datos-personales"
     * that have status PENDING. This is used to visually indicate and prioritize
     * when an affiliate has a pending personal data update request, as responding
     * to other requests before updating personal data (like email) could result
     * in responses being sent to incorrect email addresses.
     */
    public function pendingPersonalDataUpdates(Request $request): JsonResponse
    {
        $query = RequestForm::query()
            ->where('request_type', RequestTypes::ACTUALIZAR_DATOS_PERSONALES)
            ->where('status', RequestStatuses::PENDING)
            ->orderBy('created_at', 'desc');

        // Eager load responses and validator for better performance
        $requests = $query->with('responses', 'validator')->get();

        Log::info('Lista de solicitudes pendientes de actualización de datos personales consultada', [
            'total_requests' => $requests->count(),
        ]);

        // Return data WITHOUT obfuscation for administrative users
        // This endpoint requires authentication and 'requests.view' permission
        return response()->json([
            'success' => true,
            'data' => $requests->map(function ($requestForm) {
                // Get raw attributes to avoid any accessor transformations
                $attributes = $requestForm->getAttributes();

                return [
                    'id' => $requestForm->id,
                    'request_type' => $requestForm->request_type,
                    'document_type' => $requestForm->document_type,
                    'document_number' => $requestForm->document_number,
                    'name' => $requestForm->name,
                    'last_name' => $requestForm->last_name,
                    'full_name' => $requestForm->full_name,
                    // Contact information returned WITHOUT obfuscation for administrative processes
                    // Use getRawOriginal() to get raw value directly from database, bypassing any accessors or transformations
                    'email' => $requestForm->getRawOriginal('email') ?? $requestForm->getAttribute('email'),
                    'phone_number' => $requestForm->getRawOriginal('phone_number') ?? $requestForm->getAttribute('phone_number'),
                    'status' => $requestForm->status,
                    'payload' => $requestForm->payload,
                    'created_at' => $requestForm->created_at?->toIso8601String(),
                    'formatted_created_at' => $requestForm->formatted_created_at,
                    'processed_at' => $requestForm->processed_at?->toIso8601String(),
                    'formatted_processed_at' => $requestForm->formatted_processed_at,
                    'validated_at' => $requestForm->validated_at?->toIso8601String(),
                    'validated_by' => $requestForm->validator?->email,
                    'responses' => $requestForm->responses->map(function ($response) {
                        return [
                            'id' => $response->id,
                            'status' => $response->status,
                            'email_subject' => $response->email_subject,
                            'email_body' => $response->email_body,
                            'created_at' => $response->created_at,
                        ];
                    }),
                    'responses_count' => $requestForm->responses->count(),
                    'files' => $this->formatFilesMetadata($requestForm->files, $requestForm->id),
                    'files_count' => is_array($requestForm->files) ? count($requestForm->files) : 0,
                ];
            }),
        ]);
    }

    /**
     * Get human-readable status text.
     */
    private function getStatusText(string $status): string
    {
        return match ($status) {
            RequestStatuses::PENDING => 'pendiente',
            RequestStatuses::IN_REVIEW => 'en revisión',
            RequestStatuses::REJECTED => 'rechazada',
            RequestStatuses::COMPLETED => 'completada',
            default => 'desconocido',
        };
    }

    /**
     * Sanitize HTML content to prevent XSS attacks while allowing safe HTML tags and attributes.
     *
     * This method sanitizes HTML content to allow safe formatting tags and basic styling attributes
     * while preventing XSS attacks. It preserves table attributes like border, cellpadding, cellspacing,
     * and style attributes for proper table rendering in emails.
     *
     * Allowed tags: p, br, strong, b, em, i, u, table, tr, td, th, thead, tbody, ul, ol, li, div, span, h1-h6
     * Allowed attributes: border, cellpadding, cellspacing, style (with safe CSS only)
     *
     * @param string $html The HTML content to sanitize
     * @return string The sanitized HTML content
     */
    private function sanitizeHtmlContent(string $html): string
    {
        // List of allowed HTML tags for email content
        $allowedTagNames = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'table', 'tr', 'td', 'th', 'thead', 'tbody', 'ul', 'ol', 'li', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $allowedAttributes = ['border', 'cellpadding', 'cellspacing', 'style', 'align', 'valign', 'colspan', 'rowspan'];

        // Step 1: Sanitize attributes of allowed tags first
        $pattern = '/<(' . implode('|', $allowedTagNames) . ')(\s[^>]*)?>/i';
        $result = preg_replace_callback($pattern, function ($matches) use ($allowedAttributes) {
            $tagName = strtolower($matches[1]);
            $attributes = $matches[2] ?? '';
            $sanitizedAttrs = $this->sanitizeTagAttributes($attributes, $allowedAttributes);
            return '<' . $tagName . ($sanitizedAttrs ? ' ' . $sanitizedAttrs : '') . '>';
        }, $html);

        // Step 2: Remove dangerous tags (tags not in allowed list)
        $dangerousTagPattern = '/<\/?(?!' . implode('|', $allowedTagNames) . ')[^>]*>/i';
        $result = preg_replace($dangerousTagPattern, '', $result);

        // Step 3: CRITICAL - Separate tables from text content to prevent text from being absorbed into tables
        // Extract all tables with their complete structure
        // Use a balanced regex approach to match opening and closing table tags
        $tables = [];
        $placeholders = [];
        $offset = 0;

        // Find all table opening tags
        while (preg_match('/<table[^>]*>/i', $result, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $tableStart = $match[0][1];
            $tableTag = $match[0][0];
            $offset = $tableStart + strlen($tableTag);

            // Find the matching closing tag by counting nested tables
            $depth = 1;
            $tableContent = $tableTag;
            $searchOffset = $offset;

            while ($depth > 0 && $searchOffset < strlen($result)) {
                if (preg_match('/<\/?table[^>]*>/i', $result, $tagMatch, PREG_OFFSET_CAPTURE, $searchOffset)) {
                    $tagPos = $tagMatch[0][1];
                    $tag = $tagMatch[0][0];

                    if (preg_match('/^<\/table/i', $tag)) {
                        $depth--;
                    } elseif (preg_match('/^<table/i', $tag)) {
                        $depth++;
                    }

                    $tableContent .= substr($result, $searchOffset, $tagPos + strlen($tag) - $searchOffset);
                    $searchOffset = $tagPos + strlen($tag);

                    if ($depth === 0) {
                        break; // Found matching closing tag
                    }
                } else {
                    break; // No more table tags found
                }
            }

            // If we found a complete table, store it
            if ($depth === 0 && !empty($tableContent)) {
                // Ensure table is properly closed
                $tableContent = rtrim($tableContent);
                if (!preg_match('/<\/table>\s*$/i', $tableContent)) {
                    $tableContent .= '</table>';
                }

                $index = count($tables);
                $placeholder = "{{TABLE_PLACEHOLDER_{$index}}}";
                $tables[] = $tableContent;
                $placeholders[$placeholder] = $tableContent;

                // Replace table with placeholder
                $result = substr_replace($result, $placeholder, $tableStart, strlen($tableContent));
                $offset = $tableStart + strlen($placeholder);
            } else {
                // Table not properly closed, skip it
                break;
            }
        }

        // Step 4: Process text content (everything that's not a table)
        // Split by placeholders and process text blocks separately
        $parts = preg_split('/(\{\{TABLE_PLACEHOLDER_\d+\}\})/', $result, -1, PREG_SPLIT_DELIM_CAPTURE);
        $normalized = '';

        foreach ($parts as $part) {
            // Check if this is a table placeholder
            if (preg_match('/\{\{TABLE_PLACEHOLDER_(\d+)\}\}/', $part, $placeholderMatch)) {
                // Restore the table and add a clear separator after it
                $table = $placeholders[$part] ?? '';
                if (!empty($table)) {
                    // Ensure table is properly closed
                    $table = rtrim($table);
                    if (!preg_match('/<\/table>\s*$/i', $table)) {
                        $table .= '</table>';
                    }

                    // Add inline styles to table to control width (more compatible with email clients)
                    // Modify the table tag to include width control
                    if (preg_match('/<table([^>]*)>/i', $table, $tableMatch)) {
                        $tableAttrs = $tableMatch[1];
                        // Check if style attribute already exists
                        if (preg_match('/style\s*=\s*["\']([^"\']*)["\']/i', $tableAttrs, $styleMatch)) {
                            // Remove width:100% if present and replace with auto
                            $existingStyle = $styleMatch[1];
                            // Remove width:100% and width:100% !important
                            $existingStyle = preg_replace('/width\s*:\s*100%\s*!important;?/i', '', $existingStyle);
                            $existingStyle = preg_replace('/width\s*:\s*100%;?/i', '', $existingStyle);
                            $existingStyle = trim($existingStyle, '; ');
                            // Append new width control styles
                            $newStyle = (!empty($existingStyle) ? $existingStyle . '; ' : '') . 'width:auto !important; max-width:100% !important; margin:16px auto !important;';
                            $table = preg_replace('/style\s*=\s*["\']([^"\']*)["\']/i', 'style="' . htmlspecialchars($newStyle, ENT_QUOTES) . '"', $table, 1);
                        } else {
                            // Add new style attribute
                            $table = preg_replace('/<table([^>]*)>/i', '<table$1 style="width:auto !important; max-width:100% !important; margin:16px auto !important;">', $table, 1);
                        }
                    }

                    // Ensure all table rows have consistent alignment
                    $table = preg_replace_callback('/<tr([^>]*)>/i', function($matches) {
                        $attrs = $matches[1];

                        // Check if style attribute exists
                        if (preg_match('/style\s*=\s*["\']([^"\']*)["\']/i', $attrs, $styleMatch)) {
                            $existingStyle = $styleMatch[1];
                            // Remove any existing vertical-align
                            $existingStyle = preg_replace('/vertical-align\s*:\s*[^;]+;?/i', '', $existingStyle);
                            $existingStyle = trim($existingStyle, '; ');
                            $newStyle = (!empty($existingStyle) ? $existingStyle . '; ' : '') . 'vertical-align:middle;';
                            $attrs = preg_replace('/style\s*=\s*["\']([^"\']*)["\']/i', 'style="' . htmlspecialchars($newStyle, ENT_QUOTES) . '"', $attrs, 1);
                        } else {
                            $attrs = rtrim($attrs) . ' style="vertical-align:middle;"';
                        }

                        return '<tr' . $attrs . '>';
                    }, $table);

                    // Ensure all table cells (td/th) have proper vertical alignment
                    // This prevents vertical misalignment issues in email clients
                    // Force consistent vertical alignment, line-height, and padding for all cells
                    $table = preg_replace_callback('/<(td|th)([^>]*)>/i', function($matches) {
                        $tagName = $matches[1];
                        $attrs = $matches[2];

                        // Check if style attribute exists
                        if (preg_match('/style\s*=\s*["\']([^"\']*)["\']/i', $attrs, $styleMatch)) {
                            $existingStyle = $styleMatch[1];
                            // Remove any existing vertical-align to force our own
                            $existingStyle = preg_replace('/vertical-align\s*:\s*[^;]+;?/i', '', $existingStyle);
                            // Remove any existing line-height that might cause issues
                            $existingStyle = preg_replace('/line-height\s*:\s*[^;]+;?/i', '', $existingStyle);
                            // Clean up and add consistent styles
                            $existingStyle = trim($existingStyle, '; ');
                            // Force consistent vertical alignment and line-height - use !important to override any conflicting styles
                            $newStyle = (!empty($existingStyle) ? $existingStyle . '; ' : '') . 'vertical-align:middle !important; line-height:1.5 !important;';
                            $attrs = preg_replace('/style\s*=\s*["\']([^"\']*)["\']/i', 'style="' . htmlspecialchars($newStyle, ENT_QUOTES) . '"', $attrs, 1);
                        } else {
                            // Add style attribute with vertical-align and line-height
                            $attrs = rtrim($attrs) . ' style="vertical-align:middle !important; line-height:1.5 !important;"';
                        }

                        return '<' . $tagName . $attrs . '>';
                    }, $table);

                    // Add a clear separator after the table to prevent content absorption
                    $normalized .= $table . '<p style="margin:16px 0; padding:0; clear:both; display:block; height:0; line-height:0; font-size:0;"></p>';
                }
            } else {
                // This is text content - process it
                $text = $part; // Don't trim yet, preserve whitespace structure

                if (!empty(trim($text))) {
                    // Check if text already starts with a block-level tag (p, div, h1-h6, etc.)
                    $startsWithBlock = preg_match('/^\s*<(?:p|div|h[1-6]|ul|ol)/i', $text);

                    if ($startsWithBlock) {
                        // Text already has block-level structure, just ensure line breaks are preserved
                        // Convert line breaks in text nodes to <br>
                        $text = preg_replace('/([^<>\n]+\n[^<>\n]+)/', function($m) {
                            return nl2br($m[1], false);
                        }, $text);
                        $normalized .= $text;
                    } else {
                        // Text needs to be wrapped in paragraphs
                        // Split by double line breaks (paragraph breaks) first
                        $paragraphs = preg_split('/\r?\n\s*\r?\n/', $text);

                        foreach ($paragraphs as $para) {
                            $para = trim($para);
                            if (empty($para)) {
                                continue;
                            }

                            // Check if this paragraph already has HTML tags
                            if (preg_match('/<[^>]+>/', $para)) {
                                // Has HTML tags like <strong>, <br>, etc.
                                // Convert line breaks in plain text portions to <br>
                                $para = preg_replace_callback('/([^<>\n]+)/', function($m) {
                                    $content = $m[1];
                                    if (trim($content) !== '') {
                                        return nl2br($content, false);
                                    }
                                    return $content;
                                }, $para);
                            } else {
                                // Plain text - convert line breaks to <br>
                                $para = nl2br($para, false);
                            }

                            // Wrap in <p> tag
                            $normalized .= '<p>' . $para . '</p>';
                        }
                    }
                }
            }
        }

        // Step 5: Final sanitization pass - ensure all tags have sanitized attributes
        $final = preg_replace_callback($pattern, function ($matches) use ($allowedAttributes) {
            $tagName = strtolower($matches[1]);
            $attributes = $matches[2] ?? '';
            $sanitizedAttrs = $this->sanitizeTagAttributes($attributes, $allowedAttributes);
            return '<' . $tagName . ($sanitizedAttrs ? ' ' . $sanitizedAttrs : '') . '>';
        }, $normalized);

        // Step 6: Additional security - Remove dangerous URLs and event handlers
        $final = preg_replace('/(javascript|data|vbscript):/i', '', $final);
        $final = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $final);

        // Step 7: CRITICAL - Ensure all tables are properly closed and content after tables is isolated
        // Count opening and closing table tags
        $openTables = preg_match_all('/<table[^>]*>/i', $final);
        $closeTables = preg_match_all('/<\/table>/i', $final);

        // If there are unclosed tables, close them
        while ($openTables > $closeTables) {
            $final .= '</table>';
            $closeTables++;
        }

        // Add a final separator to ensure nothing after gets absorbed
        // This is a safety measure for email clients that might misinterpret the HTML
        if ($openTables > 0) {
            $final .= '<!-- END_TABLES -->';
        }

        return $final;
    }

    /**
     * Sanitize HTML tag attributes, allowing only safe attributes with safe values.
     *
     * @param string $attributesString The attributes string from an HTML tag
     * @param array $allowedAttributes List of allowed attribute names
     * @return string Sanitized attributes string
     */
    private function sanitizeTagAttributes(string $attributesString, array $allowedAttributes): string
    {
        if (empty(trim($attributesString))) {
            return '';
        }

        $sanitized = [];

        // Extract attributes using regex
        preg_match_all('/(\w+)\s*=\s*["\']([^"\']*)["\']/', $attributesString, $attrMatches, PREG_SET_ORDER);

        foreach ($attrMatches as $attrMatch) {
            $attrName = strtolower(trim($attrMatch[1]));
            $attrValue = $attrMatch[2];

            // Only allow specified attributes
            if (!in_array($attrName, $allowedAttributes)) {
                continue;
            }

            // Sanitize attribute values based on attribute type
            if ($attrName === 'style') {
                // Allow safe CSS properties only (colors, borders, padding, etc.)
                // Remove dangerous CSS like expression(), javascript:, etc.
                $attrValue = preg_replace('/(expression|javascript|@import|behavior|binding)/i', '', $attrValue);
                $attrValue = preg_replace('/url\s*\(\s*["\']?(javascript|data|vbscript):/i', '', $attrValue);
            } elseif (in_array($attrName, ['border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan'])) {
                // Numeric attributes - only allow digits
                $attrValue = preg_replace('/[^0-9]/', '', $attrValue);
            } elseif (in_array($attrName, ['align', 'valign'])) {
                // Alignment attributes - only allow safe values
                $safeAlignValues = ['left', 'right', 'center', 'justify', 'top', 'middle', 'bottom'];
                if (!in_array(strtolower($attrValue), $safeAlignValues)) {
                    continue;
                }
            }

            // Escape quotes in attribute values
            $attrValue = htmlspecialchars($attrValue, ENT_QUOTES, 'UTF-8');

            $sanitized[] = $attrName . '="' . $attrValue . '"';
        }

        return implode(' ', $sanitized);
    }

    /**
     * Export template Excel for bulk response.
     */
    public function exportBulkResponseTemplate(BulkRequestResponseRequest $request): BinaryFileResponse|JsonResponse
    {
        try {
            $user = $request->user();

            // Preparar filtros
            $dateRange = $request->input('date_range', []);
            $requestType = $request->input('request_type', 'all');
            // Normalize request_type if it's not 'all'
            if ($requestType !== 'all') {
                $requestType = RequestTypes::normalize($requestType);
            }
            $filters = [
                'request_type' => $requestType,
                'date_range' => [
                    'include_all' => $dateRange['include_all'] ?? true,
                    'start_date' => $dateRange['start_date'] ?? null,
                    'end_date' => $dateRange['end_date'] ?? null,
                ],
            ];

            // Validar que existan solicitudes pendientes o en revisión antes de generar la plantilla
            $requestsCount = $this->bulkResponseService->countRequestsForTemplate($filters);
            
            if ($requestsCount === 0) {
                $requestTypeLabel = $requestType !== 'all' 
                    ? $this->bulkResponseService->getRequestTypeLabel($requestType)
                    : 'solicitudes';
                
                Log::info('Intento de generar plantilla sin solicitudes disponibles', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'filters' => $filters,
                    'request_type_label' => $requestTypeLabel,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "No hay solicitudes pendientes o en revisión de tipo '{$requestTypeLabel}' para generar la plantilla.",
                    'request_type' => $requestType,
                    'request_type_label' => $requestTypeLabel,
                    'filters' => $filters,
                ], 422);
            }

            // Generar plantilla
            $filePath = $this->bulkResponseService->generateTemplate($filters);

            if (!file_exists($filePath)) {
                Log::error('Error generando plantilla de respuesta masiva: archivo no creado', [
                    'user_id' => $user->id,
                    'filters' => $filters,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar la plantilla',
                ], 500);
            }

            // Nombre del archivo
            $fileName = 'Plantilla_Respuestas_Masivas_' . now()->setTimezone('America/Bogota')->format('Y-m-d_His') . '.xlsx';

            Log::info('Plantilla de respuesta masiva generada', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'filters' => $filters,
                'file_name' => $fileName,
            ]);

            // Registrar en auditoría
            $this->auditLogService->logBusinessProcess('request_form', 'bulk_response_template_export', $this->auditLogService->addRequestContext($request, [
                'filters' => $filters,
                'file_name' => $fileName,
            ]));

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $requestType = $request->input('request_type', 'no proporcionado');
            
            Log::warning('Error de validación al generar plantilla de respuesta masiva', [
                'error' => $e->getMessage(),
                'errors' => $errors,
                'request_type_received' => $requestType,
                'valid_request_types' => array_merge(RequestTypes::all(), ['all']),
                'user_id' => $request->user()->id ?? null,
                'user_email' => $request->user()->email ?? null,
                'all_input' => $request->all(),
            ]);

            // Formatear mensajes de error de manera más clara
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Error de validación en los parámetros enviados',
                'errors' => $errors,
                'error_details' => $errorMessages,
            ], 422);
        } catch (\InvalidArgumentException $e) {
            $requestType = $request->input('request_type', 'no proporcionado');
            
            Log::warning('Error de validación al generar plantilla de respuesta masiva', [
                'error' => $e->getMessage(),
                'request_type_received' => $requestType,
                'valid_request_types' => array_merge(RequestTypes::all(), ['all']),
                'user_id' => $request->user()->id ?? null,
                'user_email' => $request->user()->email ?? null,
                'all_input' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'request_type_received' => $requestType,
            ], 400);
        } catch (\Exception $e) {
            $requestType = $request->input('request_type', 'no proporcionado');
            
            Log::error('Error generando plantilla de respuesta masiva', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'request_type_received' => $requestType,
                'user_id' => $request->user()->id ?? null,
                'user_email' => $request->user()->email ?? null,
                'all_input' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar la plantilla. Por favor, intente nuevamente.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Process bulk response from Excel file.
     */
    public function processBulkResponse(ProcessBulkResponseRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $uploadedFile = $request->file('file');

            if (!$uploadedFile || !$uploadedFile->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no es válido',
                ], 400);
            }

            // Usar directamente el archivo temporal que Laravel crea al subir
            // Esto es más confiable que guardarlo nuevamente
            $tempFilePath = $uploadedFile->getRealPath();

            // Si getRealPath() no funciona, usar getPathname() como alternativa
            if (!$tempFilePath || !file_exists($tempFilePath)) {
                $tempFilePath = $uploadedFile->getPathname();
            }

            // Verificar que el archivo existe
            if (!$tempFilePath || !file_exists($tempFilePath)) {
                Log::error('No se pudo obtener la ruta del archivo temporal', [
                    'getRealPath' => $uploadedFile->getRealPath(),
                    'getPathname' => $uploadedFile->getPathname(),
                    'isValid' => $uploadedFile->isValid(),
                    'original_name' => $uploadedFile->getClientOriginalName(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Error: No se pudo acceder al archivo subido',
                ], 500);
            }

            try {
                // Procesar archivo
                $results = $this->bulkResponseService->processBulkResponse($tempFilePath, $user);

                // Registrar en auditoría
                $this->auditLogService->logBusinessProcess('request_form', 'bulk_response_processed', $this->auditLogService->addRequestContext($request, [
                    'total' => $results['total'],
                    'successful' => $results['successful'],
                    'failed' => $results['failed'],
                ]));

                Log::info('Procesamiento masivo de respuestas completado', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'results' => $results,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Procesamiento completado',
                    'data' => $results,
                ]);
            } finally {
                // No eliminar el archivo temporal aquí porque Laravel lo maneja automáticamente
                // El archivo temporal se elimina cuando el request termina
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $fileName = $request->file('file')?->getClientOriginalName() ?? 'no proporcionado';
            
            Log::warning('Error de validación al procesar respuesta masiva', [
                'error' => $e->getMessage(),
                'errors' => $errors,
                'file_name' => $fileName,
                'file_size' => $request->file('file')?->getSize(),
                'file_mime_type' => $request->file('file')?->getMimeType(),
                'user_id' => $request->user()->id ?? null,
                'user_email' => $request->user()->email ?? null,
            ]);

            // Formatear mensajes de error de manera más clara
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Error de validación en el archivo enviado',
                'errors' => $errors,
                'error_details' => $errorMessages,
                'file_name' => $fileName,
            ], 422);
        } catch (\InvalidArgumentException $e) {
            $fileName = $request->file('file')?->getClientOriginalName() ?? 'no proporcionado';
            
            Log::warning('Error de validación al procesar respuesta masiva', [
                'error' => $e->getMessage(),
                'file_name' => $fileName,
                'file_size' => $request->file('file')?->getSize(),
                'user_id' => $request->user()->id ?? null,
                'user_email' => $request->user()->email ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file_name' => $fileName,
            ], 400);
        } catch (\Exception $e) {
            $fileName = $request->file('file')?->getClientOriginalName() ?? 'no proporcionado';
            
            Log::error('Error procesando respuesta masiva', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'file_name' => $fileName,
                'file_size' => $request->file('file')?->getSize(),
                'file_mime_type' => $request->file('file')?->getMimeType(),
                'user_id' => $request->user()->id ?? null,
                'user_email' => $request->user()->email ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo. Por favor, verifique el formato y vuelva a intentar.',
                'error' => config('app.debug') ? $e->getMessage() : null,
                'file_name' => $fileName,
            ], 500);
        }
    }

    /**
     * Ruta temporal pública para reintentar el proceso automático de generación de certificado
     * cuando falló por intermitencia del servicio de conversión Word a PDF
     * 
     * @param Request $request
     * @param string $requestId ID de la solicitud
     * @return JsonResponse
     */
    public function retryCertificateGeneration(Request $request, string $requestId): JsonResponse
    {
        try {
            Log::info('Iniciando reintento de generación de certificado', [
                'request_id' => $requestId,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Buscar la solicitud
            $requestForm = RequestForm::find($requestId);

            if (!$requestForm) {
                Log::warning('Solicitud no encontrada para reintento', [
                    'request_id' => $requestId,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Solicitud no encontrada',
                ], 404);
            }

            // Verificar que sea una solicitud de certificado de convenio
            if ($requestForm->request_type !== RequestTypes::CERTIFICADO_CONVENIO) {
                Log::warning('La solicitud no es de tipo certificado de convenio', [
                    'request_id' => $requestId,
                    'request_type' => $requestForm->request_type,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'La solicitud no es de tipo certificado de convenio',
                ], 400);
            }

            // Verificar si la solicitud ya está completada
            if ($requestForm->status === RequestStatuses::COMPLETED) {
                Log::info('La solicitud ya está completada', [
                    'request_id' => $requestId,
                    'status' => $requestForm->status,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'La solicitud ya está completada',
                    'request_id' => $requestId,
                    'status' => $requestForm->status,
                ], 200);
            }

            // Verificar si tiene compensaciones
            $puedeProcesarConCompensaciones = $this->puedeProcesarCertificadoConCompensaciones($requestForm);

            $resultado = null;

            if ($puedeProcesarConCompensaciones['puede_procesar']) {
                // Procesar con compensaciones
                Log::info('Reintentando procesamiento automático con compensaciones', [
                    'request_id' => $requestId,
                    'compensaciones' => $puedeProcesarConCompensaciones['compensaciones'],
                ]);

                $resultado = $this->certificadoAutomaticoService->procesarConRequestFormExistenteYCompensaciones(
                    $requestForm,
                    $puedeProcesarConCompensaciones['compensaciones']
                );
            } else {
                // Procesar sin compensaciones
                Log::info('Reintentando procesamiento automático sin compensaciones', [
                    'request_id' => $requestId,
                    'razon_no_compensaciones' => $puedeProcesarConCompensaciones['razon'] ?? 'No aplica',
                ]);

                $resultado = $this->certificadoAutomaticoService->procesarConRequestFormExistente($requestForm);
            }

            Log::info('Reintento de generación de certificado completado exitosamente', [
                'request_id' => $requestId,
                'consecutivo' => $resultado['consecutivo'] ?? null,
                'success' => $resultado['success'] ?? false,
            ]);

            return response()->json([
                'success' => $resultado['success'] ?? true,
                'message' => 'Proceso de generación de certificado reintentado exitosamente',
                'request_id' => $requestId,
                'consecutivo' => $resultado['consecutivo'] ?? null,
                'status' => $resultado['status'] ?? $requestForm->status,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error en reintento de generación de certificado', [
                'request_id' => $requestId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al reintentar la generación del certificado',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Normalize subtype value for comparison.
     * Handles variations in casing and common format differences.
     */
    private function normalizeSubtypeValue(string $subtype): string
    {
        // Normalize to uppercase and trim
        $normalized = mb_strtoupper(trim($subtype));
        
        // Remove common variations that don't affect matching
        // For example: "Y/O DESCANSO" variations
        $normalized = preg_replace('/\s*Y\/O\s*DESCANSO\s*/i', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized); // Normalize multiple spaces
        $normalized = trim($normalized);
        
        return $normalized;
    }
}
