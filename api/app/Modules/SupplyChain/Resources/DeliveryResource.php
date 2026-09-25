<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Enums\DeliveryInvoiceHandoffStatus;
use App\Modules\SupplyChain\Enums\DeliveryCocHandoffStatus;
use App\Modules\Quality\Enums\InspectionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
// ADV7 — Proof of Delivery fields surfaced via receiver_* + proofs[].
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof DeliveryStatus
            ? $this->status
            : DeliveryStatus::tryFrom((string) $this->status);
        $hasAttemptOutcome = $this->relationLoaded('attemptOutcome') && $this->attemptOutcome !== null;
        $canReportAttempt = $status === DeliveryStatus::InTransit && ! $hasAttemptOutcome;

        $this->resource->loadMissing(['stockReservationBatch', 'stockReservations.item', 'stockReservations.location', 'stockReservations.deliveryItem', 'costHandoffs']);
        $reservation = $this->stockReservationBatch;
        $transit = $this->cost_recognition_mode?->value === 'transit';
        $costSummary = function (string $kind) use ($request): array {
            $handoff = $this->costHandoffs->where('handoff_type', $kind)->sortByDesc('id')->first();
            $state = $handoff?->status?->value ?? 'pending';
            return [
                'status' => $state, 'amount' => (string) ($handoff?->target_amount ?? '0.00'),
                'message' => $handoff?->message,
                'can_retry' => $state === 'manual_required' && ($request->user()?->hasPermission('accounting.journals.post') ?? false),
            ];
        };

        return [
            'id'                  => $this->hash_id,
            'delivery_number'     => $this->delivery_number,
            'cost_recognition_mode' => $this->cost_recognition_mode?->value ?? 'legacy',
            'stock_reservation_status' => $reservation?->status?->value ?? 'unreserved',
            'stock_reservation_batch_id' => $reservation?->hash_id,
            'can_reserve_stock' => ! $reservation && in_array($status, [DeliveryStatus::Scheduled, DeliveryStatus::Loading], true)
                && (($request->user()?->hasPermission('supply_chain.deliveries.create') ?? false)
                    || ($request->user()?->hasPermission('inventory.adjust') ?? false)),
            'stock_reservation_allocations' => $this->stockReservations->map(static fn ($row): array => [
                'delivery_item_id' => $row->deliveryItem->hash_id,
                'item' => ['id' => $row->item->hash_id, 'code' => $row->item->code, 'name' => $row->item->name],
                'location_id' => $row->location->hash_id, 'location_code' => $row->location->code,
                'quantity' => $row->quantity, 'consumed_quantity' => $row->consumed_quantity,
                'lot_number' => $row->lot_number, 'expiry_date' => $row->expiry_date?->toDateString(),
            ])->all(),
            'cogs_handoff' => $this->when($transit, fn () => $costSummary('customer_cogs')),
            'loss_handoff' => $this->when($transit && $hasAttemptOutcome && $this->attemptOutcome->reconciled_at, fn () => $costSummary('unaccounted_loss')),
            'status'              => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'status_label'        => ($status = $this->status instanceof DeliveryStatus ? $this->status : DeliveryStatus::tryFrom((string) $this->status))?->label() ?? (string) $this->status,
            'can_confirm' => $this->whenLoaded('proofs', fn () => $status === DeliveryStatus::Delivered && $this->proofs->isNotEmpty() && $this->blockingReturnCase === null),
            'can_cancel' => $status?->canTransitionTo(DeliveryStatus::Cancelled) ?? false,
            'next_status'         => $nextStatus = $this->nextStatus($status),
            'next_status_label'   => $nextStatus ? DeliveryStatus::from($nextStatus)->label() : null,
            'scheduled_date'      => optional($this->scheduled_date)?->toDateString(),
            'original_scheduled_date' => optional($this->original_scheduled_date)?->toDateString(),
            'reschedule_count'    => (int) ($this->reschedule_count ?? 0),
            'reschedules'         => $this->whenLoaded('reschedules', fn () => $this->reschedules->map(fn ($r) => [
                'id'       => $r->hash_id,
                'from_date' => optional($r->from_date)?->toDateString(),
                'to_date'  => optional($r->to_date)?->toDateString(),
                'reason'   => $r->reason,
                'rescheduled_by' => $r->relationLoaded('rescheduledBy') && $r->rescheduledBy ? [
                    'id'   => $r->rescheduledBy->hash_id,
                    'name' => $r->rescheduledBy->name,
                ] : null,
                'created_at' => optional($r->created_at)?->toISOString(),
            ])->all()),
            'departed_at'         => optional($this->departed_at)?->toISOString(),
            'delivered_at'        => optional($this->delivered_at)?->toISOString(),
            'confirmed_at'        => optional($this->confirmed_at)?->toISOString(),
            'invoice_handoff'     => [
                'status' => $this->invoice_handoff_status instanceof DeliveryInvoiceHandoffStatus
                    ? $this->invoice_handoff_status->value
                    : (string) $this->invoice_handoff_status,
                'status_label' => ($handoff = $this->invoice_handoff_status instanceof DeliveryInvoiceHandoffStatus
                    ? $this->invoice_handoff_status
                    : DeliveryInvoiceHandoffStatus::tryFrom((string) $this->invoice_handoff_status))?->label(),
                'message' => $this->invoice_handoff_message,
                'attempted_at' => optional($this->invoice_handoff_at)?->toISOString(),
            ],
            'coc_handoff'         => [
                'status' => $this->coc_handoff_status instanceof DeliveryCocHandoffStatus
                    ? $this->coc_handoff_status->value
                    : (string) $this->coc_handoff_status,
                'status_label' => ($coc = $this->coc_handoff_status instanceof DeliveryCocHandoffStatus
                    ? $this->coc_handoff_status
                    : DeliveryCocHandoffStatus::tryFrom((string) $this->coc_handoff_status))?->label(),
                'message' => $this->coc_handoff_message,
                'attempted_at' => optional($this->coc_handoff_at)?->toISOString(),
            ],
            'receipt_photo_url'   => $this->receipt_photo_path ? "/api/v1/supply-chain/deliveries/{$this->hash_id}/receipt-photo" : null,
            'notes'               => $this->notes,
            // ADV7 — Proof of Delivery receiver capture fields.
            'receiver_name'       => $this->receiver_name,
            'receiver_position'   => $this->receiver_position,
            'received_at'         => optional($this->received_at)?->toISOString(),
            'delivery_remarks'    => $this->delivery_remarks,
            'billing_hold' => $this->whenLoaded('blockingReturnCase', fn () => $this->blockingReturnCase ? [
                'case_id' => $this->blockingReturnCase->hash_id,
                'case_number' => $this->blockingReturnCase->case_number,
                'message' => 'Resolve this problem report before confirming or billing the delivery.',
            ] : null),
            'can_report_attempt_outcome' => $canReportAttempt,
            'attempt_outcome_reasons' => ($canReportAttempt || ($hasAttemptOutcome && ! $this->attemptOutcome->reconciled_at)) ? \App\Modules\SupplyChain\Enums\DeliveryAttemptReason::options() : [],
            'can_receive_truck_return' => $status === DeliveryStatus::ReturnPending
                && $hasAttemptOutcome
                && $this->attemptOutcome->reconciled_at === null
                && ($request->user()?->hasPermission('return_management.receive') ?? false),
            'has_attempt_outcome' => $hasAttemptOutcome,
            'attempt_outcome' => $hasAttemptOutcome
                ? (new DeliveryAttemptOutcomeResource($this->attemptOutcome))->resolve($request)
                : null,
            'quantity_discrepancy' => $this->whenLoaded('quantityDiscrepancy', fn () => $this->quantityDiscrepancy
                ? new DeliveryQuantityDiscrepancyResource($this->quantityDiscrepancy) : null),
            'proofs'              => $this->whenLoaded('proofs', fn () => $this->proofs->map(fn ($p) => [
                'id'          => $p->hash_id,
                'proof_type'  => $p->proof_type,
                'file_name'   => $p->file_name,
                'file_size'   => $p->file_size,
                'mime_type'   => $p->mime_type,
                'is_image'    => $p->mime_type ? str_starts_with((string) $p->mime_type, 'image/') : false,
                'notes'       => $p->notes,
                'view_url'    => "/api/v1/supply-chain/deliveries/{$this->hash_id}/proofs/{$p->hash_id}/view",
                'uploader'    => $p->relationLoaded('uploader') && $p->uploader ? [
                    'id'   => $p->uploader->hash_id,
                    'name' => $p->uploader->name,
                ] : null,
                'uploaded_at' => optional($p->created_at)?->toISOString(),
            ])->all()),
            'proof_count'         => $this->whenLoaded('proofs', fn () => $this->proofs->count()),
            'sales_order'         => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
                'customer'  => $this->salesOrder->relationLoaded('customer') && $this->salesOrder->customer ? [
                    'id'   => $this->salesOrder->customer->hash_id,
                    'name' => $this->salesOrder->customer->name,
                ] : null,
            ] : null),
            'vehicle'             => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id'           => $this->vehicle->hash_id,
                'plate_number' => $this->vehicle->plate_number,
                'name'         => $this->vehicle->name,
            ] : null),
            'driver'              => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id'   => $this->driver->hash_id,
                'name' => $this->driver->name,
            ] : null),
            'confirmer'           => $this->whenLoaded('confirmer', fn () => $this->confirmer ? [
                'id'   => $this->confirmer->hash_id,
                'name' => $this->confirmer->name,
            ] : null),
            'invoice'             => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'             => $this->invoice->hash_id,
                'invoice_number' => $this->invoice->invoice_number,
                'total_amount'   => (string) $this->invoice->total_amount,
                'status'         => $this->invoice->status instanceof \BackedEnum ? $this->invoice->status->value : $this->invoice->status,
            ] : null),
            // ADV3 — IATF 16949 outgoing shipment lot (one per delivery).
            'shipment_lot'        => $this->whenLoaded('shipmentLot', fn () => $this->shipmentLot ? [
                'id'           => $this->shipmentLot->hash_id,
                'lot_number'   => $this->shipmentLot->lot_number,
                'lot_date'     => optional($this->shipmentLot->lot_date)?->toDateString(),
                'quantity'     => (int) $this->shipmentLot->quantity,
                'product'      => $this->shipmentLot->product ? [
                    'id'          => $this->shipmentLot->product->hash_id,
                    'part_number' => $this->shipmentLot->product->part_number ?? null,
                    'name'        => $this->shipmentLot->product->name ?? null,
                ] : null,
                'customer'     => $this->shipmentLot->customer ? [
                    'id'   => $this->shipmentLot->customer->hash_id,
                    'name' => $this->shipmentLot->customer->name ?? null,
                ] : null,
                'work_order_count' => is_array($this->shipmentLot->work_order_ids) ? count($this->shipmentLot->work_order_ids) : 0,
            ] : null),
            'preparation' => $this->preparation ?? [],
            'items'               => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id'                  => $i->hash_id,
                'sales_order_item_id' => optional($i->salesOrderItem)?->hash_id,
                'stock_movement_id'   => optional($i->stockMovement)?->hash_id,
                'stock_movements' => $i->relationLoaded('stockMovements') ? $i->stockMovements->map(fn ($movement): array => [
                    'id' => $movement->hash_id,
                    'quantity' => (string) $movement->quantity,
                    'lot_number' => $movement->lot_number,
                    'from_location' => $movement->fromLocation?->full_code,
                ])->all() : [],
                'inspection'          => $i->relationLoaded('inspection') && $i->inspection ? [
                    'id'                => $i->inspection->hash_id,
                    'inspection_number' => $i->inspection->inspection_number,
                    'status'            => $i->inspection->status instanceof \BackedEnum ? $i->inspection->status->value : $i->inspection->status,
                    'status_label'      => InspectionStatus::tryFrom((string) ($i->inspection->status instanceof \BackedEnum ? $i->inspection->status->value : $i->inspection->status))?->label() ?? (string) $i->inspection->status,
                ] : null,
                'product' => $i->salesOrderItem?->product ? [
                    'part_number' => $i->salesOrderItem->product->part_number,
                    'name' => $i->salesOrderItem->product->name,
                ] : null,
                'unit_of_measure' => $i->salesOrderItem?->product?->unit_of_measure,
                'quantity'            => (float) $i->quantity,
                'quantity_dispatched' => (string) $i->quantity,
                'customer_received_quantity' => $i->customer_received_quantity === null
                    ? null : (string) $i->customer_received_quantity,
                'unit_price'          => (string) $i->unit_price,
            ])->all()),
            'created_at'          => optional($this->created_at)?->toISOString(),
            'updated_at'          => optional($this->updated_at)?->toISOString(),
            'deleted_at'          => optional($this->deleted_at)?->toIso8601String(),
        ];
    }

    private function nextStatus(?DeliveryStatus $status): ?string
    {
        // Delivery attempts and final depot counts are a trusted service
        // boundary; a generic status option must never complete that ledger.
        if (! $status || $status === DeliveryStatus::ReturnPending) return null;
        foreach (DeliveryStatus::cases() as $candidate) {
            if (in_array($candidate, [DeliveryStatus::ReturnPending, DeliveryStatus::Returned], true)) continue;
            if ($status->canTransitionTo($candidate)) return $candidate->value;
        }
        return null;
    }
}
