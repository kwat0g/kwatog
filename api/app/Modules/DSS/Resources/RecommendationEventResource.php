<?php

declare(strict_types=1);

namespace App\Modules\DSS\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationEventResource extends JsonResource
{
    private const LABELS = [
        'viewed' => 'Viewed',
        'started' => 'Follow-up started',
        'accepted' => 'Accepted',
        'dismissed' => 'Dismissed',
        'snoozed' => 'Snoozed',
        'expired' => 'Expired',
        'executed' => 'Marked as executed',
        'resolved' => 'Resolved',
        'outcome' => 'Outcome recorded',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $value = $metadata['outcome']['value'] ?? null;
        $unit = $metadata['outcome']['unit'] ?? null;
        $outcome = is_numeric($value)
            ? trim((string) $value.' '.(is_string($unit) && preg_match('/^[A-Za-z%₱$._-]{1,20}$/uD', $unit) === 1 ? $unit : ''))
            : null;
        $reason = is_string($this->reason) ? mb_substr(trim($this->reason), 0, 200) : null;
        $actorName = $this->actor?->name;

        return [
            'id' => $this->hash_id,
            'label' => self::LABELS[$this->event] ?? 'Activity recorded',
            'at' => $this->created_at?->toISOString(),
            'actor' => is_string($actorName) ? mb_substr($actorName, 0, 100) : null,
            'reason' => $reason !== '' ? $reason : null,
            'outcome_summary' => $outcome,
        ];
    }
}
