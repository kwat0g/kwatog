<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SystemActorService;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Events\ReturnInspectionRequested;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Retry only the failed RMA → Quality inspection handoff. */
class CreateReturnInspectionOnRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly ReturnRequestService $returns,
        private readonly SystemActorService $actors,
    ) {}

    public function handle(ReturnInspectionRequested $event): void
    {
        $rma = ReturnRequest::query()->find($event->returnRequest->id);
        if (! $rma) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'return_request_missing',
            );
            return;
        }

        if (! in_array($rma->status?->value, ['received', 'inspected'], true)) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'return_request_not_ready_for_inspection',
            );
            return;
        }

        $by = $rma->created_by
            ? User::query()->find($rma->created_by)
            : null;
        $by ??= $this->actors->resolve();

        if (! $by) {
            $this->returns->markInspectionHandoffManual(
                $rma->id,
                'No active inspection actor is available. Configure an automation actor or retry from the RMA.',
            );
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'return_inspection_actor_missing',
                'Configure an automation actor or retry the Quality inspection from the RMA.',
            );
            return;
        }

        try {
            $updated = $this->returns->retryInspectionHandoff($rma, $by);
        } catch (BusinessRuleException $e) {
            Log::warning('CreateReturnInspectionOnRequested requires manual action', [
                'rma_id' => $rma->id,
                'reason_code' => $event->reasonCode,
                'error' => $e->getMessage(),
            ]);
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'return_inspection_manual_required',
                'Fix the Quality inspection setup, then replay this handoff or retry it from the RMA.',
            );
            return;
        } catch (Throwable $e) {
            Log::error('CreateReturnInspectionOnRequested failed unexpectedly', [
                'rma_id' => $rma->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if ($updated->inspection_handoff_status?->value === 'manual_required') {
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'return_inspection_manual_required',
                'Fix the Quality inspection setup, then replay this handoff or retry it from the RMA.',
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            $updated->inspection_handoff_status?->value === 'not_required'
                ? 'return_inspection_not_required'
                : 'return_inspection_staged',
            $updated->inspection_handoff_status?->value === 'not_required'
                ? 'The RMA contains no product-linked lines requiring a Quality inspection.'
                : "Quality inspection(s) were staged for RMA {$updated->rma_number}.",
        );
    }
}
