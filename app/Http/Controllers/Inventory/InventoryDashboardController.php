<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryCategoryResource;
use App\Models\HospitalRequest;
use App\Models\InventoryCategory;
use App\Models\InventoryProduct;
use Illuminate\Http\JsonResponse;

class InventoryDashboardController extends Controller
{
    /**
     * Get inventory dashboard data with metrics
     */
    public function index(): JsonResponse
    {
        // Get categories with aggregated stock information
        $categories = InventoryCategory::with(['products.variants'])
            ->get()
            ->map(function ($category) {
                $totalStock = 0;
                $availableStock = 0;
                $lowStockItems = [];

                foreach ($category->products as $product) {
                    foreach ($product->variants as $variant) {
                        $totalStock += $variant->stock;
                        $availableStock += $variant->stock;

                        if ($variant->is_low_stock) {
                            $lowStockItems[] = [
                                'product_id' => $product->id,
                                'product_name' => $product->name,
                                'variant_id' => $variant->id,
                                'variant_label' => $variant->label,
                                'stock' => $variant->stock,
                                'min_stock' => $variant->min_stock,
                            ];
                        }
                    }
                }

                $category->total_stock = $totalStock;
                $category->available_stock = $availableStock;
                $category->reserved_stock = 0; // TODO: Calculate based on pending requests
                $category->low_stock_items = $lowStockItems;

                return $category;
            });

        // Get all low stock products across all categories
        $lowStockProducts = InventoryProduct::with(['variants' => function ($query) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }, 'category', 'subcategory'])
            ->whereHas('variants', function ($query) {
                $query->whereColumn('stock', '<=', 'min_stock');
            })
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category->name,
                    'subcategory' => $product->subcategory?->name,
                    'variants' => $product->variants->map(function ($variant) {
                        return [
                            'id' => $variant->id,
                            'label' => $variant->label,
                            'stock' => $variant->stock,
                            'min_stock' => $variant->min_stock,
                        ];
                    }),
                ];
            });

        // Get hospital requests summary
        $requestsSummary = [
            'pending' => HospitalRequest::where('status', 'pending')->count(),
            'approved' => HospitalRequest::where('status', 'approved')->count(),
            'preparing' => HospitalRequest::where('status', 'preparing')->count(),
            'delivered' => HospitalRequest::where('status', 'delivered')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => InventoryCategoryResource::collection($categories),
                'low_stock_products' => $lowStockProducts,
                'requests_summary' => $requestsSummary,
            ],
        ]);
    }
}
