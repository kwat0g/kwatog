<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\Complaint8dEscalationDelivery;
use App\Modules\CRM\Models\CustomerComplaint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * T3.2.B — 8D SLA escalator.
 *
 * Three tiered windows tracked on customer_complaints:
 *   d3_due_at        = created_at + 48h  (containment)
 *   d4_due_at        = created_at + 7d   (root cause)
 *   finalize_due_at  = created_at + 30d  (8D finalised)
 *
 * Complaint8dEscalationDelivery is the durable claim/outcome ledger. The
 * legacy sla_alert_levels JSON remains as a compatibility summary for existing
 * readers, but it is never the sole source of delivery truth.
 */
class Complaint8dEscalationService
{
    private const TIERS = [
        'd3' => [
            'due_field'        => 'd3_due_at',
            'block_when_field' => 'd3_containment',
        ],
        'd4' => [
            'due_field'        => 'd4_due_at',
            'block_when_field' => 'd4_root_cause',
        ],
        'finalize' => [
            'due_field'        => 'finalize_due_at',
            'block_when_field' => null,
        ],
    ];

    /** advanceOne() outcomes. `failed` and `unstaffed` are never folded into `skipped`. */
    private const OUTCOME_ADVANCED = 'advanced';

    private const OUTCOME_SKIPPED = 'skipped';

    private const OUTCOME_UNSTAFFED = 'unstaffed';

    private const OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Backward-compatible per-tier advance counts. Prefer runWithOutcome().
     *
     * @return array{d3:int, d4:int, finalize:int}
     */
    public function run(): array
    {
        $outcome = $this->runWithOutcome();

        return [
            'd3'       => $outcome['advanced_by_tier']['d3'],
            'd4'       => $outcome['advanced_by_tier']['d4'],
            'finalize' => $outcome['advanced_by_tier']['finalize'],
        ];
    }

