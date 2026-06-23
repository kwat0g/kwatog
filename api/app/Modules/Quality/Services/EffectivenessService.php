<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Facades\DB;

/**
 * CAPA Effectiveness Loop (IATF 16949 §10.2.1).
 *
 * When an NCR is closed, every corrective/preventive action is scheduled for a
 * 30-day effectiveness check. A verifier confirms the fix worked (or didn't);
 * ineffective actions re-schedule a follow-up check. When all actions are
 * verified, the NCR rolls up to an effective/ineffective verdict.
 */
class EffectivenessService
{
    private const CHECK_INTERVAL_DAYS = 30;
    private const OVERDUE_ESCALATION_DAYS = 14;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * On NCR close: schedule a verification check for every corrective and
     * preventive action. Containment actions are excluded (they are temporary).
     */
    public function scheduleVerification(NonConformanceReport $ncr): void
    {
        DB::transaction(function () use ($ncr) {
            $ownerId = $ncr->closed_by;
            $due = now()->addDays(self::CHECK_INTERVAL_DAYS);

            $ncr->actions()
                ->reorder()
                ->whereIn('action_type', [NcrActionType::Corrective->value, NcrActionType::Preventive->value])
                ->get()
                ->each(function (NcrAction $action) use ($ownerId, $due) {
                    $action->forceFill([
                        'effectiveness_status'        => EffectivenessStatus::PendingVerification->value,
                        'next_effectiveness_check_at' => $due->toDateString(),
                        'due_date'                    => $action->due_date ?? $due->toDateString(),
                        'owner_id'                    => $action->owner_id ?? $ownerId,
                    ])->save();
                });

            $ncr->forceFill([
                'effectiveness_status' => EffectivenessStatus::PendingVerification->value,
            ])->save();
        });
    }

    /**
     * Record an effectiveness verdict for one action. Ineffective verdicts
     * schedule a follow-up check; effective verdicts clear the schedule.
     */
    public function verifyAction(
        NcrAction $action,
        User $by,
        EffectivenessStatus $status,
        string $notes,
    ): NcrAction {
        return DB::transaction(function () use ($action, $by, $status, $notes) {
            $next = $status === EffectivenessStatus::Ineffective
                ? now()->addDays(self::CHECK_INTERVAL_DAYS)->toDateString()
                : null;

            $action->forceFill([
                'effectiveness_status'        => $status->value,
                'effectiveness_notes'         => $notes,
                'effectiveness_checked_at'    => now(),
                'verified_at'                 => now(),
                'verified_by'                 => $by->id,
                'effectiveness_check_count'   => (int) $action->effectiveness_check_count + 1,
                'next_effectiveness_check_at' => $next,
            ])->save();

            $this->updateNcrEffectiveness($action->ncr);

            return $action->fresh(['verifier:id,name', 'performer:id,name']);
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

        $allVerified = $actions->every(function (NcrAction $a) {
            return in_array($a->effectiveness_status, [
                EffectivenessStatus::Effective,
                EffectivenessStatus::Ineffective,
                EffectivenessStatus::NotApplicable,
            ], true);
        });

        if (! $allVerified) {
            return;
        }

        $anyIneffective = $actions->contains(
            fn (NcrAction $a) => $a->effectiveness_status === EffectivenessStatus::Ineffective
        );

        $ncr->forceFill([
            'effectiveness_status'    => $anyIneffective
                ? EffectivenessStatus::Ineffective->value
                : EffectivenessStatus::Effective->value,
            'effectiveness_closed_at' => now(),
        ])->save();
    }

    /**
     * Notify owners of effectiveness checks that are due (or overdue). Overdue
     * by more than 14 days additionally escalates to production managers.
     * Returns the number of actions surfaced. Intended for a daily cron.
     */
    public function notifyOverdueChecks(): int
    {
        $today = now()->startOfDay();

        $due = NcrAction::query()
            ->with(['owner:id,name', 'ncr:id,ncr_number'])
            ->where('effectiveness_status', EffectivenessStatus::PendingVerification->value)
            ->whereNotNull('next_effectiveness_check_at')
            ->whereDate('next_effectiveness_check_at', '<=', $today->toDateString())
            ->get();

        foreach ($due as $action) {
            if ($action->owner) {
                $this->notifications->send($action->owner, 'effectiveness_due', [
                    'ncr_number'  => $action->ncr?->ncr_number,
                    'action_id'   => $action->hash_id,
                    'due_date'    => optional($action->next_effectiveness_check_at)->toDateString(),
                    'message'     => "CAPA effectiveness verification due for {$action->ncr?->ncr_number}.",
                ]);
            }

            $overdueDays = $action->next_effectiveness_check_at
                ? $today->diffInDays($action->next_effectiveness_check_at, false)
                : 0;

            if ($overdueDays <= -self::OVERDUE_ESCALATION_DAYS) {
                $managers = User::query()
                    ->whereHas('role', fn ($q) => $q->where('slug', 'production_manager'))
                    ->where('is_active', true)
                    ->get();
                if ($managers->isNotEmpty()) {
                    $this->notifications->send($managers, 'effectiveness_overdue', [
                        'ncr_number' => $action->ncr?->ncr_number,
                        'action_id'  => $action->hash_id,
                        'message'    => "CAPA effectiveness check overdue >14d for {$action->ncr?->ncr_number}.",
                    ]);
                }
            }
        }

        return $due->count();
    }
}
