<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierDeliveryResource extends JsonResource
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
            'supplier_name' => $this->supplier_name,
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'total_items' => $this->total_items,
            'status' => $this->status,
            'delivery_type' => $this->delivery_type,
            'notes' => $this->notes,
            'items' => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $item->quantity,
                    'received' => $item->received,
                    'product' => new InventoryProductResource($item->whenLoaded('product')),
                    'variant' => new InventoryVariantResource($item->whenLoaded('variant')),
                ])
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
