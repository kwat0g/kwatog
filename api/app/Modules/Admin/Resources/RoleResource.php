<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray($request): array
    {
        $isSystem = (bool) ($this->is_system ?? false);

        return [
            'id'                => $this->hash_id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'description'       => $this->description,
            'is_system'         => $isSystem,
            'type'              => $isSystem ? 'System' : 'Custom',
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
            // ADV4 — most recent edit (updated / permissions_synced / cloned)
            // sourced from audit_logs by RoleService::lastModifiedFor().
            'last_modified_by'  => isset($this->last_modified_meta) ? $this->last_modified_meta['by'] : null,
            'last_modified_at'  => isset($this->last_modified_meta) ? $this->last_modified_meta['at'] : null,
        ];
    }
}
