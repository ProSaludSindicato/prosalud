<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryProductResource extends JsonResource
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
            'variant_mode' => $this->variant_mode,
            'category_id' => $this->category_id,
            'subcategory_id' => $this->subcategory_id,
            'category' => new InventoryCategoryResource($this->whenLoaded('category')),
            'subcategory' => new InventorySubcategoryResource($this->whenLoaded('subcategory')),
            'variants' => InventoryVariantResource::collection($this->whenLoaded('variants')),
            'gender' => $this->gender,
            'total_stock' => $this->when(
                $this->relationLoaded('variants'),
                fn() => $this->total_stock
            ),
            'is_low_stock' => $this->when(
                $this->relationLoaded('variants'),
                fn() => $this->is_low_stock
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
