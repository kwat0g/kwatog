<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'budget_id'       => $this->budget_id,
            'revision_number' => $this->revision_number,
            'changes'         => $this->changes,
            'reason'          => $this->reason,
            'status'          => $this->status,
            'submitted_by'    => $this->whenLoaded('submittedBy', fn () => [
                'id'   => $this->submittedBy?->hash_id,
                'name' => $this->submittedBy?->name,
            ]),
            'approved_by'     => $this->whenLoaded('approvedBy', fn () => [
                'id'   => $this->approvedBy?->hash_id,
                'name' => $this->approvedBy?->name,
            ]),
            'created_at'      => $this->created_at,
        ];
    }
}
