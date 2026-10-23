<?php

declare(strict_types=1);

namespace App\Modules\DSS\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'detector_id' => $this->detector_id,
            'detector_version' => $this->detector_version,
            'domain' => $this->domain,
            'type' => $this->type,
            'severity' => $this->severity,
            'status' => $this->status,
            // Decimals arrive as strings — never reintroduce float rounding.
            'priority_score' => $this->priority_score,
            'impact_estimate' => $this->impact_estimate,
            'impact_unit' => $this->impact_unit,
            'impact_is_estimate' => $this->impact_is_estimate,
            'confidence' => $this->confidence,
            'data_sufficiency' => $this->data_sufficiency,
            'rationale' => $this->rationale,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'expires_at' => $this->expires_at?->toISOString(),
            'snoozed_until' => $this->snoozed_until?->toISOString(),
            'dismiss_reason' => $this->dismiss_reason,
            'decided_at' => $this->decided_at?->toISOString(),
            'events_count' => $this->whenCounted('events'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
