<?php

declare(strict_types=1);

namespace App\Modules\Loans\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->hash_id,
            'loan_no'                => $this->loan_no,
            'employee'               => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'full_name'   => $this->employee->full_name,
            ] : null),
            'loan_type'              => $this->loan_type?->value,
            'principal'              => (string) $this->principal,
            'interest_rate'          => (string) $this->interest_rate,
            'monthly_amortization'   => (string) $this->monthly_amortization,
            'total_paid'             => (string) $this->total_paid,
            'balance'                => (string) $this->balance,
            'start_date'             => optional($this->start_date)->toDateString(),
            'end_date'               => optional($this->end_date)->toDateString(),
            'pay_periods_total'      => (int) $this->pay_periods_total,
            'pay_periods_remaining'  => (int) $this->pay_periods_remaining,
            'approval_chain_size'    => (int) $this->approval_chain_size,
            'purpose'                => $this->purpose,
            'status'                 => $this->status?->value,
            'is_final_pay_deduction' => (bool) $this->is_final_pay_deduction,
            'payments'               => LoanPaymentResource::collection($this->whenLoaded('payments')),
            'created_at'             => optional($this->created_at)->toIso8601String(),
            'updated_at'             => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
