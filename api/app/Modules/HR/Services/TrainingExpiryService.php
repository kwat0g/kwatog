<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Enums\TrainingAlertLevel;
use App\Modules\HR\Models\EmployeeTraining;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * T3.4.C — Tiered training-expiry alerts.
 *
 * Fires at most one alert per record per tier. Tier ordering:
 *   t30 < t14 < t7 < expired   (severity ascending)
 *
 * The tier table is walked ascending and the most-severe match is kept,
 * so a row 1 day from expiry maps to `t7`, not `t30`. A row past expiry
 * (days_until <= 0) maps to `expired` and the record's status is flipped
 * to `expired` for downstream reporting.
 */
class TrainingExpiryService
{
    /**
     * Threshold table — ordered ascending by severity. The check loop walks
     * the table and keeps the most-severe tier whose threshold is met.
     *
     * @var list<array{days:int, level:TrainingAlertLevel}>
     */
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Scan completed training records whose expiry is within the configured
     * first-reminder horizon (or past),
     * and fire any tier alert that has not yet been fired for that record.
     *
     * @return array{evaluated:int, alerts_sent:int, expired_marked:int}
     */
    public function check(): array
    {
        $today = now()->startOfDay();
        $tiers = $this->tiers();
        $horizon = $today->copy()->addDays(max(array_column($tiers, 'days')))->toDateString();

        $rows = EmployeeTraining::query()
            ->with(['employee.department', 'training'])
            ->where('status', EmployeeTrainingStatus::Completed->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $horizon)
            ->get();

        $alertsSent    = 0;
        $expiredMarked = 0;

        foreach ($rows as $r) {
            // Carbon::diffInDays(other, false) returns SIGNED integer days
            // from $today to $expiresAt. Past-due → negative.
            $expires   = Carbon::parse($r->expires_at)->startOfDay();
            $daysUntil = (int) $today->diffInDays($expires, false);

            $tier = null;
            foreach ($tiers as $candidate) {
                if ($daysUntil <= $candidate['days']) {
                    $tier = $candidate;
                }
            }
            if ($tier === null) {
                continue;
            }

            if ($this->alreadyFired($r, $tier['level'])) {
                continue;
            }

            $this->notify($r, $tier['level'], $tier['days']);

            $update = [
                'last_alert_level' => $tier['level']->value,
                'last_alert_at'    => now(),
            ];
            if ($tier['level'] === TrainingAlertLevel::Expired) {
                $update['status'] = EmployeeTrainingStatus::Expired->value;
                $expiredMarked++;
            }
            $r->forceFill($update)->save();
            $alertsSent++;
        }

        return [
            'evaluated'      => $rows->count(),
            'alerts_sent'    => $alertsSent,
            'expired_marked' => $expiredMarked,
        ];
    }

    /**
     * Tier ordinals: t30=1, t14=2, t7=3, expired=4. A row already at-or-above
     * the candidate tier should NOT re-fire — same-day re-runs and no-change
     * scenarios are idempotent.
     */
    private function alreadyFired(EmployeeTraining $r, TrainingAlertLevel $candidate): bool
    {
        $current = $r->last_alert_level;
        if ($current === null) {
            return false;
        }
        return $current->ordinal() >= $candidate->ordinal();
    }

    private function notify(EmployeeTraining $r, TrainingAlertLevel $level, int $thresholdDays): void
    {
        $recipients = $this->resolveRecipients($r);
        if ($recipients->isEmpty()) {
            return;
        }

        $training   = $r->training;
        $employee   = $r->employee;
        $expiresStr = Carbon::parse($r->expires_at)->toDateString();
        $name       = $training?->name ?? 'Training';

        [$title, $message] = match ($level) {
            TrainingAlertLevel::T30 => [
                "Training expiry reminder: {$name}",
                "{$employee?->full_name} — {$name} expires on {$expiresStr} ({$thresholdDays} days).",
            ],
            TrainingAlertLevel::T14 => [
                "Training expiring soon: {$name}",
                "{$employee?->full_name} — {$name} expires on {$expiresStr} ({$thresholdDays} days).",
            ],
            TrainingAlertLevel::T7 => [
                "Training expiring urgently: {$name}",
                "{$employee?->full_name} — {$name} expires on {$expiresStr} ({$thresholdDays} days).",
            ],
            TrainingAlertLevel::Expired => [
                "Training overdue: {$name}",
                "{$employee?->full_name} — {$name} expired on {$expiresStr}.",
            ],
        };

        $this->notifications->send($recipients, 'training.expiry', [
            'title'       => $title,
            'message'     => $message,
            'entity_type' => 'employee_training',
            'entity_id'   => $r->hash_id,
            'link_to'     => $employee ? "/hr/employees/{$employee->hash_id}" : null,
        ]);
    }

    /** @return list<array{days:int,level:TrainingAlertLevel}> */
    private function tiers(): array
    {
        $t30 = $this->settings->requiredInt('hr.training_expiry.t30_days', 1);
        $t14 = $this->settings->requiredInt('hr.training_expiry.t14_days', 1);
        $t7 = $this->settings->requiredInt('hr.training_expiry.t7_days', 1);
        if (! ($t30 > $t14 && $t14 > $t7)) {
            throw new \App\Common\Exceptions\BusinessRuleException('Training expiry reminder thresholds must be strictly descending.');
        }
        return [
            ['days' => $t30, 'level' => TrainingAlertLevel::T30],
            ['days' => $t14, 'level' => TrainingAlertLevel::T14],
            ['days' => $t7, 'level' => TrainingAlertLevel::T7],
            ['days' => 0, 'level' => TrainingAlertLevel::Expired],
        ];
    }

    /**
     * Recipients (union, deduped by user id):
     *   (a) The User row whose users.employee_id = employee.id (if any).
     *   (b) All active users with role `department_head` whose employee's
     *       department_id matches the record's employee.department_id.
     *   (c) All active users with role `hr_officer`.
     *
     * @return Collection<int, User>
     */
    private function resolveRecipients(EmployeeTraining $r): Collection
    {
        $users = collect();

        // (a) The employee's own user, if provisioned.
        $empUser = User::query()
            ->where('employee_id', $r->employee_id)
            ->where('is_active', true)
            ->first();
        if ($empUser) {
            $users->push($empUser);
        }

        $roles = array_values(array_filter((array) $this->settings->get('hr.training_expiry.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        $roleIds = Role::query()->whereIn('slug', $roles)->pluck('id', 'slug');
        $deptHeadRoleId  = $roleIds->get('department_head');
        $hrOfficerRoleId = $roleIds->get('hr_officer');
        $deptId          = $r->employee?->department_id;

        // (b) Department heads of the employee's department.
        if ($deptHeadRoleId && $deptId) {
            $heads = User::query()
                ->where('role_id', $deptHeadRoleId)
                ->where('is_active', true)
                ->whereHas('employee', fn($q) => $q->where('department_id', $deptId))
                ->get();
            $users = $users->concat($heads);
        }

        // (c) All HR officers (org-wide).
        if ($hrOfficerRoleId) {
            $hrs = User::query()
                ->where('role_id', $hrOfficerRoleId)
                ->where('is_active', true)
                ->get();
            $users = $users->concat($hrs);
        }

        return $users->unique('id')->values();
    }
}
