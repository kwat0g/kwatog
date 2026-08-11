<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Purchasing\Enums\SupplierDispatchStatus;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Services\SupplierDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class PrepareSupplierDispatch implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly SupplierDispatchService $dispatches) {}

    public function handle(PurchaseOrderApproved $event): void
    {
        $dispatch = $this->dispatches->prepareForApproved($event->purchaseOrder);
        if ($dispatch) {
            Log::info('PrepareSupplierDispatch completed', [
                'purchase_order_id' => $event->purchaseOrder->id,
                'dispatch_id' => $dispatch->id,
                'status' => $dispatch->status?->value,
                'channel' => $dispatch->channel,
            ]);

            $status = $dispatch->status;
            if ($status === SupplierDispatchStatus::PortalAvailable) {
                app(ChainListenerRunService::class)->recordOutcome(
                    'manual_required',
                    'supplier_portal_confirmation_required',
                    'The supplier portal has the approved PO; confirm transmission after supplier notification.',
                );
                return;
            }
            if ($status === SupplierDispatchStatus::ManualRequired) {
                app(ChainListenerRunService::class)->recordOutcome(
                    'manual_required',
                    'supplier_dispatch_manual_transmission_required',
                    (string) ($dispatch->last_error ?: 'Transmit the approved PO through the configured supplier channel.'),
                );
                return;
            }

            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                $status === SupplierDispatchStatus::Confirmed
                    ? 'supplier_dispatch_already_confirmed'
                    : 'supplier_dispatch_prepared',
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            'dispatch_not_actionable_or_already_terminal',
        );
    }
}
