<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use App\Common\Services\ApprovalService;
use App\Modules\HR\Models\SalaryAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin SalaryAdjustment
 */
class SalaryAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canAct = $user !== null && $user->can('hr.salary_adjustments.act');
        $availableActions = $canAct && $this->status->value === 'pending'
            ? app(ApprovalService::class)->availableActions($this->resource, $user)
            : [];

        return [
            'id'                        => $this->hash_id,
            'employee'                  => new EmployeeResource($this->whenLoaded('employee')),
            'from_basic_monthly_salary' => $this->from_basic_monthly_salary,
            'from_semi_monthly_rate'           => $this->from_semi_monthly_rate,
            'to_basic_monthly_salary'   => $this->to_basic_monthly_salary,
            'to_semi_monthly_rate'             => $this->to_semi_monthly_rate,
            'effective_date'            => $this->effective_date?->toDateString(),
            'reason'                    => $this->reason,
            'status'                    => $this->status->value,
            'status_label'              => Str::headline((string) $this->status->value),
            'requested_by'              => $this->whenLoaded('requester', fn () => [
                'id'   => $this->requester->hash_id,
                'name' => $this->requester->name,
            ]),
            'applied_at'                => $this->applied_at?->toIso8601String(),
            'created_at'                => $this->created_at?->toIso8601String(),
            'updated_at'                => $this->updated_at?->toIso8601String(),
            'version'                   => optional($this->updated_at)->format('Y-m-d\\TH:i:s.uP'),
            'permissions'               => [
                'view' => $user !== null && $user->can('hr.salary_adjustments.view'),
                'act' => $canAct,
            ],
            'available_actions'         => $availableActions,
        ];
    }
}
