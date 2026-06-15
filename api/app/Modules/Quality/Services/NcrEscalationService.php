<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Carbon;

/**
 * T3.1.C — NCR SLA escalator. Mirrors ApprovalEscalationService shape.
 *
 * Open NCRs without a Corrective action accumulate escalation_level over
 * time; each tier resets the SLA clock. Tier 3 is the cap.
 */
class NcrEscalationService
{
    /** Hours since created_at (or last_escalated_at) before next tier fires. */
    private const SLA_HOURS_BY_SEVERITY = [
        'critical' => 8,
        'high'     => 24,
        'medium'   => 72,
        'low'      => 168,
    ];

    private const TIERS = [
        1 => ['role' => 'qc_inspector',  'subject' => 'NCR awaiting corrective'],
        2 => ['role' => 'production_manager', 'subject' => 'NCR overdue — manager attention'],
        3 => ['role' => 'system_admin',  'subject' => 'NCR critical overdue — exec escalation'],
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    /** Returns the count of NCRs advanced this run. */
    public function run(): int
    {
        $advanced = 0;

        $candidates = NonConformanceReport::query()
            ->where('status', NcrStatus::Open->value)
            ->where('escalation_level', '<', 3)
            ->whereDoesntHave('actions', fn ($q) =>
                $q->reorder()->where('action_type', NcrActionType::Corrective->value))
            ->get();

        foreach ($candidates as $ncr) {
            $sev = $ncr->severity instanceof NcrSeverity
                ? $ncr->severity->value
                : (string) $ncr->severity;
            $hoursDue = self::SLA_HOURS_BY_SEVERITY[$sev] ?? 72;
            $clockStart = $ncr->last_escalated_at ?: $ncr->created_at;
            if (! $clockStart instanceof Carbon) {
                $clockStart = Carbon::parse((string) $clockStart);
            }
            if ($clockStart->diffInHours(now()) < $hoursDue) {
                continue;
            }

            $nextTier = ((int) $ncr->escalation_level) + 1;
            if ($nextTier > 3) {
                continue;
            }
            $tier = self::TIERS[$nextTier];

            $ncr->forceFill([
                'escalation_level'  => $nextTier,
                'last_escalated_at' => now(),
            ])->save();

            $recipients = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', $tier['role']))
                ->where('is_active', true)
                ->get();
            foreach ($recipients as $user) {
                $this->notifications->send($user, 'ncr.escalation', [
                    'title'   => $tier['subject'],
                    'message' => "NCR {$ncr->ncr_number} (severity {$sev}) has been open without a Corrective action for over {$hoursDue}h. Tier {$nextTier} escalation.",
                    'link_to' => "/quality/ncrs/{$ncr->hash_id}",
                ]);
            }

            $advanced++;
        }

        return $advanced;
    }
}
