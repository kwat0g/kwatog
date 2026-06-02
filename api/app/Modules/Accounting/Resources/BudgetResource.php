<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'fiscal_year_id'   => $this->fiscal_year_id,
            'fiscal_year'      => new FiscalYearResource($this->whenLoaded('fiscalYear')),
            'department_id'    => $this->department_id,
            'department'       => $this->whenLoaded('department', fn () => [
                'id'   => $this->department?->hash_id,
                'name' => $this->department?->name,
                'code' => $this->department?->code,
            ]),
            'budget_type'      => $this->budget_type,
            'name'             => $this->name,
            'total_allocated'  => (float) $this->total_allocated,
            'total_spent'      => (float) $this->total_spent,
            'total_committed'  => (float) $this->total_committed,
            'available'        => $this->available,
            'utilization_pct'  => $this->utilization_percent,
            'status'           => $this->status,
            'submitted_by'     => $this->whenLoaded('submittedBy', fn () => [
                'id'   => $this->submittedBy?->hash_id,
                'name' => $this->submittedBy?->name,
            ]),
            'submitted_at'     => $this->submitted_at?->toISOString(),
            'approved_by'      => $this->whenLoaded('approvedBy', fn () => [
                'id'   => $this->approvedBy?->hash_id,
                'name' => $this->approvedBy?->name,
            ]),
            'approved_at'      => $this->approved_at?->toISOString(),
            'line_items'       => BudgetLineItemResource::collection($this->whenLoaded('lineItems')),
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
