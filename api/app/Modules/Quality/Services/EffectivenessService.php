<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NcrEffectivenessNotification;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Support\NcrEffectivenessStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * CAPA Effectiveness Loop (IATF 16949 §10.2.1).
 *
 * Corrective and preventive actions are scheduled when an NCR closes. A
 * verifier records a server-authoritative verdict; ineffective actions remain
 * re-checkable, while effective and not-applicable are terminal. Reminder
 * rows are keyed by action, recipient, type, and due date so scheduler retries
 * cannot create duplicate alerts.
 */
class EffectivenessService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    /**
     * On NCR close: schedule a verification check for every corrective and
     * preventive action. Containment actions are excluded.
     */
    public function scheduleVerification(NonConformanceReport $ncr): void
    {
        DB::transaction(function () use ($ncr): void {
            $lockedNcr = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->getKey());
            if ($lockedNcr->status !== NcrStatus::Closed) {
                throw new BusinessRuleException('CAPA effectiveness checks can only be scheduled for a closed NCR.');
            }

            $ownerId = $lockedNcr->closed_by;
            $due = now()->addDays($this->positiveIntSetting('quality.effectiveness.check_interval_days'));
            $actions = $lockedNcr->actions()
                ->reorder()
                ->whereIn('action_type', [NcrActionType::Corrective->value, NcrActionType::Preventive->value])
                ->lockForUpdate()
                ->get();

            foreach ($actions as $action) {
                $action->forceFill([
                    'effectiveness_status'        => EffectivenessStatus::PendingVerification->value,
                    'next_effectiveness_check_at' => $due->toDateString(),
                    'due_date'                    => $action->due_date ?? $due->toDateString(),
                    'owner_id'                    => $action->owner_id ?? $ownerId,
                ])->save();
            }

            $lockedNcr->forceFill([
                'effectiveness_status'    => EffectivenessStatus::PendingVerification->value,
                'effectiveness_closed_at' => null,
            ])->save();
        });
    }

    /**
     * Record an effectiveness verdict for one action. Ineffective verdicts
     * schedule a follow-up check; effective and not-applicable clear the
     * schedule and cannot be overwritten without a future explicit reopen flow.
     */
    public function verifyAction(
        NcrAction $action,
        User $by,
        EffectivenessStatus $status,
        string $notes,
    ): NcrAction {
        $notes = trim($notes);
        if ($notes === '') {
            throw new BusinessRuleException('CAPA verification notes are required.');
        }

        return DB::transaction(function () use ($action, $by, $status, $notes): NcrAction {
            $lockedAction = NcrAction::query()->lockForUpdate()->findOrFail($action->getKey());
            $lockedNcr = NonConformanceReport::query()->lockForUpdate()->findOrFail($lockedAction->ncr_id);

            if ($lockedNcr->status !== NcrStatus::Closed) {
                throw new BusinessRuleException('Only actions on a closed NCR can be verified for effectiveness.');
            }

            $actionType = $lockedAction->action_type instanceof \BackedEnum
                ? $lockedAction->action_type->value
                : (string) $lockedAction->action_type;
            if (! in_array($actionType, [NcrActionType::Corrective->value, NcrActionType::Preventive->value], true)) {
                throw new BusinessRuleException('Containment actions cannot receive a CAPA effectiveness verdict.');
            }

            $current = $lockedAction->effectiveness_status instanceof EffectivenessStatus
                ? $lockedAction->effectiveness_status
                : EffectivenessStatus::tryFrom((string) $lockedAction->getRawOriginal('effectiveness_status'));
            NcrEffectivenessStateMachine::assertCanTransition($current, $status);

            $next = $status === EffectivenessStatus::Ineffective
                ? now()->addDays($this->positiveIntSetting('quality.effectiveness.check_interval_days'))->toDateString()
                : null;

            $lockedAction->forceFill([
                'effectiveness_status'        => $status->value,
                'effectiveness_notes'         => $notes,
                'effectiveness_checked_at'    => now(),
                'verified_at'                 => now(),
                'verified_by'                 => $by->id,
                'effectiveness_check_count'   => (int) $lockedAction->effectiveness_check_count + 1,
                'next_effectiveness_check_at' => $next,
            ])->save();

            $this->updateNcrEffectiveness($lockedNcr);

            return $lockedAction->fresh(['verifier:id,name', 'performer:id,name', 'owner:id,name']);
        });
    }

    /**
     * Roll the NCR-level effectiveness verdict once every corrective/preventive
     * action has been verified: any ineffective → ineffective; else effective.
     */
    public function updateNcrEffectiveness(NonConformanceReport $ncr): void
    {
        $actions = $ncr->actions()
            ->reorder()
            ->whereIn('action_type', [NcrActionType::Corrective->value, NcrActionType::Preventive->value])
            ->get();

        if ($actions->isEmpty()) {
            return;
        }

        $allVerified = $actions->every(static function (NcrAction $a): bool {
            $status = $a->effectiveness_status instanceof EffectivenessStatus
                ? $a->effectiveness_status
                : EffectivenessStatus::tryFrom((string) $a->getRawOriginal('effectiveness_status'));

            return in_array($status, [
                EffectivenessStatus::Effective,
                EffectivenessStatus::Ineffective,
                EffectivenessStatus::NotApplicable,
            ], true);
        });

        if (! $allVerified) {
            return;
        }

        $anyIneffective = $actions->contains(static function (NcrAction $a): bool {
            $status = $a->effectiveness_status instanceof EffectivenessStatus
                ? $a->effectiveness_status
                : EffectivenessStatus::tryFrom((string) $a->getRawOriginal('effectiveness_status'));

            return $status === EffectivenessStatus::Ineffective;
        });

        $ncr->forceFill([
            'effectiveness_status'    => $anyIneffective
                ? EffectivenessStatus::Ineffective->value
                : EffectivenessStatus::Effective->value,
            'effectiveness_closed_at' => now(),
        ])->save();
    }

    /**
     * Notify owners of effectiveness checks that are due (or overdue). An
     * overdue reminder is sent once per due date; a manager escalation is a
     * separate once-per-due-date record after the configured threshold.
     */
    public function notifyOverdueChecks(): int
    {
        $today = now()->startOfDay();
        $due = NcrAction::query()
            ->with(['owner:id,name,is_active', 'ncr:id,ncr_number,status'])
            ->whereIn('action_type', [NcrActionType::Corrective->value, NcrActionType::Preventive->value])
            ->whereHas('ncr', fn ($q) => $q->where('status', NcrStatus::Closed->value))
            ->whereIn('effectiveness_status', [
                EffectivenessStatus::PendingVerification->value,
                EffectivenessStatus::Ineffective->value,
            ])
            ->whereNotNull('next_effectiveness_check_at')
            ->whereDate('next_effectiveness_check_at', '<=', $today->toDateString())
            ->get();

        $roles = array_values(array_filter(
            (array) $this->settings->get('quality.effectiveness.overdue_notification_roles', []),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));
        $managers = $roles === []
            ? collect()
            : User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();
        $escalationDays = $this->positiveIntSetting('quality.effectiveness.overdue_escalation_days');

        foreach ($due as $action) {
            $dueDate = $action->next_effectiveness_check_at->toDateString();
            if ($action->owner && $action->owner->is_active) {
                $this->deliverOnce(
                    $action,
                    $action->owner,
                    'effectiveness_due',
                    "CAPA effectiveness due: {$action->ncr?->ncr_number}",
                    "CAPA effectiveness verification is due for {$action->ncr?->ncr_number}.",
                    $dueDate,
                );
            }

            // Magnitude: the query above filters `next_effectiveness_check_at <= today`,
            // so the receiver is provably the earlier instant and the signed and
            // absolute results agree. `true` says so out loud (see CarbonDiffSignConventionTest).
            $overdueDays = max(0, $action->next_effectiveness_check_at->startOfDay()->diffInDays($today, true));
            if ($overdueDays < $escalationDays) {
                continue;
            }

            foreach ($managers as $manager) {
                $this->deliverOnce(
                    $action,
                    $manager,
                    'effectiveness_overdue',
                    "CAPA effectiveness overdue: {$action->ncr?->ncr_number}",
                    "CAPA effectiveness check is {$overdueDays} day(s) overdue for {$action->ncr?->ncr_number}.",
                    $dueDate,
                );
            }
        }

        return $due->count();
    }

    private function deliverOnce(
        NcrAction $action,
        User $recipient,
        string $type,
        string $title,
        string $message,
        string $dueDate,
    ): void {
        DB::transaction(function () use ($action, $recipient, $type, $title, $message, $dueDate): void {
            $lockedAction = NcrAction::query()->lockForUpdate()->find($action->getKey());
            if (! $lockedAction) {
                return;
            }

            $status = $lockedAction->effectiveness_status instanceof EffectivenessStatus
                ? $lockedAction->effectiveness_status->value
                : (string) $lockedAction->getRawOriginal('effectiveness_status');
            if (! in_array($status, [
                EffectivenessStatus::PendingVerification->value,
                EffectivenessStatus::Ineffective->value,
            ], true) || $lockedAction->next_effectiveness_check_at === null
                || $lockedAction->next_effectiveness_check_at->toDateString() !== $dueDate
                || $lockedAction->next_effectiveness_check_at->isFuture()) {
                return;
            }

            $key = "ncr-action:{$lockedAction->id}:{$recipient->id}:{$type}:{$dueDate}:v1";
            if (NcrEffectivenessNotification::query()->where('idempotency_key', $key)->exists()) {
                return;
            }

            $ncr = $lockedAction->ncr()->first();
            if (! $ncr || $ncr->status !== NcrStatus::Closed) {
                return;
            }

            $this->notifications->send($recipient, $type, [
                'title'       => $title,
                'message'     => $message,
                'link_to'     => "/quality/ncrs/{$ncr->hash_id}",
                'entity_type' => 'ncr',
                'entity_id'   => $ncr->hash_id,
                'ncr_number'  => $ncr->ncr_number,
                'action_id'   => $lockedAction->hash_id,
                'due_date'    => $dueDate,
            ]);

            // The notification row and its deduplication ledger commit as one
            // unit. A failure before commit leaves this key retryable.
            NcrEffectivenessNotification::create([
                'ncr_action_id'   => $lockedAction->id,
                'user_id'         => $recipient->id,
                'notification_type' => $type,
                'due_date'        => $dueDate,
                'idempotency_key' => $key,
                'sent_at'         => now(),
            ]);
        });
    }

    private function positiveIntSetting(string $key): int
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }

        return (int) $value;
    }
}
