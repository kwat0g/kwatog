<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payroll\Models\PayrollAdjustment
 */
class PayrollAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'period'       => $this->whenLoaded('period', fn () => [
                'id'    => $this->period->hash_id,
                'label' => $this->period->label(),
            ]),
            'employee'     => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'full_name'   => $this->employee->full_name,
            ]),
            'original_payroll_id' => $this->whenLoaded('originalPayroll', fn () => $this->originalPayroll?->hash_id),
            'type'                => $this->type?->value,
            'type_label'          => $this->type?->label(),
            'amount'              => $this->amount,
            'reason'              => $this->reason,
            'status'              => $this->status?->value,
            'status_label'        => $this->status?->label(),
            'approver'            => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id'   => $this->approver->hash_id,
                'name' => $this->approver->name,
            ] : null),
            'applied_at'          => optional($this->applied_at)->toIso8601String(),
            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
