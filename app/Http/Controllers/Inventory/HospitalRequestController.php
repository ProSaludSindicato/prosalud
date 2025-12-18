<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\{StoreHospitalRequestRequest, UpdateHospitalRequestStatusRequest};
use App\Http\Resources\HospitalRequestResource;
use App\Mail\HospitalRequestStatusUpdated;
use App\Models\{Hospital, HospitalRequest, InventoryLocation, InventoryVariant, User};
use App\Services\InventoryStockService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log, Mail};
use Illuminate\Support\Str;

class HospitalRequestController extends Controller
{
    public function __construct(private readonly InventoryStockService $stockService)
    {
    }

    /**
     * Get all hospital requests with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = HospitalRequest::with(['items.product', 'items.variant.color', 'hospital', 'targetLocation']);

            // Hospital filter
            if ($request->has('hospitalId') && $request->hospitalId) {
                $query->where('hospital_id', $request->hospitalId);
            }

            // Status filter
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('hospital_name', 'like', "%{$search}%")
                        ->orWhere('requested_by', 'like', "%{$search}%")
                        ->orWhere('observations', 'like', "%{$search}%");
                });
            }

            // Date range filter
            if ($request->has('dateFrom') && $request->dateFrom) {
                $query->where('created_at', '>=', $request->dateFrom);
            }

            if ($request->has('dateTo') && $request->dateTo) {
                $query->where('created_at', '<=', $request->dateTo);
            }

            // Check if summary is requested
            if ($request->has('summary') && 'true' === $request->summary) {
                $summary = [
                    'pending' => HospitalRequest::where('status', 'pending')->count(),
                    'approved' => HospitalRequest::where('status', 'approved')->count(),
                    'preparing' => HospitalRequest::where('status', 'preparing')->count(),
                    'delivered' => HospitalRequest::where('status', 'delivered')->count(),
                    'rejected' => HospitalRequest::where('status', 'rejected')->count(),
                ];

                return response()->json([
                    'success' => true,
                    'data' => $summary,
                ]);
            }

            // Sorting
            $sortBy = $request->input('sortBy', 'created_at');
            $sortOrder = $request->input('sortOrder', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $page = $request->input('page', 1);
            $pageSize = $request->input('pageSize', 10);

            $hospitalRequests = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => HospitalRequestResource::collection($hospitalRequests->items()),
                'pagination' => [
                    'total' => $hospitalRequests->total(),
                    'per_page' => $hospitalRequests->perPage(),
                    'current_page' => $hospitalRequests->currentPage(),
                    'last_page' => $hospitalRequests->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching hospital requests', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las solicitudes',
            ], 500);
        }
    }

    /**
     * Store a new hospital request.
     */
    public function store(StoreHospitalRequestRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $hospital = Hospital::query()->firstOrCreate(
                ['name' => $request->hospital_name ?? Str::headline($request->hospital_id)],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'hospital',
                ]
            );

            $location = $hospital->locations()->first();

            if (!$location) {
                $location = InventoryLocation::query()->create([
                    'id' => (string) Str::uuid(),
                    'hospital_id' => $hospital->id,
                    'name' => $hospital->name,
                    'type' => 'warehouse' === $hospital->type ? 'warehouse' : 'hospital',
                    'is_primary' => false,
                ]);
            }

            $hospitalRequest = HospitalRequest::create([
                'hospital_id' => $request->hospital_id,
                'hospital_name' => $request->hospital_name,
                'hospital_uuid' => $hospital->id,
                'target_location_id' => $location->id,
                'requested_by' => $request->requested_by,
                'observations' => $request->observations,
                'status' => 'pending',
            ]);

