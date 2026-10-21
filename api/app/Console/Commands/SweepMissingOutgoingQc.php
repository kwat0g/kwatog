<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O2C gap — there was no recovery surface for a work order whose outgoing QC
 * handoff silently stranded. TriggerOutgoingQC refuses (and rethrows, for
 * queue retry) when the product has no active inspection spec, the WO has no
 * attributeable creator, or the output rows are inconsistent; a queue can
 * also simply exhaust its retries. Either way the WO sat `completed` with no
 * outgoing inspection, the sales-order line could never ship, and the only
 * trace was a row in failed_jobs — nobody was told, and nothing re-fired the
 * handoff once the underlying problem was fixed.
 *
 * This sweep closes the gap in three parts:
 *  1. DETECT — completed/closed SO-linked (or rework) WOs whose output
 *     batches have no outgoing inspection.
 *  2. REPAIR — re-run the same guard-locked creation the listener uses, so a
 *     fixed spec or repaired output now produces the inspection (idempotent;
 *     uniqueness is enforced by the partial indexes, not by this command).
 *  3. ESCALATE — WOs that still cannot be repaired are reported as an
 *     actionable notification to the outgoing-QC notification roles with the
 *     concrete blocker, and every repair failure is logged with its message.
 *
 * Distinguish nothing-done from everything-failed (repo rule): the command
 * exits FAILURE when any repair errored, even if others succeeded. A stateful
 * refusal (e.g. spec still missing) is NOT a command error — it counts as
 * escalated.
 */
class SweepMissingOutgoingQc extends Command
{
    protected $signature = 'qc:sweep-missing-outgoing
        {--limit=50 : Maximum work orders to examine per run}
        {--notify-only : Detect and notify without attempting repairs}';

    protected $description = 'Detect completed work orders whose output batches never received outgoing QC, repair the handoff where possible, and escalate the rest';

