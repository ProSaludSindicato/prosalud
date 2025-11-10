<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryColorResource;
use App\Models\InventoryColor;
use Illuminate\Http\JsonResponse;

class InventoryColorController extends Controller
{
    /**
     * Get all available colors
     */
    public function index(): JsonResponse
    {
        $colors = InventoryColor::all();

        return response()->json([
            'success' => true,
            'data' => InventoryColorResource::collection($colors),
        ]);
    }
}
