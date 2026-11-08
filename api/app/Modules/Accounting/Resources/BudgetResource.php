<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Modules\Accounting\Enums\BudgetStatus;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Auth\Models\User;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $service = app(BudgetService::class);
        $availableActions = $actor instanceof User
            ? $service->availableActionsFor($this->resource, $actor)
            : [];
        $canView = $actor instanceof User
            && ($actor->hasPermission('budgeting.view') || $service->canReview($this->resource, $actor));

        return [
            'id'               => $this->hash_id,
            'fiscal_year_id'   => $this->fiscal_year_id === null ? null : app('hashids')->encode((int) $this->fiscal_year_id),
            'fiscal_year'      => new FiscalYearResource($this->whenLoaded('fiscalYear')),
            'department_id'    => $this->department_id === null ? null : app('hashids')->encode((int) $this->department_id),
            'department'       => $this->whenLoaded('department', fn () => [
                'id'   => $this->department?->hash_id,
                'name' => $this->department?->name,
                'code' => $this->department?->code,
            ]),
            'budget_type'      => $this->budget_type,
            'name'             => $this->name,
            'total_allocated'  => (string) $this->total_allocated,
            'total_spent'      => (string) $this->total_spent,
            'total_committed'  => (string) $this->total_committed,
            'available'        => (string) $this->available,
            'utilization_pct'  => $this->utilization_percent,
            'status'           => $this->status,
            'status_label'     => BudgetStatus::tryFrom((string) $this->status)?->label() ?? (string) $this->status,
            'can_approve'      => in_array('approve', $availableActions, true),
            'available_actions' => $availableActions,
            'permissions' => [
                'view' => $canView,
                'manage' => $actor instanceof User && $actor->hasPermission('budgeting.manage'),
                'approve' => in_array('approve', $availableActions, true),
            ],
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
            'rejected_by'      => $this->whenLoaded('rejectedBy', fn () => [
                'id'   => $this->rejectedBy?->hash_id,
                'name' => $this->rejectedBy?->name,
            ]),
            'rejected_at'      => $this->rejected_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'line_items'       => BudgetLineItemResource::collection($this->whenLoaded('lineItems')),
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
            'version'          => $this->updated_at?->toISOString(),
        ];
    }
}
