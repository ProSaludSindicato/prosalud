<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryEntryResource extends JsonResource
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
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,
            'received_at' => $this->received_at?->toISOString(),
            'document_number' => $this->document_number,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_by_user_id' => $this->created_by_user_id,
            'total_items' => $this->total_items,
            'total_quantity' => $this->total_quantity,
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', function () {
                return array_filter([
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'type' => $this->location->type,
                    'hospital_id' => $this->location->hospital_id,
                ], fn ($value) => null !== $value);
            }),
            'items' => InventoryEntryItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
