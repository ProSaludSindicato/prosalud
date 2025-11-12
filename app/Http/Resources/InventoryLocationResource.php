<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLocationResource extends JsonResource
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
            'type' => $this->type,
            'is_primary' => $this->is_primary,
            'hospital' => $this->whenLoaded('hospital', fn () => [
                'id' => $this->hospital?->id,
                'name' => $this->hospital?->name,
                'type' => $this->hospital?->type,
            ]),
            'total_stock' => (int) ($this->total_stock ?? 0),
            'total_reserved' => (int) ($this->total_reserved ?? 0),
            'total_variants' => (int) ($this->total_variants ?? 0),
            'stocks' => InventoryVariantStockResource::collection($this->whenLoaded('stocks')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
