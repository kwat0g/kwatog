<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SystemActorService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Exceptions\ProductionReceiptHandoffException;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WorkOrderOutputService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Retry only the failed production-output → finished-goods receipt handoff. */
class CreateProductionReceiptOnOutputRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly WorkOrderOutputService $outputs,
        private readonly SystemActorService $actors,
    ) {}

    public function handle(ProductionReceiptRequested $event): void
    {
        $output = WorkOrderOutput::query()->find($event->output->id);
        if (! $output) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'work_order_output_missing',
            );
            return;
        }

        if ((int) $output->good_count <= 0) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'no_good_output_to_receipt',
            );
            return;
        }

        $by = $output->recorded_by
            ? User::query()->find($output->recorded_by)
            : null;
        $by ??= $this->actors->resolve();

        if (! $by) {
            $this->outputs->markProductionReceiptManual($output->id);
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'production_receipt_actor_missing',
                'Configure an automation actor or create the finished-goods receipt manually.',
            );
            return;
        }

        try {
            $updated = $this->outputs->retryProductionReceipt($output, $by);
        } catch (ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException $e) {
            Log::warning('CreateProductionReceiptOnOutputRequested requires manual action', [
                'output_id' => $output->id,
                'reason_code' => $event->reasonCode,
                'error' => $e->getMessage(),
            ]);
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'production_receipt_manual_required',
                'Fix the finished-goods item/location setup, then replay this handoff or create the receipt manually.',
            );
            return;
        } catch (Throwable $e) {
            Log::error('CreateProductionReceiptOnOutputRequested failed unexpectedly', [
                'output_id' => $output->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'production_receipt_generated',
            "Finished-goods receipt was posted for batch {$updated->batch_code}.",
        );
    }
}
