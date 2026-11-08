<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Common\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class GoodsReceiptNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $status = (string) ($this->status?->value ?? $this->status);
        $canReceive = $user !== null && $user->can('inventory.grn.create');
        $canManageIncomingQc = $user !== null && $user->can('quality.inspections.manage');
        $canPostJournal = $user !== null && $user->can('accounting.journal.post');
        $canReportProblem = $user !== null && $user->can('return_management.manage');
        $canRejectRemainder = $status === 'partial_accepted'
            && $this->remainder_rejected_at === null
            && $this->relationLoaded('items')
            && $this->items->some(
                fn ($row) => bccomp((string) $row->quantity_received, (string) $row->quantity_accepted, 3) > 0
            );
        $hasSupplierShortage = $this->relationLoaded('supplierShipment')
            && $this->supplierShipment !== null
            && $this->supplierShipment->relationLoaded('items')
            && $this->relationLoaded('items')
            && $this->supplierShipment->items->contains(function ($shipmentLine): bool {
                $receivedBase = $this->items
                    ->where('purchase_order_item_id', $shipmentLine->purchase_order_item_id)
                    ->reduce(
                        static fn (string $sum, $row): string => bcadd($sum, (string) $row->quantity_received, 3),
                        '0.000',
                    );
                $received = $shipmentLine->purchaseOrderItem?->purchaseQuantity($receivedBase, 2) ?? '0.00';

                return bccomp((string) $received, (string) $shipmentLine->quantity_shipped, 2) < 0;
            });
        $availableActions = [];

        if ($status === 'draft' && $canReceive) {
            $availableActions[] = 'finalize';
        }
        if (in_array($status, ['pending_qc', 'partial_accepted'], true) && $canReceive) {
            $availableActions[] = 'accept';
            $availableActions[] = 'reject';
        }
        if ($status === 'pending_qc'
            && $canManageIncomingQc
            && ! in_array($this->incoming_qc_handoff_status?->value, ['generated', 'not_required'], true)) {
            $availableActions[] = 'retry_incoming_qc';
        }
        if (in_array($status, ['accepted', 'partial_accepted'], true)
            && $canPostJournal
            && $this->journal_entry_id === null) {
            $availableActions[] = 'retry_gl';
        }
        if ($status === 'partial_accepted' && $canReceive && $canRejectRemainder) {
            $availableActions[] = 'reject_remainder';
        }
        if ($status !== 'draft' && $canReportProblem) {
            $availableActions[] = 'report_problem';
        }
        if ($status !== 'draft' && $canReceive && ! $canReportProblem && $hasSupplierShortage) {
            $availableActions[] = 'report_shortage';
        }

        $version = optional($this->updated_at)->format('Y-m-d\\TH:i:s.uP');

        return [
            'id' => $this->hash_id,
            'grn_number' => $this->grn_number,
            'received_date' => optional($this->received_date)->toDateString(),
            'status' => (string) $this->status?->value,
            'status_label' => Str::headline((string) ($this->status?->value ?? $this->status)),
            'rejected_reason' => $this->rejected_reason,
            'remarks' => $this->remarks,
            'accepted_at' => optional($this->accepted_at)->toIso8601String(),
            'remainder_rejected_at' => optional($this->remainder_rejected_at)->toIso8601String(),
            'remainder_rejected_reason' => $this->remainder_rejected_reason,
            'remainder_rejected_by' => $this->whenLoaded('remainderRejectedBy', fn () => $this->remainderRejectedBy ? [
                'id' => $this->remainderRejectedBy->hash_id,
                'name' => $this->remainderRejectedBy->name,
            ] : null),
            'can_reject_remainder' => $canRejectRemainder,
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'version' => $version,
            'permissions' => [
                'view' => $user !== null && $user->can('inventory.view'),
                'receive' => $canReceive,
                'manage_incoming_qc' => $canManageIncomingQc,
                'post_journal' => $canPostJournal,
                'report_problem' => $canReportProblem,
            ],
            'available_actions' => $availableActions,
            'incoming_qc_handoff' => [
                'status' => $this->incoming_qc_handoff_status?->value,
                'status_label' => $this->incoming_qc_handoff_status?->label(),
                'message' => $this->incoming_qc_handoff_message,
                'at' => optional($this->incoming_qc_handoff_at)->toIso8601String(),
            ],
            'qc_inspection' => $this->whenLoaded('qcInspection', fn () => $this->qcInspection ? [
                'id' => $this->qcInspection->hash_id,
                'inspection_number' => $this->qcInspection->inspection_number,
                'stage' => $this->qcInspection->stage?->value,
                'stage_label' => $this->qcInspection->stage?->label(),
                'status' => $this->qcInspection->status?->value,
                'status_label' => $this->qcInspection->status?->label(),
            ] : null),
            'journal_entry' => $this->when(
                $request->user()?->hasPermission('accounting.journal.view')
                    && $this->relationLoaded('journalEntry'),
                fn () => $this->journalEntry ? [
                    'id' => $this->journalEntry->hash_id,
                    'entry_number' => $this->journalEntry->entry_number,
                    'status' => $this->journalEntry->status?->value,
                    'status_label' => $this->journalEntry->status?->label(),
                ] : null,
            ),
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ]),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
                // 2026-08-08 — P2P stepper: the PR behind this PO.
                'purchase_request' => $this->purchaseOrder->relationLoaded('purchaseRequest')
                    && $this->purchaseOrder->purchaseRequest
                    ? [
                        'id' => $this->purchaseOrder->purchaseRequest->hash_id,
                        'pr_number' => $this->purchaseOrder->purchaseRequest->pr_number,
                    ]
                    : null,
            ]),
            'shipment' => $this->whenLoaded('shipment', fn () => $this->shipment ? [
                'id' => $this->shipment->hash_id,
                'shipment_number' => $this->shipment->shipment_number,
                'status' => $this->shipment->status?->value,
            ] : null),
            'supplier_shipment' => $this->whenLoaded('supplierShipment', fn () => $this->supplierShipment ? [
                'id' => $this->supplierShipment->hash_id,
                'tracking_number' => $this->supplierShipment->tracking_number,
                'items' => $this->supplierShipment->items->map(function ($line): array {
                    $receivedBase = $this->items->where('purchase_order_item_id', $line->purchase_order_item_id)
                        ->reduce(static fn (string $sum, $row): string => bcadd($sum, (string) $row->quantity_received, 3), '0.000');
                    $received = $line->purchaseOrderItem?->purchaseQuantity($receivedBase, 2) ?? '0.00';

                    return [
                        'purchase_order_item_id' => $line->purchaseOrderItem?->hash_id,
                        'quantity_shipped' => (string) $line->quantity_shipped,
                        'quantity_received' => $received,
                        'difference' => Money::sub($received, (string) $line->quantity_shipped),
                        'unit' => $line->purchaseOrderItem?->unit,
                    ];
                })->values()->all(),
            ] : null),
            'receiver' => $this->whenLoaded('receiver', fn () => $this->receiver ? [
                'id' => $this->receiver->hash_id,
                'name' => $this->receiver->name,
            ] : null),
            'acceptor' => $this->whenLoaded('acceptor', fn () => $this->acceptor ? [
                'id' => $this->acceptor->hash_id,
                'name' => $this->acceptor->name,
            ] : null),
            // 2026-08-08 — auto-bill chain: the draft supplier bill staged from
            // this receipt (null until the GRN is accepted and the listener runs).
            'bill' => $this->whenLoaded('bills', fn () => $this->bills->first() ? [
                'id' => $this->bills->first()->hash_id,
                'bill_number' => $this->bills->first()->bill_number,
                'status' => (string) $this->bills->first()->status?->value,
                'status_label' => $this->bills->first()->status?->label() ?? (string) $this->bills->first()->status,
                'total_amount' => (string) $this->bills->first()->total_amount,
            ] : null),
            'items' => GrnItemResource::collection($this->whenLoaded('items')),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
