<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C1. After an outgoing inspection passes, draft a
 * delivery against the original sales order. Warehouse picks it up,
 * driver delivers, customer confirms — at which point CRM auto-creates
 * the invoice (existing DeliveryConfirmed listener path).
 *
 * Stage filter: only acts on outgoing inspections linked to a WO.
 * Idempotent: skips if a delivery already exists for the SO+WO pair.
 * Stateful failures are rethrown for queue retry; notifications are
 * best-effort.
 */
class CreateDeliveryDraftOnQcPass implements ShouldQueue
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly ?SettingsService $settings = null,
    ) {}

    public function handle(InspectionPassed $event): void
    {
        try {
            // InspectionPassed is queued through the outbox. Re-read the
            // terminal source row so a stale serialized payload cannot draft
            // a delivery for an inspection that is no longer passed.
            $inspection = Inspection::query()->find($event->inspection->id);
            if (! $inspection || $inspection->status !== InspectionStatus::Passed) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_passed_inspection');

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
            if (! $wo || ! $wo->sales_order_id) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'work_order_missing_or_not_customer_linked');

                return;
            }

            // Idempotent — one delivery per (SO, WO) pair. The DeliveryItem
            // table tracks the WO via its inspection link; querying for an
            // existing delivery that already references this WO is the
            // simplest dedup.
            $alreadyExists = Delivery::query()
                ->where('sales_order_id', $wo->sales_order_id)
                ->whereHas('items', fn ($q) => $q->where('inspection_id', $inspection->id))
                ->exists();
            if ($alreadyExists) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'delivery_already_present');

                return;
            }

            $delivery = DB::transaction(function () use ($wo, $inspection): ?Delivery {
                // Serialize auto-drafts with manual delivery creation and
                // re-check idempotency after acquiring the SO lock. The first
                // existence check above is only a fast path.
                $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
                if (! $lockedWo || ! $lockedWo->sales_order_id) {
                    return null;
                }

                $so = SalesOrder::query()->lockForUpdate()->find($lockedWo->sales_order_id);
                if (! $so) {
                    return null;
                }

                $alreadyExists = Delivery::query()
                    ->where('sales_order_id', $so->id)
                    ->whereHas('items', fn ($q) => $q->where('inspection_id', $inspection->id))
                    ->exists();
                if ($alreadyExists) {
                    return null;
                }

                if (! $lockedWo->sales_order_item_id) {
                    throw new BusinessRuleException(
                        "Work order {$lockedWo->wo_number} is linked to sales order {$so->so_number} without a sales-order line."
                    );
                }

                $soItem = SalesOrderItem::query()
                    ->whereKey($lockedWo->sales_order_item_id)
                    ->lockForUpdate()
                    ->first();
                if (! $soItem || (int) $soItem->sales_order_id !== (int) $so->id) {
                    throw new BusinessRuleException(
                        "Work order {$lockedWo->wo_number} references an invalid sales-order line."
                    );
                }

                // A passed output inspection releases its recorded accepted
                // quantity; do not reconstruct a WO aggregate here.
                $quantity = (string) ($inspection->accepted_quantity ?: 0);
                if (bccomp($quantity, '0', 2) <= 0) {
                    throw new BusinessRuleException(
                        "Outgoing inspection {$inspection->inspection_number} has no accepted output quantity available for delivery."
                    );
                }

                app(DeliveryService::class)->assertDeliveryQuantitiesAvailable(
                    $so,
                    [$soItem->id => $quantity],
                );
                app(DeliveryService::class)->lockAndValidateInspectionForDelivery(
                    inspectionId: (int) $inspection->id,
                    salesOrderId: (int) $so->id,
                    salesOrderItemId: (int) $soItem->id,
                    productId: (int) $soItem->product_id,
                    quantity: $quantity,
                );

                // Validate the complete delivery payload before inserting its
                // header. This prevents an empty delivery from committing when
                // a stale or malformed WO loses its SO-line reference.
                $delivery = Delivery::create([
                    'delivery_number' => $this->sequences->generate('delivery'),
                    'sales_order_id' => $so->id,
                    'status' => DeliveryStatus::Scheduled->value,
                    'scheduled_date' => Carbon::now()->addDay()->toDateString(),
                    'notes' => "Auto-drafted from WO {$lockedWo->wo_number} on outgoing QC pass.",
                    // System-initiated draft — attribute to the WO creator so
                    // the NOT NULL constraint on deliveries.created_by holds.
                    'created_by' => $lockedWo->created_by,
                ]);

                // L-7 — inherit unit_price from the parent SO line so the
                // auto-invoice path (C-1) produces a real-amount invoice.
                DeliveryItem::create([
                    'delivery_id' => $delivery->id,
                    'sales_order_item_id' => $soItem->id,
                    'inspection_id' => $inspection->id,
                    'quantity' => $quantity,
                    'unit_price' => $soItem->unit_price !== null ? (string) $soItem->unit_price : '0.00',
                ]);

                return $delivery;
            });

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
                    ->get();

                app(NotificationService::class)->send($recipients, 'chain.delivery_drafted', [
                    'title' => 'Delivery drafted',
                    'message' => "Outgoing QC passed — delivery drafted for WO {$wo->wo_number}.",
                    'link_to' => '/supply-chain/deliveries',
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
