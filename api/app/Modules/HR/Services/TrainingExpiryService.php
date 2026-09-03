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
use App\Modules\HR\Models\TrainingExpiryAlertDelivery;
use App\Modules\HR\Support\EmployeeTrainingStateMachine;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Tiered training-expiry alerts with durable, retryable delivery claims. */
class TrainingExpiryService
{
    /** @var list<array{days:int, level:TrainingAlertLevel}> */
    private array $tierCache = [];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
        private readonly EmployeeTrainingStateMachine $stateMachine,
    ) {}

    /** @return array{evaluated:int, alerts_sent:int, expired_marked:int} */
    public function check(): array
    {
        $today = now()->startOfDay();
        $tiers = $this->tiers();
        $horizon = $today->copy()->addDays(max(array_column($tiers, 'days')))->toDateString();

        // Recertification policy (2026-09-04): a retake creates a NEW record,
        // so an employee can hold several completed rows for one training.
        // Only the newest completion drives expiry alerts — an older
        // (superseded) cert must not fire expiry alarms while a newer one is
        // current, and once the newest lapses it is the row that alerts.
        $rows = EmployeeTraining::query()
            ->from('employee_trainings as et')
            ->where('et.status', EmployeeTrainingStatus::Completed->value)
            ->whereNotNull('et.expires_at')
            ->where('et.expires_at', '<=', $horizon)
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('employee_trainings as newer')
                    ->whereColumn('newer.employee_id', 'et.employee_id')
                    ->whereColumn('newer.training_id', 'et.training_id')
                    ->where('newer.status', EmployeeTrainingStatus::Completed->value)
                    ->whereColumn('newer.id', '>', 'et.id');
            })
            ->get(['et.id']);

        $alertsSent = 0;
        $expiredMarked = 0;

        foreach ($rows as $row) {
            $result = DB::transaction(fn (): array => $this->processRow($row->id, $today, $tiers));
            if ($result['alert_sent']) {
                $alertsSent++;
            }
            if ($result['expired_marked']) {
                $expiredMarked++;
            }
        }

        return [
            'evaluated' => $rows->count(),
            'alerts_sent' => $alertsSent,
            'expired_marked' => $expiredMarked,
        ];
    }

    /** @param list<array{days:int, level:TrainingAlertLevel}> $tiers */
    private function processRow(int $recordId, Carbon $today, array $tiers): array
    {
        $record = EmployeeTraining::query()
            ->with(['employee', 'employee.department', 'training'])
            ->lockForUpdate()
            ->find($recordId);

        if ($record === null || $record->status !== EmployeeTrainingStatus::Completed || $record->expires_at === null) {
            return ['alert_sent' => false, 'expired_marked' => false];
        }

        $expires = Carbon::parse($record->expires_at)->startOfDay();
        $daysUntil = (int) $today->diffInDays($expires, false);
        $tier = null;
        foreach ($tiers as $candidate) {
            if ($daysUntil <= $candidate['days']) {
                $tier = $candidate;
            }
        }
        if ($tier === null || $this->alreadyFired($record, $tier['level'])) {
            return ['alert_sent' => false, 'expired_marked' => false];
        }

        $delivery = $this->deliveryTargets($record);
        if ($delivery['targets'] === []) {
            // There is no durable channel to claim. Leave the marker alone so
            // a later run can retry after a recipient or preference changes.
            return ['alert_sent' => false, 'expired_marked' => false];
        }

        $this->claimDelivery($record, $tier['level'], $delivery['targets']);
        $this->notify($record, $tier['level'], $tier['days'], $delivery['users']);
        $this->completeDelivery($record, $tier['level'], $delivery['targets']);

        $expiredMarked = false;
        $update = [
            'last_alert_level' => $tier['level'],
            'last_alert_at' => now(),
        ];
        if ($tier['level'] === TrainingAlertLevel::Expired) {
            $this->stateMachine->transition($record, EmployeeTrainingStatus::Expired);
            $update['status'] = EmployeeTrainingStatus::Expired;
            $expiredMarked = true;
        }
        $record->forceFill($update)->save();

        return ['alert_sent' => true, 'expired_marked' => $expiredMarked];
    }

    private function alreadyFired(EmployeeTraining $record, TrainingAlertLevel $candidate): bool
    {
        $current = $record->last_alert_level;
        return $current !== null && $current->ordinal() >= $candidate->ordinal();
    }

    /** @param Collection<int, User> $recipients */
    private function notify(EmployeeTraining $record, TrainingAlertLevel $level, int $thresholdDays, Collection $recipients): void
    {
        $training = $record->training;
        $employee = $record->employee;
        $expires = Carbon::parse($record->expires_at)->toDateString();
        $name = $training?->name ?? 'Training';

        [$title, $message] = match ($level) {
            TrainingAlertLevel::T30 => [
                "Training expiry reminder: {$name}",
                "{$employee?->full_name} — {$name} expires on {$expires} ({$thresholdDays} days).",
            ],
            TrainingAlertLevel::T14 => [
                "Training expiring soon: {$name}",
                "{$employee?->full_name} — {$name} expires on {$expires} ({$thresholdDays} days).",
            ],
            TrainingAlertLevel::T7 => [
                "Training expiring urgently: {$name}",
                "{$employee?->full_name} — {$name} expires on {$expires} ({$thresholdDays} days).",
            ],
            TrainingAlertLevel::Expired => [
                "Training overdue: {$name}",
                "{$employee?->full_name} — {$name} expired on {$expires}.",
            ],
        };

        $this->notifications->send($recipients, 'training.expiry', [
            'title' => $title,
            'message' => $message,
            'entity_type' => 'employee_training',
            'entity_id' => $record->hash_id,
            'link_to' => $employee ? "/hr/employees/{$employee->hash_id}" : null,
        ]);
    }

    /**
     * @param list<array{user_id:int, channel:string}> $targets
     */
    private function claimDelivery(EmployeeTraining $record, TrainingAlertLevel $level, array $targets): void
    {
        foreach ($targets as $target) {
            $delivery = TrainingExpiryAlertDelivery::query()->firstOrCreate(
                [
                    'employee_training_id' => $record->id,
                    'alert_level' => $level->value,
                    'recipient_user_id' => $target['user_id'],
                    'channel' => $target['channel'],
                ],
                ['status' => 'pending'],
            );
            $delivery->forceFill([
                'status' => 'pending',
                'attempted_at' => now(),
                'error' => null,
            ])->save();
        }
    }

    /** @param list<array{user_id:int, channel:string}> $targets */
    private function completeDelivery(EmployeeTraining $record, TrainingAlertLevel $level, array $targets): void
    {
        foreach ($targets as $target) {
            TrainingExpiryAlertDelivery::query()
                ->where('employee_training_id', $record->id)
                ->where('alert_level', $level->value)
                ->where('recipient_user_id', $target['user_id'])
                ->where('channel', $target['channel'])
                ->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array{users:Collection<int, User>, targets:list<array{user_id:int,channel:string}>}
     */
    private function deliveryTargets(EmployeeTraining $record): array
    {
        $recipients = $this->resolveRecipients($record);
        if ($recipients->isEmpty()) {
            return ['users' => collect(), 'targets' => []];
        }

        $preferences = DB::table('notification_preferences')
            ->whereIn('user_id', $recipients->pluck('id')->all())
            ->where('notification_type', 'training.expiry')
            ->whereIn('channel', ['in_app', 'email'])
            ->get(['user_id', 'channel', 'enabled'])
            ->keyBy(fn (object $row): string => "{$row->user_id}:{$row->channel}");

        $targets = [];
        $users = collect();
        foreach ($recipients as $user) {
            $inApp = $preferences->get("{$user->id}:in_app")?->enabled;
            $email = $preferences->get("{$user->id}:email")?->enabled;
            $hasInApp = $inApp === null || (bool) $inApp;
            $hasEmail = (bool) $email && is_string($user->email) && $user->email !== '';

            if (! $hasInApp && ! $hasEmail) {
                continue;
            }

            $users->push($user);
            if ($hasInApp) {
                $targets[] = ['user_id' => (int) $user->id, 'channel' => 'in_app'];
            }
            if ($hasEmail) {
                $targets[] = ['user_id' => (int) $user->id, 'channel' => 'email'];
            }
        }

        return ['users' => $users->unique('id')->values(), 'targets' => $targets];
    }

    /** @return list<array{days:int, level:TrainingAlertLevel}> */
    private function tiers(): array
    {
        if ($this->tierCache !== []) {
            return $this->tierCache;
        }

        $t30 = $this->settings->requiredInt('hr.training_expiry.t30_days', 1);
        $t14 = $this->settings->requiredInt('hr.training_expiry.t14_days', 1);
        $t7 = $this->settings->requiredInt('hr.training_expiry.t7_days', 1);
        if (! ($t30 > $t14 && $t14 > $t7)) {
            throw new \App\Common\Exceptions\BusinessRuleException('Training expiry reminder thresholds must be strictly descending.');
        }

        return $this->tierCache = [
            ['days' => $t30, 'level' => TrainingAlertLevel::T30],
            ['days' => $t14, 'level' => TrainingAlertLevel::T14],
            ['days' => $t7, 'level' => TrainingAlertLevel::T7],
            ['days' => 0, 'level' => TrainingAlertLevel::Expired],
        ];
    }

    /** @return Collection<int, User> */
    private function resolveRecipients(EmployeeTraining $record): Collection
    {
        $users = collect();
        $employeeUser = User::query()
            ->where('employee_id', $record->employee_id)
            ->where('is_active', true)
            ->first();
        if ($employeeUser) {
            $users->push($employeeUser);
        }

        $roles = array_values(array_filter(
            (array) $this->settings->get('hr.training_expiry.notification_roles', []),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));
        $roleIds = Role::query()->whereIn('slug', $roles)->pluck('id', 'slug');
        $departmentHeadRoleId = $roleIds->get('department_head');
        $hrOfficerRoleId = $roleIds->get('hr_officer');
        $departmentId = $record->employee?->department_id;

        if ($departmentHeadRoleId && $departmentId) {
            $users = $users->concat(User::query()
                ->where('role_id', $departmentHeadRoleId)
                ->where('is_active', true)
                ->whereHas('employee', fn ($query) => $query->where('department_id', $departmentId))
                ->get());
        }
        if ($hrOfficerRoleId) {
            $users = $users->concat(User::query()
                ->where('role_id', $hrOfficerRoleId)
                ->where('is_active', true)
                ->get());
        }

        return $users->unique('id')->values();
    }
}
