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
            'id' => $this->hash_id,
            'role_slug' => $this->role_slug,
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'reason' => $this->reason,
            'is_active' => (bool) $this->is_active,
            'role_is_current' => $this->whenLoaded('delegator', function (): bool {
                $roleSlug = $this->delegator?->role?->slug;

                return $roleSlug !== null && ($this->role_slug === null || $roleSlug === $this->role_slug);
            }),
            'can_revoke' => $request->user() !== null && (
                $request->user()->id === $this->delegator_user_id
                || $request->user()->hasPermission('admin.delegations.manage_any')
            ),
            'delegator' => $this->whenLoaded('delegator', fn () => $this->delegator ? [
                'id' => $this->delegator->hash_id,
                'name' => $this->delegator->name,
                'is_active' => (bool) $this->delegator->is_active,
            ] : null),
            'delegate' => $this->whenLoaded('delegate', fn () => $this->delegate ? [
                'id' => $this->delegate->hash_id,
                'name' => $this->delegate->name,
                'is_active' => (bool) $this->delegate->is_active,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
