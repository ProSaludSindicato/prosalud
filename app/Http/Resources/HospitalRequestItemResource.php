<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HospitalRequestItemResource extends JsonResource
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
            'variant_id' => $this->variant_id,
            'variant_label' => $this->variant_label,
            'size' => $this->size,
            'color_id' => $this->color_id,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'product' => new InventoryProductResource($this->whenLoaded('product')),
            'variant' => new InventoryVariantResource($this->whenLoaded('variant')),
            'current_stock' => $this->when(
                $this->relationLoaded('variant'),
                fn () => $this->variant?->stock
            ),
        ];
    }
}
