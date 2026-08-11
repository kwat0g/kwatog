<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class GoodsReceiptNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'grn_number'      => $this->grn_number,
            'received_date'   => optional($this->received_date)->toDateString(),
            'status'          => (string) $this->status?->value,
            'status_label'    => Str::headline((string) ($this->status?->value ?? $this->status)),
            'rejected_reason' => $this->rejected_reason,
            'remarks'         => $this->remarks,
            'accepted_at'     => optional($this->accepted_at)->toIso8601String(),
            'incoming_qc_handoff' => [
                'status' => $this->incoming_qc_handoff_status?->value,
                'status_label' => $this->incoming_qc_handoff_status?->label(),
                'message' => $this->incoming_qc_handoff_message,
                'at' => optional($this->incoming_qc_handoff_at)->toIso8601String(),
            ],
            'vendor'          => $this->whenLoaded('vendor', fn () => [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ]),
            'purchase_order'  => $this->whenLoaded('purchaseOrder', fn () => [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
                // 2026-08-08 — P2P stepper: the PR behind this PO.
                'purchase_request' => $this->purchaseOrder->relationLoaded('purchaseRequest')
                    && $this->purchaseOrder->purchaseRequest
                    ? [
                        'id'        => $this->purchaseOrder->purchaseRequest->hash_id,
                        'pr_number' => $this->purchaseOrder->purchaseRequest->pr_number,
                    ]
                    : null,
            ]),
            'receiver'        => $this->whenLoaded('receiver', fn () => $this->receiver ? [
                'id'   => $this->receiver->hash_id,
                'name' => $this->receiver->name,
            ] : null),
            'acceptor'        => $this->whenLoaded('acceptor', fn () => $this->acceptor ? [
                'id'   => $this->acceptor->hash_id,
                'name' => $this->acceptor->name,
            ] : null),
            // 2026-08-08 — auto-bill chain: the draft supplier bill staged from
            // this receipt (null until the GRN is accepted and the listener runs).
            'bill'            => $this->whenLoaded('bills', fn () => $this->bills->first() ? [
                'id'           => $this->bills->first()->hash_id,
                'bill_number'  => $this->bills->first()->bill_number,
                'status'       => (string) $this->bills->first()->status?->value,
                'status_label' => $this->bills->first()->status?->label() ?? (string) $this->bills->first()->status,
                'total_amount' => (string) $this->bills->first()->total_amount,
            ] : null),
            'items'           => GrnItemResource::collection($this->whenLoaded('items')),
            'created_at'      => optional($this->created_at)->toIso8601String(),
        ];
    }
}
