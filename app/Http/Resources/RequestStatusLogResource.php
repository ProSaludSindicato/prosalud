<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RequestStatusLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'old_status' => $this->old_status,
            'new_status' => $this->new_status,
            'changed_by' => $this->changed_by,
            'changed_by_name' => $this->user?->name,
            'changed_by_email' => $this->user?->email,
            'reason' => $this->reason,
            'created_at' => $this->created_at,
            'created_at_formatted' => $this->created_at
                ? $this->created_at->format('d/m/Y H:i:s')
                : null,
        ];
    }
}


