<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'from_budget_line_id' => $this->from_budget_line_id,
            'to_budget_line_id'   => $this->to_budget_line_id,
            'from_line_item'   => new BudgetLineItemResource($this->whenLoaded('fromLineItem')),
            'to_line_item'     => new BudgetLineItemResource($this->whenLoaded('toLineItem')),
            'amount'           => (float) $this->amount,
            'reason'           => $this->reason,
            'status'           => $this->status,
            'requested_by'     => $this->whenLoaded('requestedBy', fn () => [
                'id'   => $this->requestedBy?->hash_id,
                'name' => $this->requestedBy?->name,
            ]),
            'approved_by'      => $this->whenLoaded('approvedBy', fn () => [
                'id'   => $this->approvedBy?->hash_id,
                'name' => $this->approvedBy?->name,
            ]),
            'approved_at'      => $this->approved_at?->toISOString(),
            'created_at'       => $this->created_at,
        ];
    }
}
