<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryVariantResource extends JsonResource
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
            'product_id' => $this->product_id,
            'size' => $this->size,
            'color_id' => $this->color_id,
            'color' => new InventoryColorResource($this->whenLoaded('color')),
            'stock' => $this->stock,
            'min_stock' => $this->min_stock,
            'max_stock' => $this->max_stock,
            'sku' => $this->sku,
            'is_low_stock' => $this->is_low_stock,
            'label' => $this->label,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
