<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Events\StockMovementGlPostingRequested;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\MovementGlPostingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Retry only the failed stock-movement → journal-entry handoff. */
class PostStockMovementToGlOnRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly MovementGlPostingService $postings) {}

    public function handle(StockMovementGlPostingRequested $event): void
    {
        $movement = StockMovement::query()->find($event->movement->id);
        if (! $movement) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'stock_movement_missing',
            );
            return;
        }

        if ($movement->journal_entry_id !== null) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'journal_entry_already_linked',
            );
            return;
        }

        try {
            $updated = $this->postings->retry($movement);
        } catch (BusinessRuleException $e) {
            Log::warning('PostStockMovementToGlOnRequested requires manual action', [
                'movement_id' => $movement->id,
                'reason_code' => $event->reasonCode,
                'error' => $e->getMessage(),
            ]);
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'movement_gl_posting_manual_required',
                'Fix the Accounting configuration or posting period, then replay this handoff.',
            );
            return;
        } catch (Throwable $e) {
            Log::error('PostStockMovementToGlOnRequested failed unexpectedly', [
                'movement_id' => $movement->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $status = $updated->gl_handoff_status instanceof MovementGlHandoffStatus
            ? $updated->gl_handoff_status
            : MovementGlHandoffStatus::tryFrom((string) $updated->gl_handoff_status);

        if ($status === MovementGlHandoffStatus::Generated) {
            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'movement_gl_posted',
                "Stock movement {$updated->id} was posted to the General Ledger.",
            );
            return;
        }

        if ($status === MovementGlHandoffStatus::ManualRequired) {
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'movement_gl_posting_manual_required',
                'Fix the Accounting configuration or posting period, then replay this handoff.',
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            'movement_gl_posting_not_required',
        );
    }
}