    public function handle(
        InspectionService $inspections,
        NotificationService $notifications,
        SettingsService $settings,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $notifyOnly = (bool) $this->option('notify-only');

        // Completed (or closed, for replay safety) work orders whose creation
        // contract with outgoing QC — SO-linked or NCR rework (REC-07) — is
        // met, but whose good output batches have no outgoing inspection.
        // The same predicate TriggerOutgoingQC guards on, read from the
        // authoritative rows instead of a queued snapshot.
        $workOrders = WorkOrder::query()
            ->with(['creator', 'outputs' => fn ($q) => $q
                ->where('good_count', '>', 0)
                ->where(fn ($eligible) => $eligible->whereNull('rework_ncr_id')->orWhereIn('rework_ncr_id',
                    DB::table('non_conformance_reports')->whereNotNull('rework_finished_at')->select('id')))
                ->whereDoesntHave('inspections', fn ($i) => $i
                    ->where('stage', InspectionStage::Outgoing->value)
                    ->where('status', '!=', 'cancelled')),
            ])
            ->whereIn('status', ['completed', 'closed'])
            ->whereHas('outputs', fn ($q) => $q
                ->where('good_count', '>', 0)
                ->where(fn ($eligible) => $eligible->whereNull('rework_ncr_id')->orWhereIn('rework_ncr_id',
                    DB::table('non_conformance_reports')->whereNotNull('rework_finished_at')->select('id')))
                ->whereDoesntHave('inspections', fn ($i) => $i
                    ->where('stage', InspectionStage::Outgoing->value)
                    ->where('status', '!=', 'cancelled')))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($workOrders->isEmpty()) {
            $this->info('No work orders are missing outgoing QC.');

            return self::SUCCESS;
        }

        $repaired = [];
        $escalated = [];
        $failed = 0;

        foreach ($workOrders as $wo) {
            try {
                $creator = $wo->creator;
                if (! $creator) {
                    $escalated[] = [$wo, 'Work order has no active creator to attribute the outgoing inspection.'];

                    continue;
                }

                if ($notifyOnly) {
                    $escalated[] = [$wo, 'Notify-only run: repair skipped.'];

                    continue;
                }

                $created = DB::transaction(function () use ($inspections, $wo, $creator): int {
                    // Match the listener's contract: re-read and lock before
                    // creating cross-module QC work.
                    $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
                    if (! $lockedWo || ! in_array($lockedWo->status?->value, ['completed', 'closed'], true)) {
                        return 0;
                    }

                    if ($lockedWo->status?->value === 'closed') {
                        throw new BusinessRuleException('Closed work order has missing outgoing QC. Controlled reconciliation required; automatic creation and reopening are prohibited.');
                    }

                    $count = 0;
                    foreach ($lockedWo->outputs()->where('good_count', '>', 0)
                        ->where(fn ($eligible) => $eligible->whereNull('rework_ncr_id')->orWhereIn('rework_ncr_id',
                            DB::table('non_conformance_reports')->whereNotNull('rework_finished_at')->select('id')))
                        ->orderBy('id')->get() as $output) {
                        $exists = $output->inspections()
                            ->where('stage', InspectionStage::Outgoing->value)
                            ->where('status', '!=', 'cancelled')
                            ->exists();
                        if ($exists) {
                            continue;
                        }

                        $inspections->createForOutput($output, $lockedWo, $creator);
                        $count++;
                    }

                    return $count;
                });

                if ($created > 0) {
                    $repaired[] = [$wo, $created];
                } else {
                    $escalated[] = [$wo, 'Repair produced no inspection (work order changed state or batches were already covered).'];
                }
            } catch (BusinessRuleException $e) {
                // Stateful refusal (missing spec, missing revision, …) keeps
                // the WO on the sweep list and escalates instead of failing
                // the whole command — the refusal is the data ops need.
                Log::warning('qc:sweep-missing-outgoing repair refused', [
                    'wo_id' => $wo->id,
                    'error' => $e->getMessage(),
                ]);
                $escalated[] = [$wo, mb_substr($e->getMessage(), 0, 240)];
            } catch (Throwable $e) {
                // Anything else is unexpected: surface it in the exit code too.
                Log::error('qc:sweep-missing-outgoing repair errored', [
                    'wo_id' => $wo->id,
                    'error' => $e->getMessage(),
                ]);
                $escalated[] = [$wo, mb_substr($e->getMessage(), 0, 240)];
                $failed++;
            }
        }

        foreach ($repaired as [$wo, $count]) {
            $this->info("Repaired: {$wo->wo_number} — created {$count} outgoing inspection(s).");
        }

        if ($escalated !== []) {
            $recipients = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug',
                    (array) $settings->get('quality.outgoing_qc.notification_roles', [])))
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->hasPermission('production.work_orders.view'))
                ->values();
            if ($recipients->isEmpty()) {
                $recipients = User::query()
                    ->whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))
                    ->where('is_active', true)
                    ->get();
                if ($recipients->isEmpty()) {
                    Log::warning('Outgoing QC escalation has no work-order viewers or system admin.', [
                        'work_order_ids' => collect($escalated)->map(fn (array $row): string => $row[0]->hash_id)->all(),
                    ]);
                }
            }

            $lines = [];
            foreach ($escalated as [$wo, $reason]) {
                $lines[] = "{$wo->wo_number}: {$reason}";
                $this->warn("Escalated: {$wo->wo_number} — {$reason}");
            }

            try {
                $notifications->send($recipients, 'chain.outgoing_qc_missing', [
                    'title' => 'Outgoing QC missing on completed work orders',
                    'message' => count($escalated).' completed work order(s) have output batches with no outgoing QC: '
                        .implode(' | ', $lines),
                    'link_to' => '/production/work-orders',
                    'entity_type' => 'work_order',
                    'entity_id' => $escalated[0][0]->hash_id,
                ]);
            } catch (Throwable $e) {
                // The escalation must surface here in the command output even
                // if the notification channel is broken.
                $this->error('Notification dispatch failed: '.$e->getMessage());
                $failed++;
            }
        }

        $this->line(sprintf(
            '%d examined, %d repaired, %d escalated, %d errored.',
            $workOrders->count(), count($repaired), count($escalated), $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
