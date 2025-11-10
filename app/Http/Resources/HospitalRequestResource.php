<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HospitalRequestResource extends JsonResource
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
            'hospital_id' => $this->hospital_id,
            'hospital_name' => $this->hospital_name,
            'requested_by' => $this->requested_by,
            'status' => $this->status,
            'observations' => $this->observations,
            'items' => HospitalRequestItemResource::collection($this->whenLoaded('items')),
            'timeline' => $this->when(
                $this->relationLoaded('timeline'),
                fn() => $this->timeline->map(fn($entry) => [
                    'id' => $entry->id,
                    'status' => $entry->status,
                    'timestamp' => $entry->timestamp->toISOString(),
                    'description' => $entry->description,
                    'actor' => $entry->actor,
                ])
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
