<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Common\Enums\PermissionOverrideType;
use App\Modules\Admin\Models\UserPermissionOverride;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserPermissionOverride
 */
class UserPermissionOverrideResource extends JsonResource
{
    public function toArray($request): array
    {
        $type = $this->type instanceof PermissionOverrideType
            ? $this->type->value
            : (string) $this->type;

        return [
            'id'         => $this->hash_id,
            'type'       => $type,
            'permission' => $this->whenLoaded('permission', fn () => [
                'id'          => $this->permission->hash_id,
                'slug'        => $this->permission->slug,
                'name'        => $this->permission->name,
                'module'      => $this->permission->module,
                'description' => $this->permission->description,
            ]),
            'granted_by' => $this->whenLoaded('grantedBy', fn () => $this->grantedBy
                ? [
                    'id'    => $this->grantedBy->hash_id,
                    'name'  => $this->grantedBy->name,
                    'email' => $this->grantedBy->email,
                ]
                : null
            ),
            'reason'     => $this->reason,
            'expires_at' => $this->expires_at?->toISOString(),
            'is_expired' => $this->isExpired(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
