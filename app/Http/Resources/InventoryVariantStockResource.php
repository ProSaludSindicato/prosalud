<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryVariantStockResource extends JsonResource
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
            'variant_id' => $this->variant_id,
            'product_id' => $this->variant?->product_id,
            'product_name' => $this->variant?->product?->name,
            'sku' => $this->variant?->sku,
            'size' => $this->variant?->size,
            'color_id' => $this->variant?->color_id,
            'color' => $this->when($this->variant && $this->variant->relationLoaded('color'), fn () => [
                'id' => $this->variant?->color?->id,
                'label' => $this->variant?->color?->label,
                'hex' => $this->variant?->color?->hex,
            ]),
            'stock' => (int) $this->stock,
            'reserved' => (int) $this->reserved,
            'min_stock' => (int) $this->min_stock,
            'max_stock' => $this->max_stock !== null ? (int) $this->max_stock : null,
        ];
    }
}
