<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeOnboarding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U4 — orchestrates the 7-step new-hire onboarding workflow.
 * Steps 1 (profile) and 3 (leave balances) are auto-completed in
 * EmployeeService::create. Other steps are recomputed from underlying
 * data so the badge is always accurate without manual marking.
 */
class OnboardingService
{
    /** Idempotent: called from EmployeeService::create after the row exists. */
    public function initialize(Employee $employee): EmployeeOnboarding
    {
        return DB::transaction(function () use ($employee) {
            /** @var EmployeeOnboarding $onboarding */
            $onboarding = EmployeeOnboarding::firstOrNew(['employee_id' => $employee->id]);

            $onboarding->profile_completed_at ??= now();
            $onboarding->leave_balances_initialized_at ??= now();

            // Already-known fields from the freshly created employee.
            if ($this->hasGovIds($employee)) {
                $onboarding->gov_ids_recorded_at ??= now();
            }
            if ($this->hasBanking($employee)) {
                $onboarding->banking_recorded_at ??= now();
            }

            $onboarding->save();
            $this->maybeComplete($onboarding);

            return $onboarding->fresh();
        });
    }

    /**
     * Recompute every step from the canonical data sources. Source of truth.
     * Idempotent — safe to call any time the employee is updated.
     */
    public function recompute(Employee $employee): EmployeeOnboarding
    {
        $onboarding = EmployeeOnboarding::firstOrCreate(
            ['employee_id' => $employee->id],
            ['profile_completed_at' => now(), 'leave_balances_initialized_at' => now()],
        );

        // Step 2: shift assigned (if module exists).
        if (Schema::hasTable('employee_shift_assignments')) {
            $hasShift = DB::table('employee_shift_assignments')
                ->where('employee_id', $employee->id)
                ->exists();
            if ($hasShift && $onboarding->shift_assigned_at === null) {
                $onboarding->shift_assigned_at = now();
            }
        }

        // Step 4: account provisioned.
        if ($employee->user()->exists() && $onboarding->account_provisioned_at === null) {
            $onboarding->account_provisioned_at = now();
        }

        // Step 6 + 7: gov IDs, banking — derived from employee fields.
        if ($this->hasGovIds($employee) && $onboarding->gov_ids_recorded_at === null) {
            $onboarding->gov_ids_recorded_at = now();
        }
        if ($this->hasBanking($employee) && $onboarding->banking_recorded_at === null) {
            $onboarding->banking_recorded_at = now();
        }

        $onboarding->save();
        $this->maybeComplete($onboarding);

        return $onboarding->fresh();
    }

    public function markStep(Employee $employee, string $step): EmployeeOnboarding
    {
        abort_unless(in_array($step, EmployeeOnboarding::stepKeys(), true), 422, 'Unknown onboarding step.');

        $onboarding = EmployeeOnboarding::firstOrCreate(
            ['employee_id' => $employee->id],
            ['profile_completed_at' => now(), 'leave_balances_initialized_at' => now()],
        );

        $col = $step.'_at';
        if ($onboarding->{$col} === null) {
            $onboarding->{$col} = now();
            $onboarding->save();
            $this->maybeComplete($onboarding);
        }

        return $onboarding->fresh();
    }

    /**
     * Returns the ordered step list for SPA consumption.
     *
     * @return array{
     *   steps: array<int, array{key: string, label: string, completed_at: ?string}>,
     *   completed_at: ?string,
     *   is_complete: bool,
     * }
     */
    public function status(Employee $employee): array
    {
        $onboarding = $this->recompute($employee);
        $steps = [];
        foreach (EmployeeOnboarding::stepKeys() as $key) {
            $steps[] = [
                'key'          => $key,
                'label'        => EmployeeOnboarding::stepLabel($key),
                'completed_at' => optional($onboarding->{$key.'_at'})->toIso8601String(),
            ];
        }
        return [
            'steps'        => $steps,
            'completed_at' => optional($onboarding->completed_at)->toIso8601String(),
            'is_complete'  => $onboarding->isComplete(),
        ];
    }

    /**
     * Daily job — notify HR for any onboarding open > 3 days without completion.
     * Returns the number of reminders sent.
     */
    public function sendRemindersForStaleOnboardings(): int
    {
        $count = 0;
        $threshold = now()->subDays(3);

        $stale = EmployeeOnboarding::query()
            ->whereNull('completed_at')
            ->where('created_at', '<', $threshold)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('reminder_sent_at')
                  ->orWhere('reminder_sent_at', '<', $threshold);
            })
            ->with('employee')
            ->get();

        foreach ($stale as $onboarding) {
            // Send a notification to HR officers (best-effort; if NotificationService
            // is not available, just log it). We don't fail the job.
            try {
                if (class_exists(\App\Common\Services\NotificationService::class)) {
                    /** @var \App\Common\Services\NotificationService $svc */
                    $svc = app(\App\Common\Services\NotificationService::class);
                    if (method_exists($svc, 'notifyRole')) {
                        $svc->notifyRole('hr_officer', [
                            'title'   => 'Onboarding incomplete',
                            'message' => sprintf(
                                'Employee %s onboarding has been open for more than 3 days.',
                                $onboarding->employee?->full_name ?? '#'.$onboarding->employee_id,
                            ),
                            'link'    => '/hr/employees/'.($onboarding->employee?->hash_id ?? ''),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $onboarding->update(['reminder_sent_at' => now()]);
            $count++;
        }

        return $count;
    }

    private function maybeComplete(EmployeeOnboarding $onboarding): void
    {
        if ($onboarding->isComplete() && $onboarding->completed_at === null) {
            $onboarding->forceFill(['completed_at' => now()])->save();
        }
    }

    private function hasGovIds(Employee $employee): bool
    {
        return ! empty($employee->sss_no)
            && ! empty($employee->philhealth_no)
            && ! empty($employee->pagibig_no)
            && ! empty($employee->tin);
    }

    private function hasBanking(Employee $employee): bool
    {
        return ! empty($employee->bank_name) && ! empty($employee->bank_account_no);
    }
}
