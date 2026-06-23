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
            // CAPA effectiveness loop.
            'due_date'                    => optional($this->due_date)?->toDateString(),
            'effectiveness_status'        => $this->effectiveness_status instanceof \BackedEnum ? $this->effectiveness_status->value : $this->effectiveness_status,
            'effectiveness_status_label'  => $this->effectiveness_status instanceof \App\Modules\Quality\Enums\EffectivenessStatus ? $this->effectiveness_status->label() : null,
            'effectiveness_notes'         => $this->effectiveness_notes,
            'effectiveness_check_count'   => (int) ($this->effectiveness_check_count ?? 0),
            'next_effectiveness_check_at' => optional($this->next_effectiveness_check_at)?->toDateString(),
            'verified_at'                 => optional($this->verified_at)?->toISOString(),
            'verifier'                    => $this->whenLoaded('verifier', fn () => $this->verifier ? [
                'id'   => $this->verifier->hash_id,
                'name' => $this->verifier->name,
            ] : null),
        ];
    }
}
