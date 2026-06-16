<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountingPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->hash_id,
            'year'          => (int) $this->year,
            'month'         => (int) $this->month,
            'status'        => $this->status?->value,
            'status_label'  => $this->status?->label(),
            'closed_at'     => optional($this->closed_at)->toIso8601String(),
            'closed_by'     => $this->whenLoaded('closedBy', fn () => $this->closedBy ? [
                'id'   => $this->closedBy->hash_id ?? null,
                'name' => $this->closedBy->name,
            ] : null),
            'reopened_at'   => optional($this->reopened_at)->toIso8601String(),
            'reopened_by'   => $this->whenLoaded('reopenedBy', fn () => $this->reopenedBy ? [
                'id'   => $this->reopenedBy->hash_id ?? null,
                'name' => $this->reopenedBy->name,
            ] : null),
            'reopen_reason' => $this->reopen_reason,
            'created_at'    => optional($this->created_at)->toIso8601String(),
            'updated_at'    => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
