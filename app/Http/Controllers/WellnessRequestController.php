<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWellnessRequestRequest;
use App\Http\Requests\UpdateWellnessRequestRequest;
use App\Mail\WellnessRequestReceived;
use App\Mail\WellnessRequestUpdated;
use App\Models\User;
use App\Models\WellnessRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WellnessRequestController extends Controller
{
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
            'filters' => $request->only(['estado', 'centroCostos', 'solicitanteId', 'fechaDesde', 'fechaHasta', 'busqueda'])
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
            ]
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

            // Send email notification
            try {
                $requester = User::find($request->input('solicitanteId'));
                $requesterEmail = $requester ? $requester->email : null;

                // Send email to Human Resources with copy to requester
                Mail::to('juanpapabon@gmail.com')
                    ->when($requesterEmail, function ($mail) use ($requesterEmail) {
                        return $mail->cc($requesterEmail);
                    })
                    ->send(new WellnessRequestReceived($wellnessRequest));

                Log::info('Wellness request email sent successfully', [
                    'wellness_request_id' => $wellnessRequest->id,
                    'email_human_resources' => 'juanpapabon@gmail.com',
                    'email_requester' => $requesterEmail,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error sending wellness request email', [
                    'wellness_request_id' => $wellnessRequest->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Don't fail transaction if email fails
            }

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

            return response()->json([
                'success' => true,
                'message' => 'Solicitud de bienestar creada exitosamente',
                'data' => $this->formatWellnessRequestResponse($wellnessRequest),
            ], 200);

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
            if (!empty($changes) || $oldDetailsForEmail !== null) {
                try {
                    $requester = User::find($wellnessRequest->requester_id);
                    $requesterEmail = $requester ? $requester->email : null;

                    // Check if only status changed (no other fields and no details changed)
                    $statusOnly = count($changes) === 1 
                        && isset($changes['status']) 
                        && $oldDetailsForEmail === null;

                    // Send email to Human Resources with copy to requester
                    Mail::to('juanpapabon@gmail.com')
                        ->when($requesterEmail, function ($mail) use ($requesterEmail) {
                            return $mail->cc($requesterEmail);
                        })
                        ->send(new WellnessRequestUpdated(
                            $wellnessRequest,
                            $changes,
                            $oldDetailsForEmail,
                            $newDetailsForEmail,
                            $statusOnly
                        ));

                    Log::info('Wellness request update email sent successfully', [
                        'wellness_request_id' => $wellnessRequest->id,
                        'email_human_resources' => 'juanpapabon@gmail.com',
                        'email_requester' => $requesterEmail,
                        'changes_count' => count($changes),
                        'details_changed' => $oldDetailsForEmail !== null,
                        'status_only' => $statusOnly,
                    ]);
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
     * Normalize value for comparison
     */
    private function normalizeValueForComparison($value, string $field): string
    {
        if ($value === null) {
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
     * Normalize details array for comparison
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
     * Format wellness request for response with Spanish keys
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
        ];
    }
}
