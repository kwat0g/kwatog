<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->hash_id,
            'po_number'              => $this->po_number,
            'date'                   => optional($this->date)->toDateString(),
            'expected_delivery_date' => optional($this->expected_delivery_date)->toDateString(),
            'subtotal'               => (string) $this->subtotal,
            'vat_amount'             => (string) $this->vat_amount,
            'total_amount'           => (string) $this->total_amount,
            'is_vatable'             => (bool) $this->is_vatable,
            'status'                 => (string) $this->status?->value,
            'requires_vp_approval'   => (bool) $this->requires_vp_approval,
            'current_approval_step'  => (int) $this->current_approval_step,
            'approved_at'            => optional($this->approved_at)->toIso8601String(),
            'sent_to_supplier_at'    => optional($this->sent_to_supplier_at)->toIso8601String(),
            'remarks'                => $this->remarks,
            'quantity_received_pct'  => $this->quantity_received_percent,
            'vendor'                 => $this->whenLoaded('vendor', fn () => [
                'id'             => $this->vendor->hash_id,
                'name'           => $this->vendor->name,
                'contact_person' => $this->vendor->contact_person,
                'email'          => $this->vendor->email,
            ]),
            'purchase_request'       => $this->whenLoaded('purchaseRequest', fn () => $this->purchaseRequest ? [
                'id'        => $this->purchaseRequest->hash_id,
                'pr_number' => $this->purchaseRequest->pr_number,
            ] : null),
            'items'                  => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'goods_receipt_notes'    => $this->whenLoaded('goodsReceiptNotes', fn () => $this->goodsReceiptNotes->map(fn ($g) => [
                'id'            => $g->hash_id,
                'grn_number'    => $g->grn_number,
                'received_date' => optional($g->received_date)->toDateString(),
                'status'        => (string) $g->status?->value,
            ])->all()),
            'bills'                  => $this->whenLoaded('bills', fn () => $this->bills->map(fn ($b) => [
                'id'           => $b->hash_id,
                'bill_number'  => $b->bill_number,
                'total_amount' => (string) $b->total_amount,
                'balance'      => (string) $b->balance,
                'status'       => (string) $b->status,
            ])->all()),
            'approval_records'       => $this->whenLoaded('approvalRecords', fn () => $this->approvalRecords->map(fn ($r) => [
                'step_order' => $r->step_order,
                'role_slug'  => $r->role_slug,
                'action'     => $r->action,
                'remarks'    => $r->remarks,
                'acted_at'   => optional($r->acted_at)->toIso8601String(),
            ])->all()),
            'creator'                => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id, 'name' => $this->creator->name,
            ] : null),
            'approver'               => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->hash_id, 'name' => $this->approver->name,
            ] : null),
            'created_at'             => optional($this->created_at)->toIso8601String(),
            'updated_at'             => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
