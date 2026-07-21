<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Admin\Models\SodConflictRule
 */
class SodConflictRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'code'         => $this->code,
            'name'         => $this->name,
            'severity'     => $this->severity->value,
            'rationale'    => $this->rationale,
            'active'       => $this->active,
            'permission_a' => [
                'slug' => $this->permissionA->slug,
                'name' => $this->permissionA->name,
            ],
            'permission_b' => [
                'slug' => $this->permissionB->slug,
                'name' => $this->permissionB->name,
            ],
        ];
    }
}
