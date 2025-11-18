<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\{StoreCategoryRequest, StoreSubcategoryRequest, UpdateCategoryRequest, UpdateSubcategoryRequest};
use App\Http\Resources\{InventoryCategoryResource, InventorySubcategoryResource};
use App\Models\InventoryCategory;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log};

class InventoryCategoryController extends Controller
{
    /**
     * Get all categories with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryCategory::with('subcategories');

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Pagination
            $page = $request->input('page', 1);
            $pageSize = $request->input('pageSize', 10);

            $categories = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => InventoryCategoryResource::collection($categories->items()),
                'pagination' => [
                    'total' => $categories->total(),
                    'per_page' => $categories->perPage(),
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching categories', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las categorías',
            ], 500);
        }
    }

    /**
     * Store a new category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $category = InventoryCategory::create([
                'name' => $request->name,
                'description' => $request->description,
                'icon' => $request->icon,
            ]);

            // Create subcategories if provided
            if ($request->has('subcategories') && is_array($request->subcategories)) {
                foreach ($request->subcategories as $subcategoryData) {
                    $category->subcategories()->create([
                        'name' => $subcategoryData['name'],
                        'description' => $subcategoryData['description'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $category->load('subcategories');

            Log::info('Category created', [
                'category_id' => $category->id,
                'name' => $category->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Categoría creada exitosamente',
                'data' => new InventoryCategoryResource($category),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error creating category', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la categoría',
            ], 500);
        }
    }

    /**
     * Get a single category.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $category = InventoryCategory::with('subcategories')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new InventoryCategoryResource($category),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching category', [
                'category_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la categoría',
            ], 500);
        }
    }

    /**
     * Update a category.
     */
    public function update(UpdateCategoryRequest $request, string $id): JsonResponse
    {
        try {
            $category = InventoryCategory::findOrFail($id);

            $category->update($request->only(['name', 'description', 'icon']));

            Log::info('Category updated', [
                'category_id' => $category->id,
                'name' => $category->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Categoría actualizada exitosamente',
                'data' => new InventoryCategoryResource($category->load('subcategories')),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating category', [
                'category_id' => $id,
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la categoría',
            ], 500);
        }
    }

    /**
     * Delete a category.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $category = InventoryCategory::findOrFail($id);

            // Check if category has products
            if ($category->products()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar la categoría porque tiene productos asociados',
                ], 409);
            }

            $categoryName = $category->name;
            $category->delete();

            Log::info('Category deleted', [
                'category_id' => $id,
                'name' => $categoryName,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Categoría eliminada exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting category', [
                'category_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la categoría',
            ], 500);
        }
    }

    /**
     * Store a new subcategory.
     */
    public function storeSubcategory(StoreSubcategoryRequest $request, string $categoryId): JsonResponse
    {
        try {
            $category = InventoryCategory::findOrFail($categoryId);

            $subcategory = $category->subcategories()->create([
                'name' => $request->name,
                'description' => $request->description,
            ]);

            Log::info('Subcategory created', [
                'subcategory_id' => $subcategory->id,
                'category_id' => $categoryId,
                'name' => $subcategory->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subcategoría creada exitosamente',
                'data' => new InventorySubcategoryResource($subcategory),
            ], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error creating subcategory', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la subcategoría',
            ], 500);
        }
    }

    /**
     * Update a subcategory.
     */
    public function updateSubcategory(UpdateSubcategoryRequest $request, string $categoryId, string $subcategoryId): JsonResponse
    {
        try {
            $category = InventoryCategory::findOrFail($categoryId);
            $subcategory = $category->subcategories()->findOrFail($subcategoryId);

            $subcategory->update($request->only(['name', 'description']));

            Log::info('Subcategory updated', [
                'subcategory_id' => $subcategory->id,
                'category_id' => $categoryId,
                'name' => $subcategory->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subcategoría actualizada exitosamente',
                'data' => new InventorySubcategoryResource($subcategory),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría o subcategoría no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating subcategory', [
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la subcategoría',
            ], 500);
        }
    }

    /**
     * Delete a subcategory.
     */
    public function destroySubcategory(string $categoryId, string $subcategoryId): JsonResponse
    {
        try {
            $category = InventoryCategory::findOrFail($categoryId);
            $subcategory = $category->subcategories()->findOrFail($subcategoryId);

            // Check if subcategory has products
            if ($subcategory->products()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar la subcategoría porque tiene productos asociados',
                ], 409);
            }

            $subcategoryName = $subcategory->name;
            $subcategory->delete();

            Log::info('Subcategory deleted', [
                'subcategory_id' => $subcategoryId,
                'category_id' => $categoryId,
                'name' => $subcategoryName,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subcategoría eliminada exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría o subcategoría no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting subcategory', [
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la subcategoría',
            ], 500);
        }
    }
}
