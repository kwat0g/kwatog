<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->hash_id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'description'       => $this->description,
            'users_count'       => $this->users_count ?? null,
            'permissions_count' => $this->permissions_count ?? null,
            'permissions'       => $this->whenLoaded('permissions', fn () =>
                $this->permissions->map(fn ($p) => [
                    'id'     => $p->hash_id,
                    'slug'   => $p->slug,
                    'name'   => $p->name,
                    'module' => $p->module,
                ])->all()
            ),
            'created_at'        => $this->created_at?->toISOString(),
            'updated_at'        => $this->updated_at?->toISOString(),
        ];
    }
}
