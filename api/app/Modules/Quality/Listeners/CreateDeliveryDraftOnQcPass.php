<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C1. After an outgoing inspection passes, draft a
 * delivery against the original sales order. Warehouse picks it up,
 * driver delivers, customer confirms — at which point CRM auto-creates
 * the invoice (existing DeliveryConfirmed listener path).
 *
 * Stage filter: only acts on outgoing inspections linked to a WO.
 * Idempotent: skips if a delivery already exists for the SO+WO pair.
 * Best-effort.
 */
class CreateDeliveryDraftOnQcPass implements ShouldQueue
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function handle(InspectionPassed $event): void
    {
        try {
            $inspection = $event->inspection;
            if ($inspection->stage?->value !== InspectionStage::Outgoing->value) return;
            if ($inspection->entity_type?->value !== 'work_order' && $inspection->entity_type !== 'work_order') return;

            $wo = WorkOrder::find($inspection->entity_id);
            if (! $wo || ! $wo->sales_order_id) return;

            // Idempotent — one delivery per (SO, WO) pair. The DeliveryItem
            // table tracks the WO via its inspection link; querying for an
            // existing delivery that already references this WO is the
            // simplest dedup.
            $alreadyExists = Delivery::query()
                ->where('sales_order_id', $wo->sales_order_id)
                ->whereHas('items', fn ($q) => $q->where('inspection_id', $inspection->id))
                ->exists();
            if ($alreadyExists) return;

            DB::transaction(function () use ($wo, $inspection) {
                $delivery = Delivery::create([
                    'delivery_number' => $this->sequences->generate('delivery'),
                    'sales_order_id'  => $wo->sales_order_id,
                    'status'          => DeliveryStatus::Scheduled->value,
                    'scheduled_date'  => Carbon::now()->addDay()->toDateString(),
                    'notes'           => "Auto-drafted from WO {$wo->wo_number} on outgoing QC pass.",
                    // System-initiated draft — attribute to the WO creator so
                    // the NOT NULL constraint on deliveries.created_by holds.
                    'created_by'      => $wo->created_by,
                ]);

                if ($wo->sales_order_item_id) {
                    // L-7 — inherit unit_price from the parent SO line so the
                    // auto-invoice path (C-1) produces a real-amount invoice.
                    // Fallback to '0.00' only if the SO item is missing (legacy
                    // data path) — better than failing the delivery draft.
                    $soItem = SalesOrderItem::find($wo->sales_order_item_id);
                    $unitPrice = $soItem?->unit_price !== null ? (string) $soItem->unit_price : '0.00';

                    DeliveryItem::create([
                        'delivery_id'         => $delivery->id,
                        'sales_order_item_id' => $wo->sales_order_item_id,
                        'inspection_id'      => $inspection->id,
                        'quantity'           => (string) ($wo->quantity_good ?: $wo->quantity_produced ?: 0),
                        'unit_price'         => $unitPrice,
                    ]);
                }
            });

            // Notify ImpEx / warehouse so they can pick + dispatch.
            try {
                User::query()
                    ->whereHas('role', fn ($q) => $q->whereIn('slug', ['impex_officer', 'warehouse_staff']))
                    ->where('is_active', true)
                    ->get()
                    ->each(function (User $user) use ($wo) {
                        $user->notifications()->create([
                            'id'              => (string) Str::uuid(),
                            'type'            => 'chain.delivery_drafted',
                            'notifiable_type' => $user::class,
                            'notifiable_id'   => $user->id,
                            'data'            => [
                                'wo_id'     => $wo->hash_id,
                                'wo_number' => $wo->wo_number,
                                'message'   => "Outgoing QC passed — delivery drafted for WO {$wo->wo_number}.",
                                'link'      => "/supply-chain/deliveries",
                            ],
                            'read_at'         => null,
                        ]);
                    });
            } catch (\Throwable $e) {
                Log::debug('CreateDeliveryDraftOnQcPass notification failed', ['error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            Log::warning('CreateDeliveryDraftOnQcPass failed', [
                'inspection_id' => $event->inspection->id ?? null,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
