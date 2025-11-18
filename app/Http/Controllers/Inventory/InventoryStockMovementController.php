<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryStockMovementResource;
use App\Models\InventoryStockMovement;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;

class InventoryStockMovementController extends Controller
{
    /**
     * List stock movements with filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryStockMovement::query()
                ->with(['variant.product', 'variant.color', 'fromLocation', 'toLocation', 'reference'])
                ->orderByDesc('moved_at');

            if ($request->filled('variantId')) {
                $query->where('variant_id', $request->input('variantId'));
            }

            if ($request->filled('reason')) {
                $query->where('reason', $request->input('reason'));
            }

            if ($request->filled('fromLocationId')) {
                $query->where('from_location_id', $request->input('fromLocationId'));
            }

            if ($request->filled('toLocationId')) {
                $query->where('to_location_id', $request->input('toLocationId'));
            }

            if ($request->filled('dateFrom')) {
                $query->whereDate('moved_at', '>=', $request->input('dateFrom'));
            }

            if ($request->filled('dateTo')) {
                $query->whereDate('moved_at', '<=', $request->input('dateTo'));
            }

            $page = (int) $request->input('page', 1);
            $pageSize = min(200, max(1, (int) $request->input('pageSize', 25)));

            $movements = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => InventoryStockMovementResource::collection($movements->items()),
                'pagination' => [
                    'total' => $movements->total(),
                    'per_page' => $movements->perPage(),
                    'current_page' => $movements->currentPage(),
                    'last_page' => $movements->lastPage(),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Error fetching inventory stock movements', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el historial de movimientos',
            ], 500);
        }
    }
}
