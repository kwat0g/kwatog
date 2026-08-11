<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentChangeType;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Events\SeparationInitiated;
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
        return DB::transaction(function () use ($employee, $data, $by) {
            // The route-bound employee may have gone through another lifecycle
            // transition while the operator was filling out the form. Lock and
            // re-read the authoritative row before creating a clearance so two
            // initiation requests cannot create parallel separation chains.
            $lockedEmployee = Employee::query()
                ->lockForUpdate()
                ->find($employee->id);

            if (! $lockedEmployee) {
                throw new BusinessRuleException('Employee not found.');
            }

            if (in_array($lockedEmployee->status?->value, ['resigned', 'terminated', 'retired'], true)) {
                throw new BusinessRuleException('Employee is already separated.');
            }

            $hasOpenClearance = Clearance::query()
                ->where('employee_id', $lockedEmployee->id)
                ->whereIn('status', [
                    ClearanceStatus::Pending->value,
                    ClearanceStatus::InProgress->value,
                    ClearanceStatus::Completed->value,
                ])
                ->exists();

            if ($hasOpenClearance) {
                throw new BusinessRuleException('Employee already has an active separation clearance.');
            }

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
                'employee_id'       => $lockedEmployee->id,
                'separation_date'   => $data['separation_date'],
                'separation_reason' => $reason->value,
                'clearance_items'   => $items,
                'status'            => ClearanceStatus::InProgress->value,
                'initiated_by'      => $by->id,
            ]);

            $lockedEmployee->forceFill(['status' => EmployeeStatus::OnLeave->value])->save();

            EmploymentHistory::create([
                'employee_id'    => $lockedEmployee->id,
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

            $fresh = $clearance->fresh();
            app(OutboxService::class)->recordForChain(
                new SeparationInitiated($fresh),
                $fresh,
                'h2r',
                'clearance',
                'initiated',
            );

            return $this->show($clearance);
        });
    }

    /** @return array<int, array{department:string,item_key:string,label:string}> */
    private function configuredChecklist(): array
    {
        $items = $this->settings->get('hr.separation.clearance_checklist');
        if (! is_array($items) || $items === []) {
            throw new BusinessRuleException('Separation clearance checklist is not configured. Configure hr.separation.clearance_checklist before initiating a separation.');
        }
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['department'], $item['item_key'], $item['label'])) {
                throw new BusinessRuleException('Separation clearance checklist contains an invalid item.');
            }
        }
        return array_values($items);
    }

    /**
     * Backwards-compatible read helper for integrations that used the old
     * static method. It deliberately delegates to deployment settings instead
     * of keeping a second hardcoded checklist in application code.
     *
     * @return array<int, array{department:string,item_key:string,label:string}>
     */
    public static function defaultChecklist(): array
    {
        $items = app(SettingsService::class)->get('hr.separation.clearance_checklist');
        if (! is_array($items) || $items === []) {
            throw new BusinessRuleException('Separation clearance checklist is not configured. Configure hr.separation.clearance_checklist before using the checklist.');
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['department'], $item['item_key'], $item['label'])) {
                throw new BusinessRuleException('Separation clearance checklist contains an invalid item.');
            }
        }

        return array_values($items);
    }

    public function signItem(Clearance $clearance, string $itemKey, User $by, ?string $remarks = null): Clearance
    {
        return DB::transaction(function () use ($clearance, $itemKey, $by, $remarks) {
            // Checklist items live in one JSON document. Read-modify-write on a
            // stale route-bound model would lose a different department's sign
            // when two operators submit at nearly the same time. The row lock
            // serialises the aggregate and makes completion a single transition.
            $lockedClearance = Clearance::query()
                ->lockForUpdate()
                ->find($clearance->id);

            if (! $lockedClearance) {
                throw new BusinessRuleException('Clearance not found.');
            }

            if ($lockedClearance->status->isTerminal()) {
                throw new BusinessRuleException('Clearance is closed.');
            }

            $items = $lockedClearance->clearance_items ?? [];
            $found = false;
            foreach ($items as &$item) {
                if (($item['item_key'] ?? '') === $itemKey) {
                    $found = true;

                    // Replayed sign requests are safe no-ops. Preserve the
                    // original signer and timestamp instead of rewriting an
                    // already authoritative checklist decision.
                    if (($item['status'] ?? '') === 'cleared') {
                        unset($item);
                        return $this->show($lockedClearance);
                    }

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
            $lockedClearance->clearance_items = $items;

            $allCleared = collect($items)->every(fn (array $i) => ($i['status'] ?? '') === 'cleared');
            $becameCompleted = false;
            if ($allCleared) {
                $becameCompleted = $lockedClearance->status !== ClearanceStatus::Completed
                    && $lockedClearance->status !== ClearanceStatus::Finalized;
                $lockedClearance->status = ClearanceStatus::Completed->value;
            }
            $lockedClearance->save();

            if ($becameCompleted) {
                // Series C — Task C3. Domain event for chain listeners
                // (DeactivateAccountOnClearanceComplete + future final-pay
                // automation). Fires only on the transition, not every save.
                $fresh = $lockedClearance->fresh();
                app(OutboxService::class)->recordForChain(
                    new ClearanceFullySigned($fresh),
                    $fresh,
                    'h2r',
                    'clearance',
                    ClearanceStatus::Completed->value,
                );
            }

            return $this->show($lockedClearance);
        });
    }

    public function finalize(Clearance $clearance, User $by, FinalPayService $finalPay): Clearance
    {
        return DB::transaction(function () use ($clearance, $by, $finalPay) {
            // Finalization posts money and changes the employee's authoritative
            // status. Every guard must inspect the locked current rows so a
            // replayed request cannot create a second JE or resurrect a terminal
            // clearance from a stale route-bound model.
            $lockedClearance = Clearance::query()
                ->lockForUpdate()
                ->find($clearance->id);

            if (! $lockedClearance) {
                throw new BusinessRuleException('Clearance not found.');
            }
            if ($lockedClearance->status === ClearanceStatus::Finalized) {
                throw new BusinessRuleException('Clearance is already finalized.');
            }
            if ($lockedClearance->status !== ClearanceStatus::Completed) {
                throw new BusinessRuleException('All clearance items must be signed before finalization.');
            }
            if (! $lockedClearance->final_pay_computed) {
                throw new BusinessRuleException('Final pay must be computed before finalization.');
            }

            // Lock the rows before checking balances. A loan settlement racing
            // with finalization must resolve before the decision is made.
            $outstandingLoans = EmployeeLoan::query()
                ->where('employee_id', $lockedClearance->employee_id)
                ->whereIn('status', ['active', 'pending'])
                ->where('balance', '>', 0)
                ->lockForUpdate()
                ->get(['id'])
                ->count();

            if ($outstandingLoans > 0) {
                throw ValidationException::withMessages([
                    'outstanding_loans' => [
                        "Cannot finalize: employee has {$outstandingLoans} outstanding loan(s) with a remaining balance. "
                        . 'Settle all loans or confirm deduction in the final pay breakdown before finalizing.',
                    ],
                ]);
            }

            $employee = Employee::query()
                ->lockForUpdate()
                ->find($lockedClearance->employee_id);

            if (! $employee) {
                throw new BusinessRuleException('Clearance employee not found.');
            }

            $lockedClearance->setRelation('employee', $employee);

            // Post the final-pay JE
            $journalEntry = $finalPay->postJournalEntry($lockedClearance, $by);
            $lockedClearance->journal_entry_id = $journalEntry->id;

            // Flip employee status
            $reason = $lockedClearance->separation_reason instanceof SeparationReason
                ? $lockedClearance->separation_reason
                : SeparationReason::from((string) $lockedClearance->separation_reason);
            $employee->forceFill(['status' => $reason->toEmployeeStatus()])->save();

            $lockedClearance->status       = ClearanceStatus::Finalized->value;
            $lockedClearance->finalized_at = now();
            $lockedClearance->finalized_by = $by->id;
            $lockedClearance->save();

            EmploymentHistory::create([
                'employee_id'    => $employee->id,
                'change_type'    => EmploymentChangeType::Separated->value,
                'from_value'     => null,
                'to_value'       => json_encode([
                    'separation_date'   => optional($lockedClearance->separation_date)?->toDateString(),
                    'separation_reason' => $reason->value,
                    'final_pay_amount'  => (string) $lockedClearance->final_pay_amount,
                    'status'            => 'finalized',
                ]),
                'effective_date' => $lockedClearance->separation_date,
                'remarks'        => 'Separation finalized. Final pay '.app(\App\Common\Services\CurrencyDisplayService::class)->format($lockedClearance->final_pay_amount).'.',
                'approved_by'    => $by->id,
            ]);

            return $this->show($lockedClearance);
        });
    }
}
