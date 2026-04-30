<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'access_type' => $this->access_type,
            'allowed_affiliate_statuses' => $this->allowed_affiliate_statuses ?? ['activo'],
            'requires_signature' => $this->requires_signature,
            'allows_multiple_responses' => $this->allows_multiple_responses,
            'start_date' => $this->start_date?->toIso8601String(),
            'end_date' => $this->end_date?->toIso8601String(),
            'questions' => $this->when(
                $this->relationLoaded('questions'),
                fn () => $this->questions->map(fn ($q) => [
                    'id' => $q->id,
                    'type' => $q->type,
                    'label' => $q->label,
                    'help_text' => $q->help_text,
                    'is_required' => $q->is_required,
                    'order' => $q->order,
                    'options' => $q->options,
                    'ranking_unique_priority' => (bool) ($q->ranking_unique_priority ?? true),
                ])
            ),
            'hospitals' => $this->when(
                $this->relationLoaded('hospitals'),
                fn () => $this->hospitals->map(fn ($h) => [
                    'id' => $h->id,
                    'name' => $h->name,
                ])
            ),
            'responses_count' => $this->when(
                isset($this->responses_count),
                $this->responses_count
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
