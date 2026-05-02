<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoldHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'event_type'          => (string) $this->event_type?->value,
            'description'         => $this->description,
            'cost'                => $this->cost ? (string) $this->cost : null,
            'performed_by'        => $this->performed_by,
            'event_date'          => optional($this->event_date)->toDateString(),
            'shot_count_at_event' => (int) $this->shot_count_at_event,
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
