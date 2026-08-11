<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Services\SupplierDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * A cancellation event can be replayed independently of the originating
 * request, so close the supplier dispatch ledger through its idempotent
 * service boundary rather than mutating the row directly.
 */
class CloseSupplierDispatchOnPurchaseOrderCancelled implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly SupplierDispatchService $dispatches) {}

    public function handle(PurchaseOrderCancelled $event): void
    {
        $dispatch = $this->dispatches->cancelForPurchaseOrder(
            $event->purchaseOrder,
            'Purchase order was cancelled; supplier dispatch is no longer actionable.',
        );

        if ($dispatch) {
            Log::info('CloseSupplierDispatchOnPurchaseOrderCancelled completed', [
                'purchase_order_id' => $event->purchaseOrder->id,
                'dispatch_id' => $dispatch->id,
            ]);

            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'supplier_dispatch_closed',
                'Supplier dispatch was closed with the cancelled purchase order.',
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            'supplier_dispatch_absent_or_already_reconciled',
        );
    }
}
