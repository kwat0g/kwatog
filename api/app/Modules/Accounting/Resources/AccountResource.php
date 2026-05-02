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
        $current = $this->resource->getAttribute('current_balance');
        $td      = $this->resource->getAttribute('total_debit');
        $tc      = $this->resource->getAttribute('total_credit');

        return [
            'id'             => $this->hash_id,
            'code'           => $this->code,
            'name'           => $this->name,
            'type'           => $this->type?->value,
            'type_label'     => $this->type?->label(),
            'normal_balance' => $this->normal_balance?->value,
            'parent_id'      => $this->parent_id ? Account::find($this->parent_id)?->hash_id : null,
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
