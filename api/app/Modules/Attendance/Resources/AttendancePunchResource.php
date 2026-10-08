<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Only ever returned by the route gated on `attendance.punch.audit`, so raw
 * coordinates are included. They are null once the 90-day purge has run.
 */
class AttendancePunchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'direction' => $this->direction->value,
            'punched_at' => $this->punched_at->toIso8601String(),
            'latitude' => $this->latitude !== null ? (string) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (string) $this->longitude : null,
            'accuracy_m' => (string) $this->accuracy_m,
            'distance_m' => (string) $this->distance_m,
            'ip' => $this->ip,
            'flags' => $this->flags ?? [],
        ];
    }
}
