<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

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
 * Wrapped in try/catch + Log::warning so a queue worker can never crash
 * because of this listener.
 */
class AutoSpawn8DOnNcrRecurrence implements ShouldQueue
{
    public function handle(NcrRecurrenceLinked $event): void
    {
        try {
            $ncr = $event->ncr;

            $source = $ncr->source instanceof NcrSource
                ? $ncr->source
                : NcrSource::tryFrom((string) $ncr->source);

            if ($source !== NcrSource::CustomerComplaint) {
                return;
            }

            /** @var CustomerComplaint|null $complaint */
            $complaint = CustomerComplaint::query()
                ->where('ncr_id', $ncr->id)
                ->first();

            if (! $complaint) {
                Log::info('AutoSpawn8DOnNcrRecurrence: no complaint linked to NCR', [
                    'ncr_id' => $ncr->id,
                ]);
                return;
            }

            // Idempotent — firstOrCreate guarantees no duplicate.
            Complaint8DReport::firstOrCreate([
                'complaint_id' => $complaint->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AutoSpawn8DOnNcrRecurrence failed', [
                'ncr_id' => $event->ncr->id ?? null,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
