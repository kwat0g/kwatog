<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->hash_id,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'module'      => $this->module,
            'description' => $this->description,
        ];
    }
}
