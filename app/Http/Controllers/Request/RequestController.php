<?php

namespace App\Http\Controllers\Request;

use App\Constants\RequestStatuses;
use App\Domain\RequestForm\RequestFormDTO;
use App\Http\Controllers\Controller;
use App\Models\RequestForm;
use App\Mail\RequestFormReceived;
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
                ->cc('comunicaciones@sindicatoprosalud.com')
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

        $requests = $query->get();

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
                ];
            })
        ]);
    }

    /**
     * Display the specified request
     */
    public function show(RequestForm $request): JsonResponse
    {
        Log::info('Solicitud consultada', [
            'request_id' => $request->id,
            'request_type' => $request->request_type,
            'status' => $request->status,
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

        // If changing to completed, set processed_at timestamp
        if ($status === RequestStatuses::COMPLETED) {
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
}
