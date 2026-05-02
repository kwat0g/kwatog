<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payroll\Models\Payroll
 */
class PayrollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->whenLoaded('employee');

        return [
            'id'              => $this->hash_id,
            'period_id'       => $this->whenLoaded('period', fn () => $this->period->hash_id),
            'employee'        => $employee ? [
                'id'           => $this->employee->hash_id,
                'employee_no'  => $this->employee->employee_no,
                'full_name'    => $this->employee->full_name,
                'department'   => $this->employee->department?->name,
                'position'     => $this->employee->position?->title,
            ] : null,

            'pay_type'        => $this->pay_type,
            'days_worked'     => $this->days_worked,

            'basic_pay'       => $this->basic_pay,
            'overtime_pay'    => $this->overtime_pay,
            'night_diff_pay'  => $this->night_diff_pay,
            'holiday_pay'     => $this->holiday_pay,
            'gross_pay'       => $this->gross_pay,

            'sss_ee'          => $this->sss_ee,
            'sss_er'          => $this->sss_er,
            'philhealth_ee'   => $this->philhealth_ee,
            'philhealth_er'   => $this->philhealth_er,
            'pagibig_ee'      => $this->pagibig_ee,
            'pagibig_er'      => $this->pagibig_er,
            'withholding_tax' => $this->withholding_tax,

            'loan_deductions'   => $this->loan_deductions,
            'other_deductions'  => $this->other_deductions,
            'adjustment_amount' => $this->adjustment_amount,
            'total_deductions'  => $this->total_deductions,
            'net_pay'           => $this->net_pay,

            'error_message'   => $this->error_message,
            'computed_at'     => optional($this->computed_at)->toIso8601String(),

            'deduction_details' => PayrollDeductionDetailResource::collection($this->whenLoaded('deductionDetails')),

            'created_at'      => optional($this->created_at)->toIso8601String(),
            'updated_at'      => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
