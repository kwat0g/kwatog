<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Services\ActivityFeedService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeOnboarding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * U4 — orchestrates the 7-step new-hire onboarding workflow.
 * Steps 1 (profile) and 3 (leave balances) are auto-completed in
 * EmployeeService::create. Other steps are recomputed from underlying
 * data so the badge is always accurate without manual marking.
 */
class OnboardingService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly ActivityFeedService $activity,
    ) {}

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
        return DB::transaction(function () use ($employee): EmployeeOnboarding {
            $onboarding = $this->lockedTracker($employee);

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
        });
    }

    /**
     * HR attestation for the one onboarding step that has no canonical source
     * table. The endpoint deliberately has no generic step argument: callers
     * cannot mark a derived or future step by guessing a column name.
     */
    public function markDepartmentTeamNotified(Employee $employee, User $actor): EmployeeOnboarding
    {
        if (! $actor->is_active || ! $actor->hasPermission('hr.employees.edit')) {
            throw new ForbiddenActionException('Only an active HR editor may attest that the department team was notified.');
        }

        return DB::transaction(function () use ($employee, $actor): EmployeeOnboarding {
            $onboarding = $this->lockedTracker($employee);
            $onboarding->dept_team_notified_at ??= now();
            $onboarding->save();
            $this->maybeComplete($onboarding);

            $this->activity->record(
                type: 'hr',
                action: 'onboarding_department_team_notified',
                subject: $employee,
                summary: "Department team notified for {$employee->full_name}.",
                detail: [
                    'employee_id' => $employee->id,
                    'step' => 'dept_team_notified',
                    'completed_at' => $onboarding->dept_team_notified_at?->toIso8601String(),
                ],
                link: "/hr/employees/{$employee->hash_id}",
                severity: 'success',
                idempotencyKey: "hr.onboarding.dept_team_notified:{$employee->id}",
                actorUserId: (int) $actor->id,
                actorType: 'user',
            );

            return $onboarding->fresh();
        });
    }

    /**
     * Reconciles canonical employee data before returning the ordered step list.
     * The reconciliation is transactional so the existing read contract stays
     * current after employee edits that do not themselves touch this tracker.
     *
     * @return array{
     *   steps: array<int, array{key: string, label: string, completed_at: ?string}>,
     *   completed_at: ?string,
     *   is_complete: bool,
     * }
     */
    public function status(Employee $employee): array
    {
        return $this->recomputeStatus($employee);
    }

    /**
     * Recompute once and project that same tracker for a write endpoint.
     *
     * @return array{
     *   steps: array<int, array{key: string, label: string, completed_at: ?string}>,
     *   completed_at: ?string,
     *   is_complete: bool,
     * }
     */
    public function recomputeStatus(Employee $employee): array
    {
        return $this->projectStatus($this->recompute($employee));
    }

    /**
     * Daily job — notify the configured HR audience for any onboarding open
     * beyond the configured stale window without completion.
     * Returns the number of reminders durably dispatched.
     */
    public function sendRemindersForStaleOnboardings(): int
    {
        $count = 0;
        $staleDays = $this->settings->requiredInt('hr.onboarding.stale_days', 1, 365);
        $threshold = now()->subDays($staleDays);

        $staleIds = EmployeeOnboarding::query()
            ->whereNull('completed_at')
            ->where('created_at', '<', $threshold)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('reminder_sent_at')
                    ->orWhere('reminder_sent_at', '<', $threshold);
            })
            ->pluck('id');

        foreach ($staleIds as $onboardingId) {
            try {
                $sent = DB::transaction(function () use ($onboardingId, $staleDays, $threshold): bool {
                    /** @var EmployeeOnboarding|null $onboarding */
                    $onboarding = EmployeeOnboarding::query()
                        ->with('employee')
                        ->lockForUpdate()
                        ->find($onboardingId);

                    // A second scheduler worker may have handled this row after
                    // the candidate query. Re-check under the row lock.
                    if ($onboarding === null
                        || $onboarding->completed_at !== null
                        || ($onboarding->reminder_sent_at !== null && ! $onboarding->reminder_sent_at->lt($threshold))) {
                        return false;
                    }

                    $audience = $this->reminderAudience();
                    if ($audience->isEmpty()) {
                        // Leave reminder_sent_at null so an operator can add an
                        // HR recipient and the next run retries this row.
                        Log::warning('Onboarding reminder skipped because its audience is empty.', [
                            'onboarding_id' => $onboarding->id,
                            'employee_id' => $onboarding->employee_id,
                        ]);
                        return false;
                    }

                    $employee = $onboarding->employee;
                    $notification = [
                        'title' => 'Onboarding incomplete',
                        'message' => sprintf(
                            'Employee %s onboarding has been open for more than %d days.',
                            $employee?->full_name ?? '#'.$onboarding->employee_id,
                            $staleDays,
                        ),
                        'entity_type' => 'employee',
                        'entity_id' => $employee?->hash_id,
                    ];
                    if ($employee !== null) {
                        $notification['link_to'] = "/hr/employees/{$employee->hash_id}";
                    }

                    // NotificationService inserts the in-app row in this same
                    // transaction. Any delivery/database exception therefore
                    // rolls back both the notification and the sent marker.
                    $this->notifications->sendInApp($audience, 'hr.onboarding.stale', $notification);
                    $onboarding->update(['reminder_sent_at' => now()]);

                    return true;
                });

                if ($sent) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::warning('Onboarding reminder delivery failed; row remains retryable.', [
                    'onboarding_id' => $onboardingId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /** @return Collection<int, User> */
    private function reminderAudience(): Collection
    {
        $roles = array_values(array_unique(array_filter(
            (array) $this->settings->get('hr.onboarding.notification_roles', ['hr_officer', 'system_admin']),
            static fn ($role): bool => is_string($role) && trim($role) !== '',
        )));

        if ($roles === []) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        return User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
            ->where('is_active', true)
            ->get();
    }

    private function lockedTracker(Employee $employee): EmployeeOnboarding
    {
        /** @var EmployeeOnboarding $onboarding */
        $onboarding = EmployeeOnboarding::firstOrCreate(
            ['employee_id' => $employee->id],
            ['profile_completed_at' => now(), 'leave_balances_initialized_at' => now()],
        );

        return EmployeeOnboarding::query()
            ->lockForUpdate()
            ->findOrFail($onboarding->id);
    }

    /**
     * @return array{
     *   steps: array<int, array{key: string, label: string, completed_at: ?string}>,
     *   completed_at: ?string,
     *   is_complete: bool,
     * }
     */
    private function projectStatus(?EmployeeOnboarding $onboarding): array
    {
        $steps = [];
        foreach (EmployeeOnboarding::stepKeys() as $key) {
            $completedAt = $onboarding === null ? null : $onboarding->{$key.'_at'};
            $steps[] = [
                'key' => $key,
                'label' => EmployeeOnboarding::stepLabel($key),
                'completed_at' => $completedAt?->toIso8601String(),
            ];
        }

        return [
            'steps' => $steps,
            'completed_at' => $onboarding?->completed_at?->toIso8601String(),
            'is_complete' => $onboarding?->isComplete() ?? false,
        ];
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
