<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * T3.2.C — Auto-spawn an 8D shell when a customer-complaint NCR is linked
 * to a prior recurrence. The shell is empty (D-fields nullable) — QC fills
 * it via the existing 8D editor. Bridges T3.1.D recurrence detection into
 * the customer complaint follow-through loop.
 *
 * Skipped when:
 *   - NCR.source != customer_complaint (internal NCRs already have NcrAction
 *     for the corrective + preventive surface).
 *   - No CustomerComplaint exists for the NCR (data integrity gap; logged).
 *   - Complaint already has an eightDReport (idempotent).
 *
 * Stateful failures are rethrown so the queue worker can retry and retain a
 * failed-job record. The unique complaint_id constraint makes replays safe.
 */
class AutoSpawn8DOnNcrRecurrence implements ShouldQueue
{
    public function handle(NcrRecurrenceLinked $event): void
    {
        $ncr = $event->ncr;

        $source = $ncr->source instanceof NcrSource
            ? $ncr->source
            : NcrSource::tryFrom((string) $ncr->source);

        if ($source !== NcrSource::CustomerComplaint) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'non_customer_complaint_ncr');
            return;
        }

        /** @var CustomerComplaint|null $complaint */
        $complaint = CustomerComplaint::query()
            ->where('ncr_id', $ncr->id)
            ->first();

        if (! $complaint) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'customer_complaint_missing');
            return;
        }

        // Idempotent — the database unique constraint is the concurrency
        // guard; the existence check is intentionally not used as a gate.
        $report = Complaint8DReport::firstOrCreate([
            'complaint_id' => $complaint->id,
        ]);

        app(ChainListenerRunService::class)->recordOutcome(
            $report->wasRecentlyCreated ? 'completed' : 'skipped',
            $report->wasRecentlyCreated ? 'complaint_8d_shell_created' : 'complaint_8d_shell_already_present',
        );
    }
}
