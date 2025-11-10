<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreHospitalRequestRequest;
use App\Http\Requests\Inventory\UpdateHospitalRequestStatusRequest;
use App\Http\Resources\HospitalRequestResource;
use App\Models\HospitalRequest;
use App\Models\InventoryVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HospitalRequestController extends Controller
{
    /**
     * Get all hospital requests with optional filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = HospitalRequest::with(['items.product', 'items.variant.color']);

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
            if ($request->has('summary') && $request->summary === 'true') {
                $summary = [
                    'pending' => HospitalRequest::where('status', 'pending')->count(),
                    'approved' => HospitalRequest::where('status', 'approved')->count(),
                    'preparing' => HospitalRequest::where('status', 'preparing')->count(),
                    'shipped' => HospitalRequest::where('status', 'shipped')->count(),
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
     * Store a new hospital request
     */
    public function store(StoreHospitalRequestRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $hospitalRequest = HospitalRequest::create([
                'hospital_id' => $request->hospital_id,
                'hospital_name' => $request->hospital_name,
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

            $hospitalRequest->load(['items.product', 'items.variant.color', 'timeline']);

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
     * Get a single hospital request
     */
    public function show(string $id): JsonResponse
    {
        try {
            $hospitalRequest = HospitalRequest::with([
                'items.product.category',
                'items.product.subcategory',
                'items.variant.color',
                'timeline',
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
     * Update hospital request status
     */
    public function updateStatus(UpdateHospitalRequestStatusRequest $request, string $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $hospitalRequest = HospitalRequest::with('items')->findOrFail($id);
            $newStatus = $request->status;

            // If status is being changed to 'shipped' or 'delivered', deduct stock
            if (in_array($newStatus, ['shipped', 'delivered']) && !in_array($hospitalRequest->status, ['shipped', 'delivered'])) {
                foreach ($hospitalRequest->items as $item) {
                    if ($item->variant_id) {
                        $variant = InventoryVariant::find($item->variant_id);

                        if ($variant) {
                            if ($variant->stock < $item->quantity) {
                                DB::rollBack();

                                return response()->json([
                                    'success' => false,
                                    'message' => "Stock insuficiente para {$item->product->name} - {$variant->label}",
                                ], 409);
                            }

                            $variant->decrement('stock', $item->quantity);
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

            $hospitalRequest->load(['items.product', 'items.variant.color', 'timeline']);

            Log::info('Hospital request status updated', [
                'request_id' => $hospitalRequest->id,
                'old_status' => $hospitalRequest->getOriginal('status'),
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
     * Delete a hospital request (only if pending)
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $hospitalRequest = HospitalRequest::findOrFail($id);

            if ($hospitalRequest->status !== 'pending') {
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
}