            // Create items
            foreach ($request->items as $itemData) {
                $variant = null;
                $variantLabel = null;
                $size = null;
                $colorId = null;

                if (isset($itemData['variant_id'])) {
                    $variant = InventoryVariant::find($itemData['variant_id']);
                    if ($variant) {
                        $variantLabel = $variant->label;
                        $size = $variant->size;
                        $colorId = $variant->color_id;
                    }
                }

                $hospitalRequest->items()->create([
                    'product_id' => $itemData['product_id'],
                    'variant_id' => $itemData['variant_id'] ?? null,
                    'variant_label' => $variantLabel,
                    'size' => $size,
                    'color_id' => $colorId,
                    'quantity' => $itemData['quantity'],
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            // Create initial timeline entry
            $hospitalRequest->timeline()->create([
                'status' => 'pending',
                'timestamp' => now(),
                'description' => 'Solicitud creada',
                'actor' => $request->requested_by,
            ]);

            DB::commit();

            $hospitalRequest->load(['items.product', 'items.variant.color', 'timeline', 'hospital', 'targetLocation']);

            Log::info('Hospital request created', [
                'request_id' => $hospitalRequest->id,
                'hospital_id' => $hospitalRequest->hospital_id,
                'hospital_name' => $hospitalRequest->hospital_name,
                'items_count' => count($request->items),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud creada exitosamente',
                'data' => new HospitalRequestResource($hospitalRequest),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error creating hospital request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la solicitud',
            ], 500);
        }
    }

    /**
     * Get a single hospital request.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $hospitalRequest = HospitalRequest::with([
                'items.product.category',
                'items.product.subcategory',
                'items.variant.color',
                'timeline',
                'hospital',
                'targetLocation',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new HospitalRequestResource($hospitalRequest),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching hospital request', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la solicitud',
            ], 500);
        }
    }

    /**
     * Update hospital request status.
     */
    public function updateStatus(UpdateHospitalRequestStatusRequest $request, string $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $hospitalRequest = HospitalRequest::with(['items.product', 'hospital', 'targetLocation'])->findOrFail($id);
            $newStatus = $request->status;
            $previousStatus = $hospitalRequest->status;

            $reserveStatuses = ['preparing'];
            $wasReserved = in_array($previousStatus, $reserveStatuses, true);
            $isReserved = in_array($newStatus, $reserveStatuses, true);

            // Ensure hospital location exists before attempting stock operations
            $primaryLocation = $this->stockService->getPrimaryLocation();
            $hospitalLocation = $hospitalRequest->target_location_id
                ? InventoryLocation::query()->find($hospitalRequest->target_location_id)
                : null;

            if (!$hospitalLocation) {
                $hospitalIdentifier = $hospitalRequest->hospital_uuid ?? $hospitalRequest->hospital_name ?? $hospitalRequest->hospital_id;
                $hospitalLocation = $this->stockService->ensureHospitalLocation($hospitalIdentifier);
                $hospitalRequest->update([
                    'target_location_id' => $hospitalLocation->id,
                    'hospital_uuid' => $hospitalLocation->hospital_id,
                ]);
            }

            if ($isReserved && !$wasReserved) {
                foreach ($hospitalRequest->items as $item) {
                    if (!$item->variant_id) {
                        continue;
                    }

                    $variant = InventoryVariant::find($item->variant_id);
                    if (!$variant) {
                        continue;
                    }

                    $primaryStock = $this->stockService->findOrCreateStock($variant, $primaryLocation);
                    $available = $primaryStock->stock - $primaryStock->reserved;

                    if ($available < $item->quantity) {
                        DB::rollBack();

                        Log::warning('Reserva de stock insuficiente', [
                            'request_id' => $hospitalRequest->id,
                            'variant_id' => $variant->id,
                            'variant_label' => $variant->label,
                            'location' => $primaryLocation->name,
                            'requested_quantity' => $item->quantity,
                            'stock' => $primaryStock->stock,
                            'reserved' => $primaryStock->reserved,
                            'available' => $available,
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => "Stock insuficiente en bodega principal para {$item->product->name} - {$variant->label}",
                        ], 409);
                    }

                    $this->stockService->adjustReserved($variant, $primaryLocation, $item->quantity);

                    if ($hospitalLocation && $hospitalLocation->id !== $primaryLocation->id) {
                        $this->stockService->adjustReserved($variant, $hospitalLocation, $item->quantity);
                    }
                }
            }

            if (!$isReserved && $wasReserved) {
                foreach ($hospitalRequest->items as $item) {
                    if (!$item->variant_id) {
                        continue;
                    }

                    $variant = InventoryVariant::find($item->variant_id);
                    if (!$variant) {
                        continue;
                    }

                    $this->stockService->adjustReserved($variant, $primaryLocation, -$item->quantity);

                    if ($hospitalLocation && $hospitalLocation->id !== $primaryLocation->id) {
                        $this->stockService->adjustReserved($variant, $hospitalLocation, -$item->quantity);
                    }
                }
            }

            if ('delivered' === $newStatus && 'delivered' !== $previousStatus) {
                $primaryLocation = $this->stockService->getPrimaryLocation();
                $shouldTransfer = true;

                if ($hospitalLocation->id === $primaryLocation->id) {
                    $shouldTransfer = false;
                }

                if ($shouldTransfer) {
                    foreach ($hospitalRequest->items as $item) {
                        if ($item->variant_id) {
                            $variant = InventoryVariant::find($item->variant_id);

                            if ($variant) {
                                try {
                                    $this->stockService->transferStock(
                                        variant: $variant,
                                        from: $primaryLocation,
                                        to: $hospitalLocation,
                                        quantity: $item->quantity,
                                        reason: 'hospital_request',
                                        referenceType: HospitalRequest::class,
                                        referenceId: $hospitalRequest->id,
                                        notes: "Traslado por solicitud hospitalaria #{$hospitalRequest->id}"
                                    );
                                } catch (\RuntimeException $movementException) {
                                    $primaryStock = $this->stockService->findOrCreateStock($variant, $primaryLocation);
                                    $hospitalStock = $this->stockService->findOrCreateStock($variant, $hospitalLocation);

                                    DB::rollBack();

                                    Log::warning('Transferencia de stock fallida', [
                                        'request_id' => $hospitalRequest->id,
                                        'variant_id' => $variant->id,
                                        'variant_label' => $variant->label,
                                        'from_location' => $primaryLocation->name,
                                        'to_location' => $hospitalLocation->name,
                                        'quantity' => $item->quantity,
                                        'from_stock' => $primaryStock->stock,
                                        'from_reserved' => $primaryStock->reserved,
                                        'from_available' => $primaryStock->stock - $primaryStock->reserved,
                                        'to_stock' => $hospitalStock->stock,
                                        'to_reserved' => $hospitalStock->reserved,
                                        'message' => $movementException->getMessage(),
                                    ]);

                                    return response()->json([
                                        'success' => false,
                                        'message' => $movementException->getMessage(),
                                    ], 409);
                                }
                            }
                        }
                    }
                }
            }

            // Update status and create timeline entry
            $success = $hospitalRequest->updateStatus(
                $newStatus,
                $request->actor,
                $request->description
            );

            if (!$success) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Transición de estado no válida',
                ], 400);
            }

            DB::commit();

            $hospitalRequest->load(['items.product', 'items.variant.color', 'timeline', 'hospital', 'targetLocation']);

            $this->notifyStatusChange($hospitalRequest, $previousStatus, $newStatus);

            Log::info('Hospital request status updated', [
                'request_id' => $hospitalRequest->id,
                'old_status' => $previousStatus,
                'new_status' => $newStatus,
                'actor' => $request->actor,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado exitosamente',
                'data' => new HospitalRequestResource($hospitalRequest),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error updating hospital request status', [
                'request_id' => $id,
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el estado',
            ], 500);
        }
    }

    /**
     * Delete a hospital request (only if pending).
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $hospitalRequest = HospitalRequest::findOrFail($id);

            if ('pending' !== $hospitalRequest->status) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden eliminar solicitudes pendientes',
                ], 409);
            }

            $hospitalRequest->delete();

            Log::info('Hospital request deleted', [
                'request_id' => $id,
                'hospital_name' => $hospitalRequest->hospital_name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud eliminada exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting hospital request', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la solicitud',
            ], 500);
        }
    }

    private function notifyStatusChange(HospitalRequest $hospitalRequest, string $previousStatus, string $newStatus): void
    {
        try {
            $requesterEmail = $this->resolveRequesterEmail($hospitalRequest->requested_by);

            $mail = Mail::to($requesterEmail);

            $mail->send(new HospitalRequestStatusUpdated(
                $hospitalRequest,
                $previousStatus,
                $newStatus
            ));

            Log::info('Hospital request status email sent', [
                'request_id' => $hospitalRequest->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'requester_email' => $requesterEmail,
                'fallback_only' => null === $requesterEmail,
            ]);

            if (!$requesterEmail) {
                Log::warning('Hospital request requester email not provided, email sent only to fallback recipient', [
                    'request_id' => $hospitalRequest->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Error sending hospital request status email', [
                'request_id' => $hospitalRequest->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveRequesterEmail(?string $requestedBy): ?string
    {
        if (!$requestedBy) {
            return null;
        }

        $requestedBy = trim($requestedBy);

        if (filter_var($requestedBy, FILTER_VALIDATE_EMAIL)) {
            return $requestedBy;
        }

        $user = null;

        if (is_numeric($requestedBy)) {
            $user = User::find((int) $requestedBy);
        }

        if (!$user) {
            $user = User::where('email', $requestedBy)->first();
        }

        if (!$user) {
            $user = User::whereRaw('LOWER(name) = ?', [strtolower($requestedBy)])->first();
        }

        return $user?->email;
    }
}
