<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
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
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

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
            $hoursDue = $this->settings->requiredInt("quality.ncr.sla_{$sev}_hours", 1);
            $clockStart = $ncr->last_escalated_at ?: $ncr->created_at;
            if (! $clockStart instanceof Carbon) {
                $clockStart = Carbon::parse((string) $clockStart);
            }
            if ($clockStart->diffInHours(now(), true) < $hoursDue) {
                continue;
            }

            $nextTier = ((int) $ncr->escalation_level) + 1;
            if ($nextTier > 3) {
                continue;
            }
            $subjects = (array) $this->settings->get('quality.ncr.escalation_subjects', []);
            $tier = ['role' => '', 'subject' => (string) ($subjects[$nextTier - 1] ?? '')];
            if ($tier['subject'] === '') {
                throw new \App\Common\Exceptions\BusinessRuleException('NCR escalation subjects are not configured.');
            }
            $roles = array_values(array_filter((array) $this->settings->get('quality.ncr.escalation_roles', []), 'is_string'));
            $tier['role'] = $roles[$nextTier - 1] ?? '';
            if ($tier['role'] === '') {
                throw new \App\Common\Exceptions\BusinessRuleException('NCR escalation roles are not configured.');
            }

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
