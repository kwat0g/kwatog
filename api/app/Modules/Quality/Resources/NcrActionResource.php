<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class NcrActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userCanVerify = $request->user()?->hasPermission('quality.ncr.manage') === true;
        $actionType = $this->action_type instanceof \BackedEnum ? $this->action_type->value : $this->action_type;
        $effectivenessStatus = $this->effectiveness_status instanceof \BackedEnum
            ? $this->effectiveness_status->value
            : $this->effectiveness_status;
        $ncrStatus = $this->relationLoaded('ncr') && $this->ncr
            ? ($this->ncr->status instanceof \BackedEnum ? $this->ncr->status->value : $this->ncr->status)
            : null;
        $canVerify = $userCanVerify
            && $ncrStatus === NcrStatus::Closed->value
            && in_array($actionType, [NcrActionType::Corrective->value, NcrActionType::Preventive->value], true)
            && in_array($effectivenessStatus, [
                EffectivenessStatus::PendingVerification->value,
                EffectivenessStatus::Ineffective->value,
            ], true);

        return [
            'id' => $this->hash_id,
            'action_type' => $this->action_type instanceof \BackedEnum ? $this->action_type->value : $this->action_type,
            'action_type_label' => Str::headline((string) ($this->action_type instanceof \BackedEnum ? $this->action_type->value : $this->action_type)),
            'description' => $this->description,
            'performed_at' => optional($this->performed_at)?->toISOString(),
            'performer' => $this->whenLoaded('performer', fn () => $this->performer ? [
                'id' => $this->performer->hash_id,
                'name' => $this->performer->name,
            ] : null),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->hash_id,
                'name' => $this->owner->name,
            ] : null),
            // CAPA effectiveness loop.
            'due_date' => optional($this->due_date)?->toDateString(),
            'effectiveness_status' => $this->effectiveness_status instanceof \BackedEnum ? $this->effectiveness_status->value : $this->effectiveness_status,
            'effectiveness_status_label' => $this->effectiveness_status instanceof EffectivenessStatus ? $this->effectiveness_status->label() : null,
            'effectiveness_notes' => $this->effectiveness_notes,
            'effectiveness_check_count' => (int) ($this->effectiveness_check_count ?? 0),
            'next_effectiveness_check_at' => optional($this->next_effectiveness_check_at)?->toDateString(),
            'verified_at' => optional($this->verified_at)?->toISOString(),
            'verifier' => $this->whenLoaded('verifier', fn () => $this->verifier ? [
                'id' => $this->verifier->hash_id,
                'name' => $this->verifier->name,
            ] : null),
            'ncr' => $this->whenLoaded('ncr', fn () => $this->ncr ? [
                'id' => $this->ncr->hash_id,
                'ncr_number' => $this->ncr->ncr_number,
            ] : null),
            'permissions' => [
                'verify_effectiveness' => $userCanVerify,
            ],
            'available_actions' => [
                'can_verify' => $canVerify,
            ],
        ];
    }
}
