<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentChangeType;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentHistory;
use App\Modules\HR\Support\EmployeeStateMachine;
use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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
 *   cancel()          reverts a pending/in-progress separation: clearance
 *                     → cancelled, employee restored to pre-initiation status
 *   finalize()        requires final pay computed; flips employee.status to
 *                     resigned/terminated/retired and stamps employment history.
 */
class SeparationService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly EmployeeStateMachine $stateMachine,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Clearance::query()->with([
            'employee:id,employee_no,first_name,last_name,department_id,position_id',
            'employee.department:id,name,code',
            'employee.position:id,title',
        ]);
        foreach (['status', 'separation_reason'] as $f) {
            if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        }

        // The SPA sends a HashID here. Passing it straight into a WHERE against
        // a bigint column is a 22P02 invalid-input error, so decode first and
        // skip the clause when the value cannot be resolved.
        if (! empty($filters['employee_id'])) {
            $employeeId = HashIdFilter::decode($filters['employee_id'], Employee::class);
            if ($employeeId === null) {
                $q->whereRaw('1 = 0');
            } else {
                $q->where('employee_id', $employeeId);
            }
        }

        // The list page ships a Search box that sent `search` to an endpoint
        // which ignored it, so the control silently did nothing.
        if (! empty($filters['search']) && is_string($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['search'])).'%';
            $q->where(function ($sub) use ($term) {
                $sub->where('clearance_no', 'ilike', $term)
                    ->orWhereHas('employee', function ($emp) use ($term) {
                        $emp->where('employee_no', 'ilike', $term)
                            ->orWhere('first_name', 'ilike', $term)
                            ->orWhere('last_name', 'ilike', $term)
                            ->orWhereRaw("(first_name || ' ' || last_name) ilike ?", [$term]);
                    });
            });
        }

        // paginate(0) returns every row and a negative value throws, so an
        // unvalidated page size was both a contract and a load problem.
        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 100));

        return $q->orderByDesc('id')->paginate($perPage);
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

            // clearances.separation_date is load-bearing outside this module:
            // PayrollCalculatorService::employedDayFraction() reads the EARLIEST
            // separation date on record and prorates basic pay by the days it
            // covers. A date before the hire date makes that window empty, so
            // the fraction collapses to 0.0000 and every later cutoff pays zero
            // basic pay — and because no cancel/correct transition exists, a
            // mistyped year could not be walked back through the API.
            $separationDate = Carbon::parse((string) $data['separation_date'])->startOfDay();
            $hireDate = $lockedEmployee->date_hired;

            if ($hireDate && $separationDate->lt($hireDate->copy()->startOfDay())) {
                throw new BusinessRuleException(
                    'Separation date '.$separationDate->toDateString().' precedes the hire date '
                    .$hireDate->toDateString().'. The separation was not initiated.'
                );
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
                'remarks'           => $data['remarks'] ?? null,
            ]);

            $fromStatus = $lockedEmployee->status instanceof EmployeeStatus
                ? $lockedEmployee->status->value
                : (string) $lockedEmployee->getRawOriginal('status');
            $this->stateMachine->transition($lockedEmployee, EmployeeStatus::OnLeave);

            EmploymentHistory::create([
                'employee_id'    => $lockedEmployee->id,
                'change_type'    => EmploymentChangeType::Separated->value,
                'from_value'     => ['status' => $fromStatus],
                // EmploymentHistory casts both value columns to 'array'.
                // json_encode()-ing first double-encodes, so the row reads back
                // as a JSON *string* while from_value reads back as an array —
                // and EmploymentHistoryResource masks assuming array shape.
                'to_value'       => [
                    'separation_date'   => (string) $data['separation_date'],
                    'separation_reason' => $reason->value,
                    'status'            => 'in_progress',
                ],
                'effective_date' => $data['separation_date'],
                'remarks'        => ($data['remarks'] ?? null)
                    ?: 'Separation initiated. Clearance '.$clearance->clearance_no.'.',
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
        return self::validateChecklist($this->settings->get('hr.separation.clearance_checklist'));
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
        return self::validateChecklist(app(SettingsService::class)->get('hr.separation.clearance_checklist'));
    }

    /**
     * One validator for both readers.
     *
     * item_key uniqueness is a completion invariant, not cosmetics. signItem()
     * matches the FIRST row with a given key and treats an already-cleared
     * match as a replayed no-op, so a duplicated key leaves the later row
     * permanently pending. Completion requires EVERY row cleared, so the
     * clearance can never reach `completed`, can never be finalized, and the
     * employee can never be separated or paid final pay — with no cancel
     * transition to escape. Blank keys/labels/departments are unroutable and
     * unrenderable for the same reason.
     *
     * @return array<int, array{department:string,item_key:string,label:string}>
     */
    private static function validateChecklist(mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            throw new BusinessRuleException('Separation clearance checklist is not configured. Configure hr.separation.clearance_checklist before initiating a separation.');
        }

        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['department'], $item['item_key'], $item['label'])) {
                throw new BusinessRuleException('Separation clearance checklist contains an invalid item.');
            }

            foreach (['department', 'item_key', 'label'] as $field) {
                if (! is_string($item[$field]) || trim($item[$field]) === '') {
                    throw new BusinessRuleException(
                        "Separation clearance checklist has an item with a blank or non-string {$field}."
                    );
                }
            }

            $key = $item['item_key'];
            if (isset($seen[$key])) {
                throw new BusinessRuleException(
                    "Separation clearance checklist has a duplicate item_key '{$key}'. "
                    .'Duplicate keys produce a clearance that can never be completed.'
                );
            }
            $seen[$key] = true;
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

                    // Per-department signing gate (HR-03). Runs before the
                    // replay no-op so an outsider cannot even probe the state
                    // of another department's item.
                    $this->assertMaySignItem($item, $by);

                    // Replayed sign requests are safe no-ops. Preserve the
                    // original signer and timestamp instead of rewriting an
                    // already authoritative checklist decision.
                    if (($item['status'] ?? '') === 'cleared') {
                        unset($item);
                        return $this->show($lockedClearance);
                    }

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

    /**
     * HR-04 — the only backwards transition out of an initiated separation.
     * A rescinded resignation or a mistyped separation date otherwise leaves
     * the employee stuck: initiate() refuses a second clearance and the only
     * way out was forward through every signature to final pay.
     */
    public function cancel(Clearance $clearance, User $by, ?string $reason = null): Clearance
    {
        return DB::transaction(function () use ($clearance, $by, $reason) {
            $lockedClearance = Clearance::query()
                ->lockForUpdate()
                ->find($clearance->id);

            if (! $lockedClearance) {
                throw new BusinessRuleException('Clearance not found.');
            }
            if ($lockedClearance->status === ClearanceStatus::Cancelled) {
                throw new BusinessRuleException('Clearance is already cancelled.');
            }
            if ($lockedClearance->status !== ClearanceStatus::Pending
                && $lockedClearance->status !== ClearanceStatus::InProgress) {
                throw new BusinessRuleException(
                    'Only pending or in-progress clearances can be cancelled. '
                    .'Completed or finalized separations require a different correction.'
                );
            }
            if ($lockedClearance->final_pay_computed) {
                throw new BusinessRuleException(
                    'Clearance can no longer be cancelled: final pay has already been computed.'
                );
            }

            $employee = Employee::query()
                ->lockForUpdate()
                ->find($lockedClearance->employee_id);

            if (! $employee) {
                throw new BusinessRuleException('Clearance employee not found.');
            }

            // initiate() stamps the employee's pre-separation status onto the
            // history row it writes. Restore exactly that value — not a hard
            // "active" — so a suspended employee is not silently reactivated.
            $initiation = EmploymentHistory::query()
                ->where('employee_id', $employee->id)
                ->where('change_type', EmploymentChangeType::Separated->value)
                ->orderByDesc('id')
                ->get()
                ->first(fn (EmploymentHistory $row) => ($row->to_value['status'] ?? null) === 'in_progress');

            $fromStatus = $employee->status instanceof EmployeeStatus
                ? $employee->status->value
                : (string) $employee->getRawOriginal('status');
            $priorStatus = EmployeeStatus::from(
                (string) ($initiation?->from_value['status'] ?? EmployeeStatus::Active->value)
            );

            $this->stateMachine->transition($employee, $priorStatus);

            // No cancelled_by/cancelled_at columns exist on clearances; the
            // actor lands in the audit log and the reason in remarks.
            $note = 'Separation cancelled by '.$by->name
                .($reason !== null && trim($reason) !== '' ? ': '.trim($reason) : '');
            $existingRemarks = trim((string) ($lockedClearance->remarks ?? ''));
            $lockedClearance->status  = ClearanceStatus::Cancelled->value;
            $lockedClearance->remarks = $existingRemarks === '' ? $note : $existingRemarks."\n".$note;
            $lockedClearance->save();

            EmploymentHistory::create([
                'employee_id'    => $employee->id,
                'change_type'    => EmploymentChangeType::Separated->value,
                'from_value'     => ['status' => $fromStatus],
                'to_value'       => [
                    'separation_date'   => optional($lockedClearance->separation_date)?->toDateString(),
                    'separation_reason' => $lockedClearance->separation_reason instanceof SeparationReason
                        ? $lockedClearance->separation_reason->value
                        : (string) $lockedClearance->separation_reason,
                    'status'            => 'cancelled',
                ],
                'effective_date' => now()->toDateString(),
                'remarks'        => 'Separation cancelled. Clearance '.$lockedClearance->clearance_no.'.',
                'approved_by'    => $by->id,
            ]);

            return $this->show($lockedClearance);
        });
    }

    /**
     * Per-department signing gate (HR-03). The flat hr.clearance.sign
     * permission — checked by the route middleware — only answers whether a
     * role signs clearance items at all; this check answers WHICH items. A
     * signer may clear an item only when their own employee belongs to the
     * department that owns it. hr_officer and system_admin remain fallback
     * signers for every department so no item can become unsignable.
     *
     * @param  array<string, mixed>  $item
     */
    private function assertMaySignItem(array $item, User $by): void
    {
        if (in_array($by->role?->slug, ['hr_officer', 'system_admin'], true)) {
            return;
        }

        $label = trim((string) ($item['department'] ?? ''));
        $owner = $this->resolveChecklistDepartment($label);
        $actorDepartmentId = $by->employee?->department_id;

        if ($owner === null || $actorDepartmentId === null || (int) $owner->id !== (int) $actorDepartmentId) {
            throw new BusinessRuleException(
                "Clearance item '{$item['item_key']}' belongs to the {$label} department. "
                .'Only a member of that department, an HR Officer, or a System Administrator may sign it.'
            );
        }
    }

    /**
     * Resolves a checklist item's department label against the departments
     * table. An exact (case-insensitive) code or name match wins; otherwise a
     * UNIQUE name prefix is accepted ("Warehouse" → "Warehouse & Logistics").
     * Ambiguous or unknown labels resolve to null, which restricts the item
     * to the hr_officer / system_admin fallback signers.
     */
    public function resolveChecklistDepartment(string $label): ?Department
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        $escaped = str_replace(['%', '_'], ['\%', '\_'], $label);

        $exact = Department::query()
            ->where(fn ($q) => $q->where('code', 'ilike', $escaped)->orWhere('name', 'ilike', $escaped))
            ->get();

        if ($exact->count() === 1) {
            return $exact->first();
        }
        if ($exact->count() > 1) {
            return null;
        }

        $prefix = Department::query()->where('name', 'ilike', $escaped.'%')->get();

        return $prefix->count() === 1 ? $prefix->first() : null;
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

            // Flip employee status through the same transition map used by
            // initiation so terminal states cannot be reopened by replay.
            $reason = $lockedClearance->separation_reason instanceof SeparationReason
                ? $lockedClearance->separation_reason
                : SeparationReason::from((string) $lockedClearance->separation_reason);
            $fromStatus = $employee->status instanceof EmployeeStatus
                ? $employee->status->value
                : (string) $employee->getRawOriginal('status');
            $targetStatus = EmployeeStatus::from($reason->toEmployeeStatus());
            $this->stateMachine->transition($employee, $targetStatus);

            $lockedClearance->status       = ClearanceStatus::Finalized->value;
            $lockedClearance->finalized_at = now();
            $lockedClearance->finalized_by = $by->id;
            $lockedClearance->save();

            EmploymentHistory::create([
                'employee_id'    => $employee->id,
                'change_type'    => EmploymentChangeType::Separated->value,
                'from_value'     => ['status' => $fromStatus],
                'to_value'       => [
                    'separation_date'   => optional($lockedClearance->separation_date)?->toDateString(),
                    'separation_reason' => $reason->value,
                    'final_pay_amount'  => (string) $lockedClearance->final_pay_amount,
                    'status'            => 'finalized',
                ],
                'effective_date' => $lockedClearance->separation_date,
                'remarks'        => 'Separation finalized. Final pay '.app(\App\Common\Services\CurrencyDisplayService::class)->format($lockedClearance->final_pay_amount).'.',
                'approved_by'    => $by->id,
            ]);

            return $this->show($lockedClearance);
        });
    }
}
