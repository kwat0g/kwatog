<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Use the raw attributes array, NOT getAttribute(): strict mode
        // (Model::shouldBeStrict()) throws when an unknown attribute key is
        // accessed via the magic getter. The flat list endpoint doesn't
        // pre-compute these; only AccountService::tree() does.
        $attrs   = $this->resource->getAttributes();
        $current = $attrs['current_balance'] ?? null;
        $td      = $attrs['total_debit']     ?? null;
        $tc      = $attrs['total_credit']    ?? null;

        return [
            'id'             => $this->hash_id,
            'code'           => $this->code,
            'name'           => $this->name,
            'type'           => $this->type?->value,
            'type_label'     => $this->type?->label(),
            'normal_balance' => $this->normal_balance?->value,
            'parent_id'      => $this->whenLoaded('parent', fn () => $this->parent?->hash_id),
            'parent_code'    => $this->whenLoaded('parent', fn () => $this->parent?->code),
            'is_active'      => (bool) $this->is_active,
            'is_leaf'        => $this->resource->relationLoaded('children')
                ? $this->resource->children->isEmpty()
                : null,
            'description'    => $this->description,
            'children'       => self::collection($this->whenLoaded('children')),
            // These come from AccountService::tree(); null when not pre-computed.
            'current_balance'=> $current !== null ? (string) $current : null,
            'total_debit'    => $td !== null ? (string) $td : null,
            'total_credit'   => $tc !== null ? (string) $tc : null,
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
