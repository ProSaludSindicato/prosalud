<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryEntryRequest;
use App\Http\Resources\InventoryEntryResource;
use App\Models\{InventoryEntry, InventoryLocation, InventoryProduct, InventoryVariant};
use App\Services\InventoryStockService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Support\Str;

class InventoryEntryController extends Controller
{
    public function __construct(private readonly InventoryStockService $stockService)
    {
    }

    /**
     * List inventory entries with pagination and optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryEntry::query()
                ->with(['items.variant.color', 'items.product', 'location'])
                ->orderByDesc('received_at');

            if ($request->filled('supplierId')) {
                $query->where('supplier_id', $request->string('supplierId')->toString());
            }

            if ($request->filled('dateFrom')) {
                $query->whereDate('received_at', '>=', $request->date('dateFrom'));
            }

            if ($request->filled('dateTo')) {
                $query->whereDate('received_at', '<=', $request->date('dateTo'));
            }

            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 10);

            $entries = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => InventoryEntryResource::collection($entries->items()),
                'pagination' => [
                    'total' => $entries->total(),
                    'per_page' => $entries->perPage(),
                    'current_page' => $entries->currentPage(),
                    'last_page' => $entries->lastPage(),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Error fetching inventory entries', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las entradas de inventario',
            ], 500);
        }
    }

    /**
     * Store a new inventory entry and update stock levels accordingly.
     */
    public function store(StoreInventoryEntryRequest $request): JsonResponse
    {
        $payload = $request->validated();

        DB::beginTransaction();

        try {
            $user = $request->user();

            $location = isset($payload['location_id'])
                ? InventoryLocation::query()->findOrFail($payload['location_id'])
                : $this->stockService->getPrimaryLocation();

            $entry = InventoryEntry::query()->create([
                'id' => (string) Str::uuid(),
                'supplier_id' => $payload['supplier_id'],
                'supplier_name' => $payload['supplier_name'] ?? null,
                'received_at' => $payload['received_at'],
                'document_number' => $payload['document_number'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $user?->name ?? 'Sistema',
                'created_by_user_id' => $user?->id,
                'total_items' => 0,
                'total_quantity' => 0,
                'location_id' => $location->id,
            ]);

            $totalQuantity = 0;
            $itemsCreated = 0;

            foreach ($payload['items'] as $itemData) {
                /** @var InventoryProduct $product */
                $product = InventoryProduct::query()
                    ->with(['variants.color'])
                    ->lockForUpdate()
                    ->findOrFail($itemData['product_id']);

                $variant = null;

                if (!empty($itemData['variant_id'])) {
                    $variant = InventoryVariant::query()
                        ->with('color')
                        ->lockForUpdate()
                        ->where('product_id', $product->id)
                        ->findOrFail($itemData['variant_id']);
                } else {
                    $variant = InventoryVariant::query()
                        ->with('color')
                        ->lockForUpdate()
                        ->where('product_id', $product->id)
                        ->first();

                    if (!$variant) {
                        throw new \RuntimeException('No se encontró una variante asociada al producto seleccionado.');
                    }
                }

                $quantity = (int) $itemData['quantity'];

                $locationStock = $this->stockService->findOrCreateStock($variant, $location);
                $previousStock = $locationStock->stock;

                $updatedStock = $this->stockService->adjustStock(
                    variant: $variant,
                    location: $location,
                    quantity: $quantity,
                    reason: 'entry',
                    referenceType: InventoryEntry::class,
                    referenceId: $entry->id
                );
                $newStock = $updatedStock->stock;

                $variantLabel = $variant->label ?? (
                    implode(' · ', array_filter([
                        $variant->size,
                        $variant->color->label ?? $variant->color_id,
                    ])) ?: 'Variante'
                );

                $entry->items()->create([
                    'id' => (string) Str::uuid(),
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'product_name' => $product->name,
                    'variant_label' => $variantLabel,
                    'quantity' => $quantity,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                ]);

                $totalQuantity += $quantity;
                ++$itemsCreated;
            }

            $entry->update([
                'total_items' => $itemsCreated,
                'total_quantity' => $totalQuantity,
            ]);

            DB::commit();

            $entry->load(['items.variant.color', 'items.product', 'location']);

            return response()->json([
                'success' => true,
                'message' => 'Entrada registrada exitosamente',
                'data' => new InventoryEntryResource($entry),
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error('Error creating inventory entry', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'payload' => $payload,
            ]);

            $statusCode = $exception instanceof \RuntimeException ? 422 : 500;

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $statusCode);
        }
    }

    /**
     * Display a specific inventory entry.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $entry = InventoryEntry::query()
                ->with(['items.variant.color', 'items.product', 'location'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new InventoryEntryResource($entry),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Entrada de inventario no encontrada',
            ], 404);
        } catch (\Throwable $exception) {
            Log::error('Error fetching inventory entry', [
                'entry_id' => $id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la entrada de inventario',
            ], 500);
        }
    }
}
