<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Events\OutgoingQuantityReleased;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C1. After an outgoing inspection passes, draft a
 * delivery against the original sales order. Warehouse picks it up,
 * driver delivers, customer confirms — at which point CRM auto-creates
 * the invoice (existing DeliveryConfirmed listener path).
 *
 * Stage filter: only acts on outgoing inspections linked to a WO.
 * Idempotent: only unallocated released quantities are added to the draft.
 * Stateful failures are rethrown for queue retry; notifications are
 * best-effort.
 */
class CreateDeliveryDraftOnQcPass implements ShouldQueue
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly ?SettingsService $settings = null,
    ) {}

    public function handle(InspectionPassed|OutgoingQuantityReleased $event): void
    {
        try {
            // Re-read current release evidence before acting on an outbox event.
            // A late incident may have revoked a previously approved quantity.
            $inspection = Inspection::query()->find($event->inspection->id);
            if (! $inspection || ! $inspection->canReleaseOutgoing()) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_released_inspection');

                return;
            }
            if ($inspection->stage?->value !== InspectionStage::Outgoing->value) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_outgoing_inspection');

                return;
            }
            if ($inspection->entity_type?->value !== 'work_order' && $inspection->entity_type !== 'work_order') {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'non_work_order_inspection');

                return;
            }

            if (! $inspection->work_order_output_id) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'legacy_inspection_has_no_output_provenance');

                return;
            }

            $wo = WorkOrder::find($inspection->entity_id);
            if ($wo?->production_purpose === 'stock' && ! $wo->sales_order_id) {
                foreach (\App\Modules\CRM\Models\SalesOrder::whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
                    ->whereHas('items', fn ($q) => $q->where('product_id', $wo->product_id)->where('fulfillment_policy', 'stock_first'))->orderBy('id')->get() as $order) {
                    app(\App\Modules\MRP\Services\MrpEngineService::class)->runForSalesOrder($order);
                }
                app(ChainListenerRunService::class)->recordOutcome('completed', 'stock_supply_replanned');
                return;
            }
            if (! $wo || ! $wo->sales_order_id) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'work_order_missing_or_not_customer_linked');

                return;
            }

            $delivery = app(DeliveryService::class)->draftReleasedWorkOrder($wo);

            if (! $delivery) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'delivery_already_present_or_source_not_actionable');

                return;
            }

            // Notify ImpEx / warehouse so they can pick + dispatch.
            try {
                $settings = $this->settings ?? app(SettingsService::class);
                $roles = array_values(array_filter((array) $settings->get('quality.outgoing_qc_delivery.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
                $recipients = User::query()
                    ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $user): bool => $user->hasPermission('supply_chain.deliveries.view'));

                app(NotificationService::class)->send($recipients, 'chain.delivery_drafted', [
                    'title' => 'Delivery drafted',
                    'message' => "Outgoing QC passed — delivery drafted for WO {$wo->wo_number}.",
                    'link_to' => "/supply-chain/deliveries/{$delivery->hash_id}",
                    'entity_type' => 'work_order',
                    'entity_id' => $wo->hash_id,
                    'wo_number' => $wo->wo_number,
                ]);
            } catch (\Throwable $e) {
                Log::debug('CreateDeliveryDraftOnQcPass notification failed', ['error' => $e->getMessage()]);
            }

            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'delivery_draft_created',
                "Drafted delivery {$delivery->delivery_number} from outgoing QC pass for WO {$wo->wo_number}.",
            );
        } catch (\Throwable $e) {
            Log::error('CreateDeliveryDraftOnQcPass failed', [
                'inspection_id' => $event->inspection->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
