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
                ->whereDoesntHave('inspections', fn ($i) => $i
                    ->where('stage', InspectionStage::Outgoing->value)),
            ])
            ->whereIn('status', ['completed', 'closed'])
            ->where(function ($q) {
                $q->whereNotNull('sales_order_id')->orWhereNotNull('parent_ncr_id');
            })
            ->whereHas('outputs', fn ($q) => $q->where('good_count', '>', 0))
            ->whereDoesntHave('outputs.inspections', fn ($i) => $i
                ->where('stage', InspectionStage::Outgoing->value))
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

                    $count = 0;
                    foreach ($lockedWo->outputs()->where('good_count', '>', 0)->orderBy('id')->get() as $output) {
                        $exists = $output->inspections()
                            ->where('stage', InspectionStage::Outgoing->value)
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
                ->get();

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
