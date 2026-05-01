<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SettingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'key'   => $this->key,
            'value' => is_string($this->value) ? json_decode($this->value, true) : $this->value,
            'group' => $this->group,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
