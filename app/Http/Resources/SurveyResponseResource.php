<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'survey_id' => $this->survey_id,
            'respondent_document_type' => $this->respondent_document_type,
            'respondent_document_number' => $this->respondent_document_number,
            'respondent_name' => $this->respondent_name,
            'hospital' => $this->hospital,
            'metadata' => $this->metadata,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'answers' => $this->when(
                $this->relationLoaded('answers'),
                fn () => $this->answers->map(fn ($a) => [
                    'question_id' => $a->question_id,
                    'question_label' => $a->question?->label,
                    'question_type' => $a->question?->type,
                    'value' => $a->decoded_value,
                ])
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
