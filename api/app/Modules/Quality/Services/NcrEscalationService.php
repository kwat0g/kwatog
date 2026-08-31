<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NcrEscalationDelivery;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * T3.1.C — NCR SLA escalator.
 *
 * An NCR remains eligible while it is non-terminal and has no Corrective
 * action. Each tier is represented by a durable delivery row. The NCR and its
 * delivery row are locked and advanced in the same transaction as the
 * notification insert, so a failed send cannot consume a tier.
 */
class NcrEscalationService
{
    private const MAX_TIER = 3;

    /** advanceOne() outcomes. `failed` must never be reported as `skipped`. */
    private const OUTCOME_ADVANCED = 'advanced';

    private const OUTCOME_SKIPPED = 'skipped';

    private const OUTCOME_UNSTAFFED = 'unstaffed';

    private const OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    /** Returns the count of NCRs whose tier was durably delivered this run. */
    public function run(): int
    {
        return $this->runWithOutcome()['advanced'];
    }

    /**
     * Escalate every eligible NCR and report what actually happened.
     *
     * `advanced` alone cannot be read as health. A run in which every single
     * candidate threw also advances nothing, and for the 8D SLA ledger that
     * ambiguity meant a subsystem which had never once worked printed a
     * zero-count summary and exited SUCCESS every 15 minutes (see CLAUDE.md).
     * `failed` is what separates "nothing was due" from "nothing survived", so
     * it is reported separately and never folded into `skipped`. An unstaffed
     * escalation role gets its own bucket: no notification reached anybody,
     * which an operator must see, but it is a configuration state rather than
     * a fault, so it does not fail the run.
     *
     * @return array{considered: int, advanced: int, skipped: int, unstaffed: int, failed: int}
     */
    public function runWithOutcome(): array
    {
        $ids = NonConformanceReport::query()
            ->whereIn('status', [NcrStatus::Open->value, NcrStatus::InProgress->value])
            ->where('escalation_level', '<', self::MAX_TIER)
            ->whereDoesntHave(
                'actions',
                fn ($q) => $q->reorder()->where('action_type', NcrActionType::Corrective->value),
            )
            ->pluck('id');

        $tally = [
            'considered' => $ids->count(),
            'advanced'   => 0,
            'skipped'    => 0,
            'unstaffed'  => 0,
            'failed'     => 0,
        ];

        foreach ($ids as $id) {
            $tally[$this->advanceOne((int) $id)]++;
        }

        return $tally;
    }

