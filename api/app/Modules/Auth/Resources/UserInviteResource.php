<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-A.1 — Listing/creation shape for invites. Token is intentionally
 * absent from this resource for security; it is delivered out-of-band via
 * the invite email and only inspected on accept.
 */
class UserInviteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->hash_id,
            'email'      => $this->email,
            'expires_at' => optional($this->expires_at)->toIso8601String(),
            'used_at'    => optional($this->used_at)->toIso8601String(),
            'is_pending' => $this->resource->isPending(),
            'is_expired' => $this->resource->isExpired(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'employee'   => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'full_name'   => $this->employee->full_name,
            ]),
            'role' => $this->whenLoaded('role', fn () => $this->role ? [
                'id'   => $this->role->hash_id,
                'name' => $this->role->name,
                'slug' => $this->role->slug,
            ] : null),
            'inviter' => $this->whenLoaded('inviter', fn () => $this->inviter ? [
                'id'   => $this->inviter->hash_id,
                'name' => $this->inviter->name,
            ] : null),
        ];
    }
}
