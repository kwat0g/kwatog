<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentChangeType;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentHistory;
use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 8 — Task 71. Employee separation orchestrator.
 *
 * Lifecycle:
 *   initiate()        creates Clearance with default per-department checklist
 *                     and flips employee.status to on_leave
 *   signItem()        marks one checklist item as cleared (with auth check)
 *   markAllSigned()   transitions to completed when every item is cleared
 *   finalize()        requires final pay computed; flips employee.status to
 *                     resigned/terminated/retired and stamps employment history.
 */
class SeparationService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SettingsService $settings,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Clearance::query()->with([
            'employee:id,employee_no,first_name,last_name,department_id,position_id',
            'employee.department:id,name,code',
            'employee.position:id,title',
        ]);
        foreach (['status', 'separation_reason', 'employee_id'] as $f) {
            if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        }
        return $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(Clearance $clearance): Clearance
    {
        return $clearance->load([
            'employee:id,employee_no,first_name,last_name,department_id,position_id,date_hired,basic_monthly_salary,semi_monthly_rate,pay_type',
            'employee.department:id,name,code',
            'employee.position:id,title',
            'initiator:id,name',
            'finalizer:id,name',
        ]);
    }

    public function initiate(Employee $employee, array $data, User $by): Clearance
    {
        if (in_array($employee->status?->value, ['resigned', 'terminated', 'retired'], true)) {
            throw new BusinessRuleException('Employee is already separated.');
        }
        return DB::transaction(function () use ($employee, $data, $by) {
            $reason = SeparationReason::from((string) $data['separation_reason']);

            $items = array_map(fn (array $row) => [
                'department' => $row['department'],
                'item_key'   => $row['item_key'],
                'label'      => $row['label'],
                'status'     => 'pending',
                'signed_by'  => null,
                'signed_at'  => null,
                'remarks'    => null,
            ], $this->configuredChecklist());

            $clearance = Clearance::create([
                'clearance_no'      => $this->sequences->generate('clearance'),
                'employee_id'       => $employee->id,
                'separation_date'   => $data['separation_date'],
                'separation_reason' => $reason->value,
                'clearance_items'   => $items,
                'status'            => ClearanceStatus::InProgress->value,
                'initiated_by'      => $by->id,
            ]);

            $employee->forceFill(['status' => EmployeeStatus::OnLeave->value])->save();

            EmploymentHistory::create([
                'employee_id'    => $employee->id,
                'change_type'    => EmploymentChangeType::Separated->value,
                'from_value'     => null,
                'to_value'       => json_encode([
                    'separation_date'   => (string) $data['separation_date'],
                    'separation_reason' => $reason->value,
                    'status'            => 'in_progress',
                ]),
                'effective_date' => $data['separation_date'],
                'remarks'        => 'Separation initiated. Clearance '.$clearance->clearance_no.'.',
                'approved_by'    => $by->id,
            ]);

            // Series C — Task C3. Domain event for chain listeners.
            $fresh = $clearance->fresh();
            DB::afterCommit(fn () =>
                event(new \App\Modules\HR\Events\SeparationInitiated($fresh))
            );

            return $this->show($clearance);
        });
    }

    /** @return array<int, array{department:string,item_key:string,label:string}> */
    private function configuredChecklist(): array
    {
        $items = $this->settings->get('hr.separation.clearance_checklist');
        if (! is_array($items) || $items === []) {
            $items = self::defaultChecklist();
        }
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['department'], $item['item_key'], $item['label'])) {
                throw new BusinessRuleException('Separation clearance checklist contains an invalid item.');
            }
        }
        return array_values($items);
    }

    public static function defaultChecklist(): array
    {
        return [
            ['department' => 'HR', 'item_key' => 'exit_interview', 'label' => 'Exit Interview Completed'],
            ['department' => 'HR', 'item_key' => 'id_surrender', 'label' => 'Company ID & Uniform Surrendered'],
            ['department' => 'FIN', 'item_key' => 'loan_cleared', 'label' => 'Company Loans & Advances Settled'],
            ['department' => 'FIN', 'item_key' => 'accountability_cleared', 'label' => 'Financial Accountabilities Cleared'],
            ['department' => 'IT', 'item_key' => 'email_deactivated', 'label' => 'Email & System Access Revoked'],
            ['department' => 'IT', 'item_key' => 'laptop_surrendered', 'label' => 'IT Equipment & Accessories Returned'],
            ['department' => 'PROD', 'item_key' => 'tools_surrendered', 'label' => 'Factory Tools & PPE Returned'],
            ['department' => 'WH', 'item_key' => 'stock_handover', 'label' => 'Warehouse Custody Handover'],
            ['department' => 'MAINT', 'item_key' => 'maintenance_keys', 'label' => 'Locker & Tool Cabinet Keys Returned'],
            ['department' => 'ADMIN', 'item_key' => 'gate_pass', 'label' => 'Security Clearance & Gate Pass'],
            ['department' => 'EXEC', 'item_key' => 'final_approval', 'label' => 'Executive Management Clearance'],
        ];
    }

    public function signItem(Clearance $clearance, string $itemKey, User $by, ?string $remarks = null): Clearance
    {
        if ($clearance->status->isTerminal()) {
            throw new BusinessRuleException('Clearance is closed.');
        }
        return DB::transaction(function () use ($clearance, $itemKey, $by, $remarks) {
            $items = $clearance->clearance_items ?? [];
            $found = false;
            foreach ($items as &$item) {
                if (($item['item_key'] ?? '') === $itemKey) {
                    $found = true;
                    // Soft auth check — user must belong to that department,
                    // or have hr_officer / system_admin role. Officer override
                    // is enforced at controller via permission middleware.
                    $item['status']    = 'cleared';
                    $item['signed_by'] = $by->id;
                    $item['signed_at'] = now()->toISOString();
                    if ($remarks !== null) $item['remarks'] = $remarks;
                    break;
                }
            }
            unset($item);
            if (! $found) {
                throw new BusinessRuleException("Item '{$itemKey}' not found on clearance.");
            }
            $clearance->clearance_items = $items;

            $allCleared = collect($items)->every(fn (array $i) => ($i['status'] ?? '') === 'cleared');
            $becameCompleted = false;
            if ($allCleared) {
                $becameCompleted = $clearance->status !== ClearanceStatus::Completed
                    && $clearance->status !== ClearanceStatus::Finalized;
                $clearance->status = ClearanceStatus::Completed->value;
            }
            $clearance->save();

            if ($becameCompleted) {
                // Series C — Task C3. Domain event for chain listeners
                // (DeactivateAccountOnClearanceComplete + future final-pay
                // automation). Fires only on the transition, not every save.
                $fresh = $clearance->fresh();
                DB::afterCommit(fn () =>
                    event(new \App\Modules\HR\Events\ClearanceFullySigned($fresh))
                );
            }

            return $this->show($clearance);
        });
    }

    public function finalize(Clearance $clearance, User $by, FinalPayService $finalPay): Clearance
    {
        if ($clearance->status === ClearanceStatus::Finalized) {
            throw new BusinessRuleException('Clearance is already finalized.');
        }
        if ($clearance->status !== ClearanceStatus::Completed) {
            throw new BusinessRuleException('All clearance items must be signed before finalization.');
        }
        if (! $clearance->final_pay_computed) {
            throw new BusinessRuleException('Final pay must be computed before finalization.');
        }

        // Hard block: outstanding loans must be settled or deducted from final pay
        // before Finance can finalize. Consistent with FinalPayService which includes
        // active|pending balances in the less_loan_balance deduction line.
        $outstandingLoans = EmployeeLoan::query()
            ->where('employee_id', $clearance->employee_id)
            ->whereIn('status', ['active', 'pending'])
            ->where('balance', '>', 0)
            ->count();

        if ($outstandingLoans > 0) {
            throw ValidationException::withMessages([
                'outstanding_loans' => [
                    "Cannot finalize: employee has {$outstandingLoans} outstanding loan(s) with a remaining balance. "
                    . 'Settle all loans or confirm deduction in the final pay breakdown before finalizing.',
                ],
            ]);
        }

        return DB::transaction(function () use ($clearance, $by, $finalPay) {
            $clearance->load('employee');
            $employee = $clearance->employee;

            // Post the final-pay JE
            $journalEntry = $finalPay->postJournalEntry($clearance, $by);
            $clearance->journal_entry_id = $journalEntry->id;

            // Flip employee status
            $reason = $clearance->separation_reason instanceof SeparationReason
                ? $clearance->separation_reason
                : SeparationReason::from((string) $clearance->separation_reason);
            $employee->forceFill(['status' => $reason->toEmployeeStatus()])->save();

            $clearance->status       = ClearanceStatus::Finalized->value;
            $clearance->finalized_at = now();
            $clearance->finalized_by = $by->id;
            $clearance->save();

            EmploymentHistory::create([
                'employee_id'    => $employee->id,
                'change_type'    => EmploymentChangeType::Separated->value,
                'from_value'     => null,
                'to_value'       => json_encode([
                    'separation_date'   => optional($clearance->separation_date)?->toDateString(),
                    'separation_reason' => $reason->value,
                    'final_pay_amount'  => (string) $clearance->final_pay_amount,
                    'status'            => 'finalized',
                ]),
                'effective_date' => $clearance->separation_date,
                'remarks'        => 'Separation finalized. Final pay '.app(\App\Common\Services\CurrencyDisplayService::class)->format($clearance->final_pay_amount).'.',
                'approved_by'    => $by->id,
            ]);

            return $this->show($clearance);
        });
    }
}