    /** @return self::OUTCOME_* */
    private function advanceOne(int $ncrId): string
    {
        try {
            return DB::transaction(function () use ($ncrId): string {
                $ncr = NonConformanceReport::query()->lockForUpdate()->find($ncrId);
                if (! $ncr || ! $this->isEligible($ncr)) {
                    return self::OUTCOME_SKIPPED;
                }

                $severity = $ncr->severity instanceof NcrSeverity
                    ? $ncr->severity->value
                    : (string) $ncr->severity;
                $hoursDue = $this->settings->requiredInt("quality.ncr.sla_{$severity}_hours", 1);
                $clockStart = $ncr->last_escalated_at ?: $ncr->created_at;
                if (! $clockStart instanceof Carbon) {
                    $clockStart = Carbon::parse((string) $clockStart);
                }
                if ($clockStart->diffInHours(now(), true) < $hoursDue) {
                    return self::OUTCOME_SKIPPED;
                }

                $nextTier = ((int) $ncr->escalation_level) + 1;
                if ($nextTier > self::MAX_TIER) {
                    return self::OUTCOME_SKIPPED;
                }

                [$role, $subject] = $this->tierConfiguration($nextTier);
                $idempotencyKey = "ncr:{$ncr->id}:escalation:{$nextTier}:v1";
                $delivery = NcrEscalationDelivery::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if (! $delivery) {
                    $delivery = NcrEscalationDelivery::create([
                        'ncr_id'          => $ncr->id,
                        'tier'            => $nextTier,
                        'role'            => $role,
                        'subject'         => $subject,
                        'status'          => 'pending',
                        'attempts'        => 0,
                        'recipient_count' => 0,
                        'idempotency_key' => $idempotencyKey,
                    ]);
                }

                if ($delivery->sent_at !== null) {
                    // A repair run can reconcile an already-delivered row if a
                    // legacy deployment had written the two aggregates apart.
                    if ((int) $ncr->escalation_level < $nextTier) {
                        $ncr->forceFill([
                            'escalation_level'  => $nextTier,
                            'last_escalated_at' => $delivery->sent_at,
                        ])->save();
                    }

                    return self::OUTCOME_SKIPPED;
                }

                $recipients = User::query()
                    ->whereHas('role', fn ($q) => $q->where('slug', $role))
                    ->where('is_active', true)
                    ->get();

                $delivery->forceFill([
                    'attempts'          => (int) $delivery->attempts + 1,
                    'last_attempted_at' => now(),
                    'last_error'        => null,
                ])->save();

                if ($recipients->isEmpty()) {
                    // Empty audiences are not a successful delivery. Keep the
                    // durable row pending so the next run can retry after the
                    // role is staffed or reconfigured, and report it in its own
                    // bucket — a tier nobody received is invisible if it is
                    // counted as an ordinary skip.
                    $delivery->forceFill([
                        'status'     => 'pending',
                        'last_error' => "No active recipients for escalation role {$role}.",
                    ])->save();

                    return self::OUTCOME_UNSTAFFED;
                }

                $this->notifications->send($recipients, 'ncr.escalation', [
                    'title'       => $subject,
                    'message'     => "NCR {$ncr->ncr_number} (severity {$severity}) has been open without a Corrective action for over {$hoursDue}h. Tier {$nextTier} escalation.",
                    'link_to'     => "/quality/ncrs/{$ncr->hash_id}",
                    'entity_type' => 'ncr',
                    'entity_id'   => $ncr->hash_id,
                    'ncr_number'  => $ncr->ncr_number,
                    'severity'    => $severity,
                    'tier'        => $nextTier,
                ]);

                $sentAt = now();
                $delivery->forceFill([
                    'status'          => 'sent',
                    'recipient_count' => $recipients->count(),
                    'sent_at'         => $sentAt,
                    'last_error'      => null,
                ])->save();

                $ncr->forceFill([
                    'escalation_level'  => $nextTier,
                    'last_escalated_at' => $sentAt,
                ])->save();

                return self::OUTCOME_ADVANCED;
            });
        } catch (BusinessRuleException $exception) {
            // A malformed/missing escalation policy is an operator-facing
            // configuration error, not a retryable delivery failure. Do not
            // swallow it as if a notification provider had transiently failed.
            Log::error('NCR escalation policy is invalid.', [
                'ncr_id'    => $ncrId,
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            // The transaction above rolls back the tier and any notification
            // rows. Preserve a retryable delivery record separately so support
            // can see why the next scheduler run did not advance it.
            try {
                DB::transaction(function () use ($ncrId, $exception): void {
                    $ncr = NonConformanceReport::query()->lockForUpdate()->find($ncrId);
                    if (! $ncr || ! $this->isEligible($ncr)) {
                        return;
                    }

                    $nextTier = ((int) $ncr->escalation_level) + 1;
                    if ($nextTier > self::MAX_TIER) {
                        return;
                    }

                    [$role, $subject] = $this->tierConfiguration($nextTier);
                    $delivery = NcrEscalationDelivery::query()->firstOrCreate(
                        ['idempotency_key' => "ncr:{$ncr->id}:escalation:{$nextTier}:v1"],
                        [
                            'ncr_id'          => $ncr->id,
                            'tier'            => $nextTier,
                            'role'            => $role,
                            'subject'         => $subject,
                            'status'          => 'pending',
                            'attempts'        => 0,
                            'recipient_count' => 0,
                        ],
                    );
                    $delivery->forceFill([
                        'status'            => 'pending',
                        'attempts'          => (int) $delivery->attempts + 1,
                        'last_attempted_at' => now(),
                        'last_error'        => mb_substr($exception->getMessage(), 0, 5000),
                    ])->save();
                });
            } catch (Throwable $recordingFailure) {
                // The recorder could not record. That is not a delivery
                // problem and must not be reduced to a log line: the caller
                // still counts this NCR as failed, so the command reports a
                // non-zero failure count and exits non-zero either way.
                Log::error('NCR escalation failure could not be recorded.', [
                    'ncr_id'    => $ncrId,
                    'exception' => $recordingFailure::class,
                    'message'   => $recordingFailure->getMessage(),
                ]);
            }

            Log::warning('NCR escalation delivery failed; tier remains retryable.', [
                'ncr_id'    => $ncrId,
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);

            return self::OUTCOME_FAILED;
        }
    }

    private function isEligible(NonConformanceReport $ncr): bool
    {
        if (! in_array((string) ($ncr->status instanceof \BackedEnum ? $ncr->status->value : $ncr->status), [
            NcrStatus::Open->value,
            NcrStatus::InProgress->value,
        ], true)) {
            return false;
        }

        if ((int) $ncr->escalation_level >= self::MAX_TIER) {
            return false;
        }

        return ! $ncr->actions()
            ->where('action_type', NcrActionType::Corrective->value)
            ->exists();
    }

    /** @return array{0: string, 1: string} */
    private function tierConfiguration(int $tier): array
    {
        $subjects = array_values(array_filter(
            (array) $this->settings->get('quality.ncr.escalation_subjects', []),
            static fn ($value): bool => is_string($value) && $value !== '',
        ));
        $roles = array_values(array_filter(
            (array) $this->settings->get('quality.ncr.escalation_roles', []),
            static fn ($value): bool => is_string($value) && $value !== '',
        ));

        $subject = (string) ($subjects[$tier - 1] ?? '');
        $role = (string) ($roles[$tier - 1] ?? '');
        if ($subject === '') {
            throw new BusinessRuleException('NCR escalation subjects are not configured.');
        }
        if ($role === '') {
            throw new BusinessRuleException('NCR escalation roles are not configured.');
        }

        return [$role, $subject];
    }
}
