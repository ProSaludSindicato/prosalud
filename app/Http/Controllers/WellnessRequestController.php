<?php

namespace App\Http\Controllers;

use App\Http\Requests\{ExportWellnessExcelRequest, StoreWellnessRequestRequest, UpdateWellnessRequestRequest};
use App\Mail\{WellnessRequestReceived, WellnessRequestUpdated};
use App\Models\{User, WellnessRequest};
use App\Services\{AuditLogService, WellnessExcelExportService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log, Mail, Storage};
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WellnessRequestController extends Controller
{
    public function __construct(
        private WellnessExcelExportService $excelExportService,
        private AuditLogService $auditLogService,
    ) {
    }

    /**
     * Display a listing of wellness requests.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WellnessRequest::with(['requester', 'details']);

        // Filter by status
        if ($request->has('estado')) {
            $query->where('status', $request->input('estado'));
        }

        // Filter by cost center
        if ($request->has('centroCostos')) {
            $query->where('cost_center', $request->input('centroCostos'));
        }

        // Filter by requester
        if ($request->has('solicitanteId')) {
            $query->where('requester_id', $request->input('solicitanteId'));
        }

        // Filter by date range
        if ($request->has('fechaDesde')) {
            $query->whereDate('proposed_date', '>=', $request->input('fechaDesde'));
        }

        if ($request->has('fechaHasta')) {
            $query->whereDate('proposed_date', '<=', $request->input('fechaHasta'));
        }

        // Search by activity name
        if ($request->has('busqueda')) {
            $search = $request->input('busqueda');
            $query->where(function ($q) use ($search) {
                $q->where('activity_name', 'like', "%{$search}%")
                  ->orWhere('activity_description', 'like', "%{$search}%");
            });
        }

        // Order by proposed date (most recent first) or created at
        $orderBy = $request->input('ordenarPor', 'created_at');
        $orderDirection = $request->input('direccion', 'desc');
        $query->orderBy($orderBy, $orderDirection);

        // Pagination
        $perPage = $request->integer('per_page', 15);
        $wellnessRequests = $query->paginate($perPage);

        // Transform to Spanish keys for frontend
        $transformedItems = $wellnessRequests->map(function ($wellnessRequest) {
            return $this->formatWellnessRequestResponse($wellnessRequest);
        });

        Log::info('Wellness requests list retrieved', [
            'total' => $wellnessRequests->total(),
            'current_page' => $wellnessRequests->currentPage(),
            'per_page' => $wellnessRequests->perPage(),
            'filters' => $request->only(['estado', 'centroCostos', 'solicitanteId', 'fechaDesde', 'fechaHasta', 'busqueda']),
        ]);

        return response()->json([
            'success' => true,
            'data' => $transformedItems,
            'pagination' => [
                'current_page' => $wellnessRequests->currentPage(),
                'per_page' => $wellnessRequests->perPage(),
                'total' => $wellnessRequests->total(),
                'last_page' => $wellnessRequests->lastPage(),
                'from' => $wellnessRequests->firstItem(),
                'to' => $wellnessRequests->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created wellness request.
     */
    public function store(StoreWellnessRequestRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Get transformed data (Spanish to English mapping)
            $wellnessRequestData = $request->getTransformedData();
            $wellnessRequestData['status'] = 'pending';

            // Create the wellness request
            $wellnessRequest = WellnessRequest::create($wellnessRequestData);

            // Create details if requires_details is true
            $transformedDetails = $request->getTransformedDetails();
            if (!empty($transformedDetails)) {
                $details = [];
                foreach ($transformedDetails as $detail) {
                    $details[] = [
                        'wellness_request_id' => $wellnessRequest->id,
                        'type' => $detail['type'],
                        'quantity' => $detail['quantity'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                $wellnessRequest->details()->insert($details);
            }

            // Load relationships for email
            $wellnessRequest->load('requester', 'details');

            DB::commit();

            Log::info('New wellness request created', [
                'wellness_request_id' => $wellnessRequest->id,
                'activity_name' => $wellnessRequest->activity_name,
                'cost_center' => $wellnessRequest->cost_center,
                'locations' => $wellnessRequest->locations,
                'proposed_date' => $wellnessRequest->proposed_date,
                'requester_id' => $wellnessRequest->requester_id,
                'status' => $wellnessRequest->status,
            ]);

            // Preparar la respuesta antes de enviar el correo
            $response = response()->json([
                'success' => true,
                'message' => 'Solicitud de bienestar creada exitosamente',
                'data' => $this->formatWellnessRequestResponse($wellnessRequest),
            ], 200);

            // Enviar correo de notificación de forma asíncrona después de enviar la respuesta HTTP
            // Esto evita que el envío de correo bloquee la respuesta al frontend
            $requester = User::find($request->input('solicitanteId'));
            $requesterEmail = $requester ? $requester->email : null;
            $wellnessRequestForEmail = $wellnessRequest;

            if ($requesterEmail) {
                dispatch(function () use ($wellnessRequestForEmail, $requesterEmail) {
                    try {
                        Mail::to($requesterEmail)
                            ->send(new WellnessRequestReceived($wellnessRequestForEmail));

                        Log::info('Wellness request email sent successfully', [
                            'wellness_request_id' => $wellnessRequestForEmail->id,
                            'email_requester' => $requesterEmail,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Error sending wellness request email', [
                            'wellness_request_id' => $wellnessRequestForEmail->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                })->afterResponse();
            }

            return $response;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error creating wellness request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->except(['detalles']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => config('app.debug') ? $e->getMessage() : 'Ocurrió un error al procesar la solicitud',
            ], 500);
        }
    }

    /**
     * Display the specified wellness request.
     */
    public function show(WellnessRequest $wellnessRequest): JsonResponse
    {
        $wellnessRequest->load(['requester', 'details', 'activityRealized.evidences']);

        return response()->json([
            'success' => true,
            'data' => $this->formatWellnessRequestResponse($wellnessRequest),
        ], 200);
    }

    /**
     * Update the specified wellness request.
     * Only allows updates if the request is not in a final state (resolved or rejected).
     */
    public function update(UpdateWellnessRequestRequest $request, WellnessRequest $wellnessRequest): JsonResponse
    {
        // Check if request is in a final state
        if (in_array($wellnessRequest->status, ['resolved', 'rejected'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede editar una solicitud que ya tiene un estado final',
                'error' => "La solicitud está en estado '{$wellnessRequest->status}' y no puede ser modificada",
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Capture original values before update for change detection
            $originalValues = [
                'activity_name' => $wellnessRequest->activity_name,
                'activity_description' => $wellnessRequest->activity_description,
                'cost_center' => $wellnessRequest->cost_center,
                'locations' => $wellnessRequest->locations,
                'proposed_date' => $wellnessRequest->proposed_date ? $wellnessRequest->proposed_date->format('Y-m-d') : null,
                'start_time' => $wellnessRequest->start_time,
                'end_time' => $wellnessRequest->end_time,
                'participant_count' => $wellnessRequest->participant_count,
                'requires_details' => $wellnessRequest->requires_details,
                'status' => $wellnessRequest->status,
            ];

            // Capture original details
            $originalDetails = $wellnessRequest->details->map(function ($detail) {
                return [
                    'type' => $detail->type,
                    'quantity' => $detail->quantity,
                ];
            })->toArray();

            // Get transformed data (only fields that are provided)
            $updateData = $request->getTransformedData();

            // Check if requires_details will change
            $willUpdateDetails = $request->has('detalles') || isset($updateData['requires_details']);
            $newRequiresDetails = $updateData['requires_details'] ?? $wellnessRequest->requires_details;

            // Handle details update if requires_details is provided or if details are provided
            $newDetails = null;
            if ($willUpdateDetails) {
                // Get new details before deleting
                $transformedDetails = $request->getTransformedDetails();
                $newDetails = $transformedDetails;

                // Delete existing details
                $wellnessRequest->details()->delete();

                // Add new details only if requires_details is true
                if ($newRequiresDetails && !empty($transformedDetails)) {
                    $details = [];
                    foreach ($transformedDetails as $detail) {
                        $details[] = [
                            'wellness_request_id' => $wellnessRequest->id,
                            'type' => $detail['type'],
                            'quantity' => $detail['quantity'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    $wellnessRequest->details()->insert($details);
                }
            }

            // Update the wellness request
            $wellnessRequest->update($updateData);

            // Reload relationships
            $wellnessRequest->refresh();
            $wellnessRequest->load('requester', 'details');

            // Detect changes
            $changes = [];
            foreach ($updateData as $field => $newValue) {
                $oldValue = $originalValues[$field] ?? null;

                // Normalize values for comparison
                $oldValueNormalized = $this->normalizeValueForComparison($oldValue, $field);
                $newValueNormalized = $this->normalizeValueForComparison($newValue, $field);

                // Compare values
                if ($oldValueNormalized !== $newValueNormalized) {
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }

            // Check if details changed
            $oldDetailsForEmail = null;
            $newDetailsForEmail = null;
            if ($willUpdateDetails) {
                // Normalize details arrays for comparison
                $originalDetailsNormalized = $this->normalizeDetailsArray($originalDetails);
                $newDetailsNormalized = $this->normalizeDetailsArray($newDetails ?? []);

                if ($originalDetailsNormalized !== $newDetailsNormalized) {
                    $oldDetailsForEmail = $originalDetails;
                    $newDetailsForEmail = $newDetails ?? [];
                }
            }

            DB::commit();

            // Send email notification if there are changes
            if (!empty($changes) || null !== $oldDetailsForEmail) {
                try {
                    $requester = User::find($wellnessRequest->requester_id);
                    $requesterEmail = $requester ? $requester->email : null;

                    // Check if only status changed (no other fields and no details changed)
                    $statusOnly = 1 === count($changes)
                        && isset($changes['status'])
                        && null === $oldDetailsForEmail;

                    // Send email to requester
                    if ($requesterEmail) {
                        Mail::to($requesterEmail)
                            ->send(new WellnessRequestUpdated(
                                $wellnessRequest,
                                $changes,
                                $oldDetailsForEmail,
                                $newDetailsForEmail,
                                $statusOnly
                            ));

                        Log::info('Wellness request update email sent successfully', [
                            'wellness_request_id' => $wellnessRequest->id,
                            'email_requester' => $requesterEmail,
                            'changes_count' => count($changes),
                            'details_changed' => null !== $oldDetailsForEmail,
                            'status_only' => $statusOnly,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('Error sending wellness request update email', [
                        'wellness_request_id' => $wellnessRequest->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Don't fail transaction if email fails
                }
            }

            Log::info('Wellness request updated successfully', [
                'wellness_request_id' => $wellnessRequest->id,
                'updated_fields' => array_keys($updateData),
                'status' => $wellnessRequest->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud de bienestar actualizada exitosamente',
                'data' => $this->formatWellnessRequestResponse($wellnessRequest),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error updating wellness request', [
                'wellness_request_id' => $wellnessRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->except(['detalles']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => config('app.debug') ? $e->getMessage() : 'Ocurrió un error al procesar la solicitud',
            ], 500);
        }
    }

    /**
     * Normalize value for comparison.
     */
    private function normalizeValueForComparison($value, string $field): string
    {
        if (null === $value) {
            return '';
        }

        switch ($field) {
            case 'proposed_date':
                // Normalize dates to Y-m-d format
                if ($value instanceof \Carbon\Carbon) {
                    return $value->format('Y-m-d');
                }
                if (is_string($value)) {
                    try {
                        return \Carbon\Carbon::parse($value)->format('Y-m-d');
                    } catch (\Exception $e) {
                        return (string) $value;
                    }
                }

                return (string) $value;

            case 'locations':
                // Normalize arrays to sorted JSON string
                if (is_array($value)) {
                    sort($value);

                    return json_encode($value);
                }

                return (string) $value;

            case 'requires_details':
            case 'participant_count':
                // Convert boolean/integer to string
                return (string) $value;

            default:
                return (string) $value;
        }
    }

    /**
     * Normalize details array for comparison.
     */
    private function normalizeDetailsArray(?array $details): string
    {
        if (empty($details)) {
            return '';
        }

        // Sort by type and quantity, then convert to JSON
        $sorted = $details;
        usort($sorted, function ($a, $b) {
            $typeA = $a['type'] ?? '';
            $typeB = $b['type'] ?? '';
            if ($typeA !== $typeB) {
                return strcmp($typeA, $typeB);
            }

            return ($a['quantity'] ?? 0) <=> ($b['quantity'] ?? 0);
        });

        return json_encode($sorted);
    }

    /**
     * Format wellness request for response with Spanish keys.
     */
    private function formatWellnessRequestResponse(WellnessRequest $wellnessRequest): array
    {
        return [
            'id' => $wellnessRequest->id,
            'nombreActividad' => $wellnessRequest->activity_name,
            'descripcionActividad' => $wellnessRequest->activity_description,
            'centroCostos' => $wellnessRequest->cost_center,
            'sedes' => $wellnessRequest->locations ?? [],
            'fechaPropuesta' => $wellnessRequest->proposed_date->format('Y-m-d'),
            'horaInicio' => $wellnessRequest->start_time ? (strlen($wellnessRequest->start_time) >= 5 ? substr($wellnessRequest->start_time, 0, 5) : $wellnessRequest->start_time) : null,
            'horaFin' => $wellnessRequest->end_time ? (strlen($wellnessRequest->end_time) >= 5 ? substr($wellnessRequest->end_time, 0, 5) : $wellnessRequest->end_time) : null,
            'numeroParticipantes' => $wellnessRequest->participant_count,
            'requiereDetalles' => $wellnessRequest->requires_details,
            'detalles' => $wellnessRequest->details->map(function ($detail) {
                return [
                    'tipo' => $detail->type,
                    'cantidad' => $detail->quantity,
                ];
            })->toArray(),
            'solicitanteId' => (string) $wellnessRequest->requester_id,
            'solicitante' => $wellnessRequest->requester ? [
                'id' => $wellnessRequest->requester->id,
                'name' => $wellnessRequest->requester->name,
                'email' => $wellnessRequest->requester->email,
            ] : null,
            'estado' => $wellnessRequest->status,
            'created_at' => $wellnessRequest->created_at->toIso8601String(),
            'updated_at' => $wellnessRequest->updated_at->toIso8601String(),
            'actividad_realizada' => $wellnessRequest->activityRealized ? $this->formatActivityRealizedForRequest($wellnessRequest->activityRealized) : null,
        ];
    }

    /**
     * Format activity realized for wellness request response.
     */
    private function formatActivityRealizedForRequest(\App\Models\WellnessActivityRealized $activityRealized): array
    {
        $response = [
            'id' => $activityRealized->id,
            'wellness_request_id' => $activityRealized->wellness_request_id,
            'fecha_realizada' => $activityRealized->realized_date->format('Y-m-d'),
            'ubicacion_real' => $activityRealized->real_location,
            'numero_asistentes_real' => $activityRealized->real_attendees_count,
            'descripcion_realizada' => $activityRealized->realized_description,
            'obsequio_entregado' => $activityRealized->gift_delivered,
            'evidencias' => $this->formatEvidenciasForRequest($activityRealized),
            'publicado_en_galeria' => $activityRealized->published_to_gallery,
            'evento_galeria_id' => $activityRealized->gallery_event_id,
            'created_at' => $activityRealized->created_at->toIso8601String(),
            'updated_at' => $activityRealized->updated_at->toIso8601String(),
        ];

        // Add listado_asistencia if exists
        if ($activityRealized->listado_asistencia_path) {
            $fileUrl = null;
            $urlExpiresAt = null;

            try {
                // Generate temporary signed URL for private bucket file (valid for 1 hour)
                $storage = Storage::disk('prosalud-private');
                $fileUrl = $storage->temporaryUrl($activityRealized->listado_asistencia_path, now()->addHours(1));
                $urlExpiresAt = now()->addHours(1)->toIso8601String();
            } catch (\Exception $e) {
                Log::warning('Failed to generate temporary URL for listado_asistencia', [
                    'activity_realized_id' => $activityRealized->id,
                    'path' => $activityRealized->listado_asistencia_path,
                    'error' => $e->getMessage(),
                ]);
                // If temporary URL generation fails, try fallback disk
                try {
                    $storage = Storage::disk('local');
                    if (method_exists($storage, 'temporaryUrl')) {
                        $fileUrl = $storage->temporaryUrl($activityRealized->listado_asistencia_path, now()->addHours(1));
                        $urlExpiresAt = now()->addHours(1)->toIso8601String();
                    }
                } catch (\Exception $fallbackError) {
                    Log::error('Failed to generate temporary URL from fallback disk', [
                        'error' => $fallbackError->getMessage(),
                    ]);
                }
            }

            $response['listado_asistencia'] = [
                'id' => $activityRealized->id,
                'file_url' => $fileUrl,
                'url_expires_at' => $urlExpiresAt,
            ];
        }

        return $response;
    }

    /**
     * Format evidencias with main image information for wellness request.
     */
    private function formatEvidenciasForRequest(\App\Models\WellnessActivityRealized $activityRealized): array
    {
        // Get main image URL from gallery event if published
        $mainImageUrl = null;
        if ($activityRealized->published_to_gallery && $activityRealized->gallery_event_id) {
            $mainImage = \App\Models\WellnessEventImage::where('event_id', $activityRealized->gallery_event_id)
                ->where('is_main', true)
                ->first();
            if ($mainImage) {
                $mainImageUrl = $mainImage->image_url;
            }
        }

        return $activityRealized->evidences->map(function ($evidence) use ($mainImageUrl) {
            return [
                'id' => $evidence->id,
                'image_url' => $evidence->image_url,
                'is_selected_for_gallery' => $evidence->is_selected_for_gallery,
                'order' => $evidence->order,
                'is_main' => $mainImageUrl && $evidence->image_url === $mainImageUrl,
            ];
        })->sortBy('order')->values()->toArray();
    }

    /**
     * Export wellness requests to Excel file.
     */
    public function exportExcel(ExportWellnessExcelRequest $request): BinaryFileResponse|JsonResponse
    {
        try {
            $user = $request->user();

            // Preparar filtros
            $filters = [
                'cost_center' => $request->input('cost_center'),
                'requester_id' => $request->input('requester_id'),
                'status' => $request->input('status'),
                'fecha_desde' => $request->input('fecha_desde'),
                'fecha_hasta' => $request->input('fecha_hasta'),
            ];

            // Preparar opciones
            $options = [
                'include_images' => $request->boolean('include_images', false),
            ];

            // Generar reporte
            $filePath = $this->excelExportService->generateReport($filters, $options);

            if (!file_exists($filePath)) {
                Log::error('Error generando reporte Excel de bienestar: archivo no creado', [
                    'user_id' => $user->id,
                    'filters' => $filters,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            // Nombre del archivo con fecha y hora de generación
            $fileName = 'Reporte_Bienestar_ProSalud_' . now()->setTimezone('America/Bogota')->format('Y-m-d_His') . '.xlsx';

            Log::info('Reporte Excel de bienestar generado', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'filters' => $filters,
                'file_name' => $fileName,
            ]);

            // Registrar en auditoría
            $this->auditLogService->logBusinessProcess('wellness_request', 'excel_export', [
                'user_id' => $user->id,
                'filters' => $filters,
                'file_name' => $fileName,
            ]);

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Error de validación al generar reporte Excel de bienestar', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error generando reporte Excel de bienestar', [
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
}
