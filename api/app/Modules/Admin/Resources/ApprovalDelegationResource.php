<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Common\Models\ApprovalDelegation;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalDelegation
 */
class ApprovalDelegationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'        => $this->hash_id,
            'role_slug' => $this->role_slug,
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at'   => $this->ends_at?->toDateString(),
            'reason'    => $this->reason,
            'is_active' => (bool) $this->is_active,
            'delegator' => $this->whenLoaded('delegator', fn () => $this->delegator ? [
                'id'    => $this->delegator->hash_id,
                'name'  => $this->delegator->name,
                'email' => $this->delegator->email,
            ] : null),
            'delegate'  => $this->whenLoaded('delegate', fn () => $this->delegate ? [
                'id'    => $this->delegate->hash_id,
                'name'  => $this->delegate->name,
                'email' => $this->delegate->email,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
