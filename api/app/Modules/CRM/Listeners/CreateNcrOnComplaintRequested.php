<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SystemActorService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\ComplaintNcrRequested;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Services\ComplaintService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Retry only the failed complaint → Quality NCR handoff. */
class CreateNcrOnComplaintRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly ComplaintService $complaints,
        private readonly SystemActorService $actors,
    ) {}

    public function handle(ComplaintNcrRequested $event): void
    {
        $complaint = CustomerComplaint::query()->find($event->complaint->id);
        if (! $complaint) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'customer_complaint_missing',
            );
            return;
        }

        if ($complaint->ncr_id !== null || $complaint->ncr_handoff_status?->value === 'generated') {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'complaint_ncr_already_linked',
            );
            return;
        }

        $by = $complaint->created_by
            ? User::query()->find($complaint->created_by)
            : null;
        $by ??= $this->actors->resolve();

        if (! $by) {
            $this->complaints->markNcrHandoffManual(
                $complaint->id,
                'No active NCR actor is available. Configure an automation actor or retry from the complaint.',
            );
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'complaint_ncr_actor_missing',
                'Configure an automation actor or retry the NCR handoff from the complaint.',
            );
            return;
        }

        try {
            $updated = $this->complaints->retryNcrHandoff($complaint, $by);
        } catch (BusinessRuleException $e) {
            Log::warning('CreateNcrOnComplaintRequested requires manual action', [
                'complaint_id' => $complaint->id,
                'reason_code' => $event->reasonCode,
                'error' => $e->getMessage(),
            ]);
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'complaint_ncr_manual_required',
                'Fix the Quality/NCR setup, then replay this handoff or retry it from the complaint.',
            );
            return;
        } catch (Throwable $e) {
            Log::error('CreateNcrOnComplaintRequested failed unexpectedly', [
                'complaint_id' => $complaint->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            $updated->ncr_handoff_status?->value === 'manual_required' ? 'manual_required' : 'completed',
            $updated->ncr_handoff_status?->value === 'manual_required'
                ? 'complaint_ncr_manual_required'
                : 'complaint_ncr_generated',
            $updated->ncr_handoff_status?->value === 'manual_required'
                ? 'Fix the Quality/NCR setup, then retry the handoff.'
                : "NCR was opened for complaint {$updated->complaint_number}.",
        );
    }
}
