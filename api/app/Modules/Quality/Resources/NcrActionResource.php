<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NcrActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'action_type'  => $this->action_type instanceof \BackedEnum ? $this->action_type->value : $this->action_type,
            'description'  => $this->description,
            'performed_at' => optional($this->performed_at)?->toISOString(),
            'performer'    => $this->whenLoaded('performer', fn () => $this->performer ? [
                'id'   => $this->performer->hash_id,
                'name' => $this->performer->name,
            ] : null),
        ];
    }
}
