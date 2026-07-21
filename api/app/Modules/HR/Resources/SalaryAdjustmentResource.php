<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\HR\Models\SalaryAdjustment
 */
class SalaryAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->hash_id,
            'employee'                  => new EmployeeResource($this->whenLoaded('employee')),
            'from_basic_monthly_salary' => $this->from_basic_monthly_salary,
            'from_daily_rate'           => $this->from_daily_rate,
            'to_basic_monthly_salary'   => $this->to_basic_monthly_salary,
            'to_daily_rate'             => $this->to_daily_rate,
            'effective_date'            => $this->effective_date?->toDateString(),
            'reason'                    => $this->reason,
            'status'                    => $this->status->value,
            'requested_by'              => $this->whenLoaded('requester', fn () => [
                'id'   => $this->requester->hash_id,
                'name' => $this->requester->name,
            ]),
            'applied_at'                => $this->applied_at?->toIso8601String(),
            'created_at'                => $this->created_at?->toIso8601String(),
        ];
    }
}
