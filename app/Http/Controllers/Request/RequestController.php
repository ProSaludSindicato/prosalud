<?php

namespace App\Http\Controllers\Request;

use App\Constants\RequestStatuses;
use App\Domain\RequestForm\RequestFormDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\RespondToRequestRequest;
use App\Models\RequestForm;
use App\Models\RequestResponse;
use App\Mail\RequestFormReceived;
use App\Mail\RequestFormResponse;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RequestController extends Controller
{
    public function __construct(
        private AuditLogService $auditLogService
    ) {}
    public function store(StoreRequestFormRequest $request): JsonResponse
    {
        $dto = RequestFormDTO::fromArray($request->validated());
        $requestData = $dto->toArray();

        $requestData['status'] = RequestStatuses::PENDING;

        $requestForm = new RequestForm($requestData);
        $requestForm->created_at = now();
        $requestForm->save();

        try {
            Mail::to($requestForm->email)
                ->cc('comunicaciones-prosalud@yopmail.com')
                // ->cc('comunicaciones@sindicatoprosalud.com')
                ->send(new RequestFormReceived($requestForm));
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de confirmación de solicitud', [
                'request_id' => $requestForm->id,
                'email' => $requestForm->email,
                'error' => $e->getMessage(),
            ]);
        }

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
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->auditLogService->logBusinessProcess('request_form', 'created', $this->auditLogService->addRequestContext($request, [
            'request_id' => $requestForm->id,
            'request_type' => $requestForm->request_type,
            'affiliate_document' => $requestForm->document_number,
            'affiliate_email' => $requestForm->email,
        ]));

        return response()->json([
            'message' => 'Solicitud recibida exitosamente',
            'data' => [
                'id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'status' => $requestForm->status,
                'created_at' => $requestForm->formatted_created_at,
            ]
        ], 201);
    }

    /**
     * Get a summary of payload data for logging
     */
    private function getPayloadSummary(array $payload): array
    {
        $summary = [];

        $commonFields = [
            'proceso', 'dondeRealizaProceso', 'motivoSolicitud',
            'dirigidoAQuien', 'tipoVehiculo', 'placaVehiculo',
            'infoCertificado', 'otrosDescripcion'
        ];

        foreach ($commonFields as $field) {
            if (isset($payload[$field])) {
                $summary[$field] = $payload[$field];
            }
        }

        return $summary;
    }

    /**
     * Display a listing of requests
     */
    public function index(Request $request): JsonResponse
    {
        $query = RequestForm::query();

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
            $query->where('request_type', $request->get('request_type'));
        }

        // Order by created_at desc by default
        $query->orderBy('created_at', 'desc');

        // Eager load responses for better performance
        $requests = $query->with('responses')->get();

        Log::info('Lista de solicitudes consultada', [
            'total_requests' => $requests->count(),
            'filters' => $request->only(['search', 'status', 'request_type'])
        ]);

        return response()->json([
            'success' => true,
            'data' => $requests->map(function ($request) {
                return [
                    'id' => $request->id,
                    'request_type' => $request->request_type,
                    'document_type' => $request->document_type,
                    'document_number' => $request->document_number,
                    'name' => $request->name,
                    'last_name' => $request->last_name,
                    'full_name' => $request->full_name,
                    'email' => $request->email,
                    'phone_number' => $request->phone_number,
                    'status' => $request->status,
                    'payload' => $request->payload,
                    'created_at' => $request->created_at,
                    'formatted_created_at' => $request->formatted_created_at,
                    'processed_at' => $request->processed_at,
                    'formatted_processed_at' => $request->formatted_processed_at,
                    'responses' => $request->responses->map(function ($response) {
                        return [
                            'id' => $response->id,
                            'status' => $response->status,
                            'email_subject' => $response->email_subject,
                            'email_body' => $response->email_body,
                            'created_at' => $response->created_at,
                        ];
                    }),
                    'responses_count' => $request->responses->count(),
                ];
            })
        ]);
    }

    /**
     * Display the specified request
     */
    public function show(RequestForm $request): JsonResponse
    {
        // Load responses relationship
        $request->load('responses');

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
            ]
        ]);

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
                'email' => $request->email,
                'phone_number' => $request->phone_number,
                'payload' => json_encode($request->payload ?? (object) [], JSON_UNESCAPED_UNICODE),
                'status' => $request->status,
                'created_at' => $request->created_at,
                'formatted_created_at' => $request->formatted_created_at,
                'processed_at' => $request->processed_at,
                'formatted_processed_at' => $request->formatted_processed_at,
                'responses' => $request->responses->map(function ($response) {
                    return [
                        'id' => $response->id,
                        'status' => $response->status,
                        'email_subject' => $response->email_subject,
                        'email_body' => $response->email_body,
                        'created_at' => $response->created_at,
                    ];
                }),
                'responses_count' => $request->responses->count(),
            ]
        ]);
    }

    /**
     * Change request status
     */
    public function changeStatus(ChangeRequestStatusRequest $statusRequest, RequestForm $request): JsonResponse
    {
        $status = $statusRequest->validated()['status'];

        $updateData = ['status' => $status];

        if ($status === RequestStatuses::COMPLETED || $status === RequestStatuses::REJECTED) {
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
            ]
        ]);
    }

    /**
     * Get human-readable status text
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
     * Respond to a request with email and status update
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

        $validated = $request->validated();
        $status = $validated['status'];
        $emailSubject = $validated['email_subject'];
        $emailBody = $validated['email_body'];

        // Get attachments if provided
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

        // Prepare data for logging
        $oldStatus = $requestForm->status;
        $requestFormId = (string) $requestForm->id;

        Log::info('Iniciando proceso de respuesta a solicitud', [
            'request_id' => $requestFormId,
            'request_type' => $requestForm->request_type,
            'old_status' => $oldStatus,
            'new_status' => $status,
            'email' => $requestForm->email,
            'has_attachments' => !empty($attachments),
            'attachments_count' => count($attachments),
        ]);

        // IMPORTANT: Send email FIRST, before updating status or creating response record
        // This ensures that if email fails, we don't update the request status
        try {
            Log::info('Intentando enviar correo de respuesta', [
                'request_id' => $requestFormId,
                'email_to' => $requestForm->email,
                'email_cc' => 'juanpapabon@gmail.com',
                'email_subject' => $emailSubject,
                'email_body_length' => strlen($emailBody),
                'attachments_count' => count($attachments),
            ]);

            Mail::to($requestForm->email)
                ->cc('juanpapabon@gmail.com') // Hardcoded as per requirements
                ->send(new RequestFormResponse(
                    $requestForm,
                    $emailSubject,
                    $emailBody,
                    $status,
                    $attachments // This parameter is renamed to $uploadedFiles in RequestFormResponse constructor
                ));

            Log::info('Correo de respuesta enviado exitosamente', [
                'request_id' => $requestFormId,
                'email' => $requestForm->email,
                'status' => $status,
                'has_attachments' => !empty($attachments),
                'attachments_count' => count($attachments),
            ]);

        } catch (\Throwable $e) {
            // Log detailed error information
            Log::error('FALLO AL ENVIAR CORREO DE RESPUESTA - NO SE ACTUALIZARÁ EL ESTADO', [
                'request_id' => $requestFormId,
                'request_type' => $requestForm->request_type,
                'email' => $requestForm->email,
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

        if ($status === RequestStatuses::COMPLETED || $status === RequestStatuses::REJECTED) {
            $updateData['processed_at'] = now();
        } else {
            // For other statuses, clear processed_at
            $updateData['processed_at'] = null;
        }

        $requestForm->update($updateData);
        $requestForm->refresh(); // Refresh to ensure we have the latest data

        // Store the response for traceability (only after email is sent successfully)
        $requestResponse = RequestResponse::create([
            'request_form_id' => $requestFormId,
            'status' => $status,
            'email_subject' => $emailSubject,
            'email_body' => $emailBody,
            'created_at' => now(),
        ]);

        Log::info('Respuesta de solicitud procesada exitosamente', [
            'request_id' => $requestFormId,
            'response_id' => $requestResponse->id,
            'request_type' => $requestForm->request_type,
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
            ]
        ]);
    }
}
