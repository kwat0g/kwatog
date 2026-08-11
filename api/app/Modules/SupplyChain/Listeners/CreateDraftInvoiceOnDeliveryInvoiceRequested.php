<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SystemActorService;
use App\Modules\SupplyChain\Events\DeliveryInvoiceRequested;
use App\Modules\SupplyChain\Exceptions\DeliveryInvoiceHandoffException;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry only the failed delivery → draft-invoice handoff.
 *
 * The original confirmation has already committed. Expected accounting/data
 * problems become an explicit manual outcome; unexpected infrastructure
 * failures are rethrown so the queue and recovery ledger retain retry truth.
 */
class CreateDraftInvoiceOnDeliveryInvoiceRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly SystemActorService $actors,
    ) {}

    public function handle(DeliveryInvoiceRequested $event): void
    {
        $delivery = Delivery::query()->find($event->delivery->id);
        if (! $delivery || $delivery->status?->value !== 'confirmed') {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'delivery_missing_or_not_confirmed',
            );
            return;
        }

        if ($delivery->invoice_id !== null) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'invoice_already_linked',
            );
            return;
        }

        // Prefer the original confirmer for attribution. The configured
        // system actor is the recovery fallback when that user was removed.
        $by = $delivery->confirmed_by
            ? \App\Modules\Auth\Models\User::query()->find($delivery->confirmed_by)
            : null;
        $by ??= $this->actors->resolve();

        if (! $by) {
            $this->deliveries->markInvoiceHandoffManual($delivery->id);
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'invoice_handoff_actor_missing',
                'Configure an automation actor or create the customer invoice manually.',
            );
            return;
        }

        try {
            $updated = $this->deliveries->retryInvoiceHandoff($delivery, $by);
        } catch (DeliveryInvoiceHandoffException|BusinessRuleException $e) {
            Log::warning('CreateDraftInvoiceOnDeliveryInvoiceRequested requires manual action', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'invoice_handoff_manual_required',
                'Fix the accounting setup, then replay this handoff or create the invoice manually.',
            );
            return;
        } catch (Throwable $e) {
            Log::error('CreateDraftInvoiceOnDeliveryInvoiceRequested failed unexpectedly', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'customer_invoice_staged',
            $updated->invoice_id
                ? "Draft customer invoice was linked to delivery {$updated->delivery_number}."
                : null,
        );
    }
}