    /**
     * Escalate every eligible complaint and report what actually happened.
     *
     * A zero advanced count cannot be read as health: a run in which every
     * candidate threw also advances nothing, which is exactly how the 8D SLA
     * ledger stayed dead while the cron printed zeros and exited SUCCESS (see
     * CLAUDE.md). `failed` and `unstaffed` are therefore reported separately and
     * never folded into `skipped`, and the command exits non-zero when any
     * candidate failed.
     *
     * @return array{considered:int, advanced:int, skipped:int, unstaffed:int, failed:int, advanced_by_tier:array{d3:int, d4:int, finalize:int}}
     */
    public function runWithOutcome(): array
    {
        $now = now();

        $candidates = CustomerComplaint::query()
            ->whereNotIn('status', [
                ComplaintStatus::Closed->value,
                ComplaintStatus::Cancelled->value,
            ])
            ->where(function ($q) use ($now): void {
                $q->where('d3_due_at', '<', $now)
                    ->orWhere('d4_due_at', '<', $now)
                    ->orWhere('finalize_due_at', '<', $now);
            });

        $roleSlugs = array_values(array_filter(
            (array) $this->settings->get('crm.complaint_8d.notification_roles', []),
            'is_string',
        ));
        $subjects = (array) $this->settings->get('crm.complaint_8d.escalation_subjects', []);
        $recipients = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roleSlugs))
            ->where('is_active', true)
            ->get();

        $tally = [
            'considered'       => 0,
            'advanced'         => 0,
            'skipped'          => 0,
            'unstaffed'        => 0,
            'failed'           => 0,
            'advanced_by_tier' => ['d3' => 0, 'd4' => 0, 'finalize' => 0],
        ];

        // Keep scheduler memory bounded. Each complaint is re-read and locked
        // before any claim or notification row is written.
        $candidates->orderBy('id')->chunkById(100, function ($batch) use (
            &$tally,
            $now,
            $recipients,
            $subjects,
        ): void {
            foreach ($batch as $candidate) {
                $tally['considered']++;
                $result = $this->advanceOne((int) $candidate->id, $now, $recipients, $subjects);
                $tally[$result['outcome']]++;
                foreach ($result['fired'] as $tier) {
                    $tally['advanced_by_tier'][$tier]++;
                }
            }
        });

        return $tally;
    }

    /**
     * @param Collection<int, User> $configuredRecipients
     * @param array<string, mixed> $subjects
     * @return array{fired: list<string>, outcome: string}
     */
    private function advanceOne(
        int $complaintId,
        \Carbon\CarbonInterface $now,
        Collection $configuredRecipients,
        array $subjects,
    ): array {
        try {
            return DB::transaction(function () use (
                $complaintId,
                $now,
                $configuredRecipients,
                $subjects,
            ): array {
                $complaint = CustomerComplaint::query()
                    ->lockForUpdate()
                    ->find($complaintId);
                if (! $complaint) {
                    return ['fired' => [], 'outcome' => self::OUTCOME_SKIPPED];
                }

                $status = $complaint->status instanceof ComplaintStatus
                    ? $complaint->status
                    : ComplaintStatus::tryFrom((string) $complaint->status);
                if ($status === null || $status->isTerminal()) {
                    return ['fired' => [], 'outcome' => self::OUTCOME_SKIPPED];
                }

                $complaint->load(['eightDReport', 'assignee']);
                $report = $complaint->eightDReport;
                $fired = $this->firedLevels($complaint);
                $levelsChanged = false;
                $toFire = [];
                $deliveries = [];

                foreach (self::TIERS as $key => $config) {
                    $delivery = Complaint8dEscalationDelivery::query()
                        ->where('complaint_id', $complaint->id)
                        ->where('tier', $key)
                        ->lockForUpdate()
                        ->first();

                    // Repair a legacy row where the durable delivery committed
                    // but the compatibility JSON marker did not.
                    if ($delivery?->sent_at !== null) {
                        if (! in_array($key, $fired, true)) {
                            $fired[] = $key;
                            $levelsChanged = true;
                        }
                        continue;
                    }

                    if (in_array($key, $fired, true)) {
                        continue;
                    }

                    $dueAt = $complaint->{$config['due_field']};
                    if (! $dueAt || $now->lessThanOrEqualTo($dueAt)) {
                        continue;
                    }

                    if (! $this->tierNeedsEscalation($key, $config, $report)) {
                        continue;
                    }

                    $subject = is_string($subjects[$key] ?? null)
                        ? trim((string) $subjects[$key])
                        : '';
                    if ($subject === '') {
                        throw new BusinessRuleException(
                            "8D escalation subject for {$key} is not configured.",
                        );
                    }

                    $delivery ??= Complaint8dEscalationDelivery::create([
                        'complaint_id'   => $complaint->id,
                        'tier'           => $key,
                        'status'         => 'pending',
                        'attempts'       => 0,
                        'recipient_count' => 0,
                        'idempotency_key' => "complaint:{$complaint->id}:8d:{$key}:v1",
                    ]);
                    $deliveries[$key] = $delivery;
                    $toFire[$key] = $subject;
                }

                if ($levelsChanged) {
                    $complaint->forceFill([
                        'sla_alert_levels' => array_values(array_unique($fired)),
                    ])->save();
                }

                if ($toFire === []) {
                    return ['fired' => [], 'outcome' => self::OUTCOME_SKIPPED];
                }

                $audience = $this->deliverableAudience($configuredRecipients, $complaint->assignee);
                if ($audience->isEmpty()) {
                    foreach ($deliveries as $delivery) {
                        $this->markPending($delivery, 'No active notification recipient is configured.');
                    }

                    // A tier nobody received is not a silent skip: it stays
                    // pending for the next run and is surfaced in its own bucket.
                    return ['fired' => [], 'outcome' => self::OUTCOME_UNSTAFFED];
                }

                $sent = [];
                foreach ($toFire as $key => $subject) {
                    $delivery = $deliveries[$key];
                    $delivery->forceFill([
                        'status'            => 'pending',
                        'attempts'          => (int) $delivery->attempts + 1,
                        'recipient_count'   => $audience->count(),
                        'last_attempted_at' => now(),
                        'last_error'        => null,
                    ])->save();

                    // NotificationService inserts all in-app rows in this
                    // transaction and defers realtime/email side effects until
                    // commit. A thrown send rolls back both rows and claims.
                    $this->notifications->send($audience->all(), '8d.sla', [
                        'title'       => $subject,
                        'message'     => "Complaint {$complaint->complaint_number} has missed the {$key} SLA window. Review the 8D and act now.",
                        'link_to'     => "/crm/complaints/{$complaint->hash_id}",
                        'entity_type' => 'customer_complaint',
                        'entity_id'   => $complaint->hash_id,
                        'tier'        => $key,
                    ]);

                    $sentAt = now();
                    $delivery->forceFill([
                        'status'          => 'sent',
                        'recipient_count' => $audience->count(),
                        'sent_at'         => $sentAt,
                        'last_error'      => null,
                    ])->save();
                    $sent[] = $key;
                    $fired[] = $key;
                }

                $complaint->forceFill([
                    'sla_alert_levels' => array_values(array_unique($fired)),
                ])->save();

                return ['fired' => $sent, 'outcome' => self::OUTCOME_ADVANCED];
            });
        } catch (BusinessRuleException $exception) {
            // A malformed/missing escalation policy is an operator-facing
            // configuration error, not a transient delivery failure. Surface it
            // instead of pretending the tier was simply skipped.
            Log::error('Complaint8dEscalationService: escalation policy is invalid', [
                'complaint_id' => $complaintId,
                'message'      => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            // The transaction above rolled back the tier and any notification
            // rows. Preserve a retryable delivery record separately, and report
            // the complaint as failed so the command cannot exit SUCCESS while
            // nothing survived.
            $this->recordFailure($complaintId, $exception);
            Log::warning('Complaint8dEscalationService: tier evaluation failed', [
                'complaint_id' => $complaintId,
                'error'        => $exception->getMessage(),
            ]);

            return ['fired' => [], 'outcome' => self::OUTCOME_FAILED];
        }
    }

    private function tierNeedsEscalation(
        string $key,
        array $config,
        ?Complaint8DReport $report,
    ): bool {
        if ($key === 'finalize') {
            return ! ($report?->finalized_at);
        }

        $value = $report
            ? trim((string) $report->{$config['block_when_field']})
            : '';

        return $value === '';
    }

    /**
     * @return list<string>
     */
    private function firedLevels(CustomerComplaint $complaint): array
    {
        return array_values(array_unique(array_filter(
            (array) ($complaint->sla_alert_levels ?? []),
            static fn (mixed $level): bool => is_string($level) && $level !== '',
        )));
    }

    /**
     * NotificationService treats in-app as opt-out and email as opt-in. Filter
     * the audience using the same contract so an all-opted-out audience remains
     * pending instead of consuming a tier without a durable channel.
     *
     * @param Collection<int, User> $configuredRecipients
     * @return Collection<int, User>
     */
    private function deliverableAudience(Collection $configuredRecipients, ?User $assignee): Collection
    {
        $audience = collect($configuredRecipients->all());
        if ($assignee?->is_active) {
            $audience->push($assignee);
        }
        $audience = $audience
            ->filter(static fn (mixed $user): bool => $user instanceof User && $user->is_active)
            ->unique('id')
            ->values();

        if ($audience->isEmpty()) {
            return $audience;
        }

        $preferences = DB::table('notification_preferences')
            ->whereIn('user_id', $audience->pluck('id')->all())
            ->where('notification_type', '8d.sla')
            ->whereIn('channel', ['in_app', 'email'])
            ->get(['user_id', 'channel', 'enabled'])
            ->keyBy(static fn (object $row): string => "{$row->user_id}:{$row->channel}");

        return $audience
            ->filter(static function (User $user) use ($preferences): bool {
                $inApp = $preferences->get("{$user->id}:in_app")?->enabled;
                $email = $preferences->get("{$user->id}:email")?->enabled;

                return $inApp === null
                    || (bool) $inApp
                    || ((bool) $email && is_string($user->email) && $user->email !== '');
            })
            ->values();
    }

    private function markPending(
        Complaint8dEscalationDelivery $delivery,
        string $error,
    ): void {
        $delivery->forceFill([
            'status'            => 'pending',
            'attempts'          => (int) $delivery->attempts + 1,
            'last_attempted_at' => now(),
            'last_error'        => mb_substr($error, 0, 5000),
        ])->save();
    }

    private function recordFailure(int $complaintId, Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($complaintId, $exception): void {
                $complaint = CustomerComplaint::query()
                    ->lockForUpdate()
                    ->find($complaintId);
                if (! $complaint) {
                    return;
                }

                $status = $complaint->status instanceof ComplaintStatus
                    ? $complaint->status
                    : ComplaintStatus::tryFrom((string) $complaint->status);
                if ($status === null || $status->isTerminal()) {
                    return;
                }

                $complaint->load('eightDReport');
                $fired = $this->firedLevels($complaint);
                foreach (self::TIERS as $key => $config) {
                    if (in_array($key, $fired, true)) {
                        continue;
                    }

                    $dueAt = $complaint->{$config['due_field']};
                    if (! $dueAt || now()->lessThanOrEqualTo($dueAt)
                        || ! $this->tierNeedsEscalation($key, $config, $complaint->eightDReport)) {
                        continue;
                    }

                    $delivery = Complaint8dEscalationDelivery::query()
                        ->where('complaint_id', $complaint->id)
                        ->where('tier', $key)
                        ->lockForUpdate()
                        ->first();
                    if ($delivery?->sent_at !== null) {
                        continue;
                    }
                    $delivery ??= Complaint8dEscalationDelivery::create([
                        'complaint_id'    => $complaint->id,
                        'tier'            => $key,
                        'status'          => 'pending',
                        'attempts'        => 0,
                        'recipient_count' => 0,
                        'idempotency_key' => "complaint:{$complaint->id}:8d:{$key}:v1",
                    ]);
                    $this->markPending($delivery, $exception->getMessage());
                }
            });
        } catch (Throwable $recordingFailure) {
            Log::error('Complaint8dEscalationService: failure could not be recorded', [
                'complaint_id' => $complaintId,
                'error'        => $recordingFailure->getMessage(),
            ]);
        }
    }
}
