<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'subcategories' => InventorySubcategoryResource::collection($this->whenLoaded('subcategories')),
            'products_count' => $this->when(
                $this->relationLoaded('products'),
                fn() => $this->products->count()
            ),
            'total_stock' => $this->when(
                isset($this->total_stock),
                fn() => $this->total_stock
            ),
            'available_stock' => $this->when(
                isset($this->available_stock),
                fn() => $this->available_stock
            ),
            'reserved_stock' => $this->when(
                isset($this->reserved_stock),
                fn() => $this->reserved_stock
            ),
            'low_stock_items' => $this->when(
                isset($this->low_stock_items),
                fn() => $this->low_stock_items
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
