<?php

declare(strict_types=1);

namespace App\Modules\Loans\Resources;

use App\Common\Services\ApprovalService;
use App\Modules\Loans\Support\LoanRate;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Policies\LoanAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var EmployeeLoan $loan */
        $loan = $this->resource;
        $actions = $this->actions($request, $loan);

        return [
            'id'                     => $this->hash_id,
            'loan_no'                => $this->loan_no,
            'employee'               => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'full_name'   => $this->employee->full_name,
            ] : null),
            'loan_type'              => $this->loan_type?->value,
            'loan_type_label'        => $this->loan_type?->label(),
            'principal'              => (string) $this->principal,
            'interest_rate'          => (string) $this->interest_rate,
            'interest_rate_percent'  => LoanRate::percent((string) $this->interest_rate),
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
            'status_label'           => $this->status?->label(),
            'is_final_pay_deduction' => (bool) $this->is_final_pay_deduction,
            'has_overdue_approval'   => $this->relationLoaded('approvalRecords')
                ? $this->approvalRecords->contains(fn ($r) => $r->action === 'pending' && $r->is_overdue)
                : false,
            'payments'               => LoanPaymentResource::collection($this->whenLoaded('payments')),
            'approval_records'       => $this->whenLoaded('approvalRecords', fn () => $this->approvalRecords->map(fn ($r) => [
                'step_order'    => (int) $r->step_order,
                'role_slug'     => $r->role_slug,
                'action'        => $r->action,
                'remarks'       => $r->remarks,
                'acted_at'      => optional($r->acted_at)->toIso8601String(),
                'approver'      => $r->relationLoaded('approver') && $r->approver ? [
                    'id'   => $r->approver->hash_id,
                    'name' => $r->approver->name,
                ] : null,
                'is_overdue'    => (bool) $r->is_overdue,
                'overdue_hours' => $r->is_overdue ? (int) $r->overdue_hours : null,
            ])->all()),
            'actions'                => $actions,
            'created_at'             => optional($this->created_at)->toIso8601String(),
            'updated_at'             => optional($this->updated_at)->toIso8601String(),
        ];
    }

    /** @return array{can_approve:bool,can_reject:bool,can_cancel:bool} */
    private function actions(Request $request, EmployeeLoan $loan): array
    {
        $user = $request->user();
        $next = $loan->relationLoaded('approvalRecords')
            ? $loan->approvalRecords->first(fn ($record): bool => $record->action === 'pending')
            : null;
        $submitterId = $loan->relationLoaded('employee') && $loan->employee?->relationLoaded('user')
            ? $loan->employee->user?->id
            : $loan->approvalSubmitterId();
        $canDecide = $user !== null
            && $next !== null
            && $user->can('loans.approve')
            && app(LoanAccessPolicy::class)->canDecide($user, $loan)
            && app(ApprovalService::class)->canUserActFor($user, (string) $next->role_slug)
            && (int) $submitterId !== (int) $user->id;

        return [
            'can_approve' => $canDecide,
            'can_reject' => $canDecide,
            'can_cancel' => $user !== null
                && $loan->status?->value === 'pending'
                && $user->can('loans.write_off')
                && app(LoanAccessPolicy::class)->canDecide($user, $loan),
        ];
    }
}
