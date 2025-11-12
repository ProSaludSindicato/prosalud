<?php

namespace App\Http\Resources;

use App\Models\InventoryEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryStockMovementResource extends JsonResource
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
            'variant' => $this->when($this->variant, fn () => array_filter([
                'label' => $this->variant->label,
                'size' => $this->variant->size,
                'color_id' => $this->variant->color_id,
                'color' => $this->variant->relationLoaded('color') ? array_filter([
                    'id' => $this->variant->color?->id,
                    'label' => $this->variant->color?->label,
                    'hex' => $this->variant->color?->hex,
                ], fn ($value) => $value !== null) : null,
                'gender' => $this->variant->product?->gender,
            ], fn ($value) => $value !== null)),
            'quantity' => (int) $this->quantity,
            'reason' => $this->reason,
            'from_location' => $this->whenLoaded('fromLocation', fn () => [
                'id' => $this->fromLocation?->id,
                'name' => $this->fromLocation?->name,
                'type' => $this->fromLocation?->type,
            ]),
            'from_supplier' => $this->when(
                $this->reason === 'entry'
                && $this->relationLoaded('reference')
                && $this->reference instanceof InventoryEntry,
                fn () => array_filter([
                    'id' => $this->reference->id,
                    'supplier_id' => $this->reference->supplier_id,
                    'supplier_name' => $this->reference->supplier_name,
                    'document_number' => $this->reference->document_number,
                ], fn ($value) => $value !== null)
            ),
            'to_location' => $this->whenLoaded('toLocation', fn () => [
                'id' => $this->toLocation?->id,
                'name' => $this->toLocation?->name,
                'type' => $this->toLocation?->type,
            ]),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'notes' => $this->notes,
            'moved_at' => $this->moved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
