<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ControlledDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->hash_id,
            'code'                   => $this->code,
            'title'                  => $this->title,
            'category'               => $this->category,
            'description'            => $this->description,
            'assignee_role'          => $this->assignee_role,
            'review_interval_months' => $this->review_interval_months !== null
                ? (int) $this->review_interval_months : null,
            'last_reviewed_at'       => $this->last_reviewed_at?->toISOString(),
            'last_review_alert_at'   => $this->last_review_alert_at?->toISOString(),
            'is_active'              => (bool) $this->is_active,
            'current_revision'       => $this->whenLoaded(
                'currentRevision',
                fn () => $this->currentRevision ? [
                    'id'              => $this->currentRevision->hash_id,
                    'revision_number' => (int) $this->currentRevision->revision_number,
                    'effective_date'  => $this->currentRevision->effective_date?->toDateString(),
                    'published_at'    => $this->currentRevision->published_at?->toISOString(),
                ] : null,
            ),
            'created_at'             => $this->created_at?->toISOString(),
            'updated_at'             => $this->updated_at?->toISOString(),
        ];
    }
}
