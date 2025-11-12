<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryLocationResource;
use App\Models\InventoryLocation;
use App\Services\InventoryStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InventoryLocationController extends Controller
{
    public function __construct(private readonly InventoryStockService $stockService)
    {
    }

    /**
     * List locations with optional summary or detailed stock info.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryLocation::query()
                ->withSum('stocks as total_stock', 'stock')
                ->withSum('stocks as total_reserved', 'reserved')
                ->withCount('stocks as total_variants');

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            if ($request->filled('isPrimary')) {
                $query->where('is_primary', filter_var($request->input('isPrimary'), FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->boolean('withHospital', false)) {
                $query->with('hospital');
            }

            $summaryOnly = $request->boolean('summary', false);

            if (!$summaryOnly) {
                $query->with(['stocks.variant.product', 'stocks.variant.color']);
            }

            $locations = $query->orderByDesc('is_primary')->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => InventoryLocationResource::collection($locations),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Error fetching inventory locations', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las ubicaciones de inventario',
            ], 500);
        }
    }

    /**
     * Show detailed info for a specific location.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        try {
            $location = InventoryLocation::query()
                ->with(['hospital', 'stocks.variant.product', 'stocks.variant.color'])
                ->findOrFail($id);

            $location->loadSum('stocks as total_stock', 'stock');
            $location->loadSum('stocks as total_reserved', 'reserved');
            $location->loadCount('stocks as total_variants');

            return response()->json([
                'success' => true,
                'data' => new InventoryLocationResource($location),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Ubicación no encontrada',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Error fetching inventory location', [
                'location_id' => $id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la ubicación',
            ], 500);
        }
    }
}
