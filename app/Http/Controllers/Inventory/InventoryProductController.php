<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\{StoreProductRequest, UpdateProductRequest};
use App\Http\Resources\InventoryProductResource;
use App\Models\{InventoryProduct, InventoryVariant};
use App\Services\{InventoryStockService, SstDotacionService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log};

class InventoryProductController extends Controller
{
    public function __construct(private readonly InventoryStockService $stockService)
    {
    }

    /**
     * Get all products with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryProduct::with(['category', 'subcategory', 'variants.color']);

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Category filter
            if ($request->has('category') && $request->category) {
                $query->where('category_id', $request->category);
            }

            // Low stock filter
            if ($request->has('lowStock') && 'true' === $request->lowStock) {
                $query->whereHas('variants', function ($q) {
                    $q->whereColumn('stock', '<=', 'min_stock');
                });
            }

            // Sorting
            $sortBy = $request->input('sortBy', 'created_at');
            $sortOrder = $request->input('sortOrder', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $page = $request->input('page', 1);
            $pageSize = $request->input('pageSize', 10);

            $products = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => InventoryProductResource::collection($products->items()),
                'pagination' => [
                    'total' => $products->total(),
                    'per_page' => $products->perPage(),
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching products', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los productos',
            ], 500);
        }
    }

    /**
     * Store a new product with variants.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $product = InventoryProduct::create([
                'name' => $request->name,
                'category_id' => $request->category_id,
                'subcategory_id' => $request->subcategory_id,
                'description' => $request->description,
                'variant_mode' => $request->variant_mode,
                'gender' => $request->gender,
            ]);

            // Create variants
            foreach ($request->variants as $variantData) {
                $newVariant = $product->variants()->create([
                    'size' => $variantData['size'] ?? null,
                    'color_id' => $variantData['color_id'] ?? null,
                    'stock' => $variantData['stock'],
                    'min_stock' => $variantData['min_stock'],
                    'max_stock' => $variantData['max_stock'] ?? null,
                    'sku' => $variantData['sku'],
                ]);

                try {
                    $this->stockService->setVariantTotalStock($newVariant, $variantData['stock']);
                } catch (\RuntimeException $e) {
                    DB::rollBack();

                    Log::warning('Error al sincronizar stock inicial de variante', [
                        'product_id' => $product->id,
                        'variant_id' => $newVariant->id,
                        'error' => $e->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                    ], 409);
                }
            }

            DB::commit();

            // Clear inventory cache for dotación/EPP
            SstDotacionService::clearInventoryCache();

            $product->load(['category', 'subcategory', 'variants.color']);

            Log::info('Product created', [
                'product_id' => $product->id,
                'name' => $product->name,
                'variants_count' => count($request->variants),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Producto creado exitosamente',
                'data' => new InventoryProductResource($product),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error creating product', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el producto',
            ], 500);
        }
    }

    /**
     * Get a single product.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $product = InventoryProduct::with(['category', 'subcategory', 'variants.color'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new InventoryProductResource($product),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching product', [
                'product_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el producto',
            ], 500);
        }
    }

    /**
     * Update a product and its variants.
     */
    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $product = InventoryProduct::findOrFail($id);

            // Update product basic info
            $product->update($request->only([
                'name',
                'category_id',
                'subcategory_id',
                'description',
                'gender',
                'variant_mode',
            ]));

            // Update variants if provided
            if ($request->has('variants')) {
                $existingVariantIds = [];

                foreach ($request->variants as $variantData) {
                    if (isset($variantData['id'])) {
                        // Update existing variant
                        $variant = InventoryVariant::find($variantData['id']);

                        if ($variant && $variant->product_id === $product->id) {
                            // Check if marked for deletion
                            if (isset($variantData['deleted']) && true === $variantData['deleted']) {
                                $variant->delete();
                                continue;
                            }

                            $variant->update([
                                'size' => $variantData['size'] ?? null,
                                'color_id' => $variantData['color_id'] ?? null,
                                'stock' => $variantData['stock'],
                                'min_stock' => $variantData['min_stock'],
                                'max_stock' => $variantData['max_stock'] ?? null,
                                'sku' => $variantData['sku'],
                            ]);

                            try {
                                $this->stockService->setVariantTotalStock($variant, $variantData['stock']);
                            } catch (\RuntimeException $e) {
                                DB::rollBack();

                                Log::warning('Error al sincronizar stock de variante en actualización', [
                                    'product_id' => $product->id,
                                    'variant_id' => $variant->id,
                                    'error' => $e->getMessage(),
                                ]);

                                return response()->json([
                                    'success' => false,
                                    'message' => $e->getMessage(),
                                ], 409);
                            }
                            $existingVariantIds[] = $variant->id;
                        }
                    } else {
                        // Create new variant
                        $newVariant = $product->variants()->create([
                            'size' => $variantData['size'] ?? null,
                            'color_id' => $variantData['color_id'] ?? null,
                            'stock' => $variantData['stock'],
                            'min_stock' => $variantData['min_stock'],
                            'max_stock' => $variantData['max_stock'] ?? null,
                            'sku' => $variantData['sku'],
                        ]);

                        try {
                            $this->stockService->setVariantTotalStock($newVariant, $variantData['stock']);
                        } catch (\RuntimeException $e) {
                            DB::rollBack();

                            Log::warning('Error al sincronizar stock de nueva variante en actualización', [
                                'product_id' => $product->id,
                                'variant_id' => $newVariant->id,
                                'error' => $e->getMessage(),
                            ]);

                            return response()->json([
                                'success' => false,
                                'message' => $e->getMessage(),
                            ], 409);
                        }
                        $existingVariantIds[] = $newVariant->id;
                    }
                }
            }

            DB::commit();

            // Clear inventory cache for dotación/EPP
            SstDotacionService::clearInventoryCache();

            $product->load(['category', 'subcategory', 'variants.color']);

            Log::info('Product updated', [
                'product_id' => $product->id,
                'name' => $product->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado exitosamente',
                'data' => new InventoryProductResource($product),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error updating product', [
                'product_id' => $id,
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el producto',
            ], 500);
        }
    }

    /**
     * Delete a product.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $product = InventoryProduct::findOrFail($id);

            // Check if product has hospital requests
            if ($product->hospitalRequestItems()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el producto porque tiene solicitudes asociadas',
                ], 409);
            }

            $productName = $product->name;
            $product->delete(); // Variants will be deleted automatically due to cascade

            // Clear inventory cache for dotación/EPP
            SstDotacionService::clearInventoryCache();

            Log::info('Product deleted', [
                'product_id' => $id,
                'name' => $productName,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Producto eliminado exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting product', [
                'product_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el producto',
            ], 500);
        }
    }
}
