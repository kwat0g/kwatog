<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->hash_id,
            'rma_number'           => $this->rma_number,
            'type'                 => $this->type?->value,
            'type_label'           => $this->type?->label(),
            'status'               => $this->status?->value,
            'status_label'         => $this->status?->label(),
            'is_editable'          => $this->is_editable,
            // RM-01 — the pending step of the approval-records chain, so the
            // detail page can show where the RMA waits without browsing the
            // approval board. Null when the relation is not loaded (list) or
            // nothing is pending (draft, fully approved, rejected, cancelled).
            'pending_approval_step' => $this->relationLoaded('approvalRecords')
                ? $this->approvalRecords->first(fn ($r) => $r->action === 'pending')?->step_order
                : null,
            'has_overdue_approval'  => $this->relationLoaded('approvalRecords')
                ? $this->approvalRecords->contains(fn ($r) => $r->action === 'pending' && $r->is_overdue)
                : false,
            'disposition_status'   => $this->disposition_status,
            'finance_only'         => (bool) $this->finance_only,
            'finance_only_reason'  => $this->finance_only_reason,
            'finance_only_approved_by' => $this->finance_only_approved_by
                ? \App\Common\Support\HashId::encode((int) $this->finance_only_approved_by) : null,
            'inspection_handoff'   => [
                'status'       => $this->inspection_handoff_status?->value,
                'status_label' => $this->inspection_handoff_status?->label(),
                'message'      => $this->inspection_handoff_message,
                'at'           => optional($this->inspection_handoff_at)->toIso8601String(),
            ],

            'reason_code'          => $this->reason_code,
            'reason_description'   => $this->reason_description,
            'customer_notes'       => $this->customer_notes,
            'internal_notes'       => $this->internal_notes,
            'resolution'           => $this->resolution,
            'refund_amount'        => $this->refund_amount ? (string) $this->refund_amount : null,
            'return_date'          => optional($this->return_date)->toDateString(),

            'source_label'         => $this->source_label,

            'sales_order'          => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),

            'invoice'              => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'             => $this->invoice->hash_id,
                'invoice_number' => $this->invoice->invoice_number,
            ] : null),

            'purchase_order'       => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),

            'bill'                 => $this->whenLoaded('bill', fn () => $this->bill ? [
                'id'          => $this->bill->hash_id,
                'bill_number' => $this->bill->bill_number,
            ] : null),

            'customer'             => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),

            'vendor'               => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ] : null),

            'credit_note'          => $this->whenLoaded('creditNote', fn () => $this->creditNote ? [
                'id'                 => $this->creditNote->hash_id,
                'credit_note_number' => $this->creditNote->credit_note_number,
                'type'               => $this->creditNote->type?->value,
                'status'             => $this->creditNote->status?->value,
                'total_amount'       => (string) $this->creditNote->total_amount,
            ] : null),

            'replacement_purchase_order' => $this->whenLoaded('replacementPurchaseOrder', fn () => $this->replacementPurchaseOrder ? [
                'id'        => $this->replacementPurchaseOrder->hash_id,
                'po_number' => $this->replacementPurchaseOrder->po_number,
                'status'    => $this->replacementPurchaseOrder->status?->value,
            ] : null),

            'credit_memo'          => $this->whenLoaded('creditMemo', fn () => $this->creditMemo ? [
                'id'             => $this->creditMemo->hash_id,
                'invoice_number' => $this->creditMemo->invoice_number,
            ] : null),

            // 2026-08-08 — how many units actually moved in/out of stock (sum
            // of the per-line moved quantities): restocked for customer returns,
            // shipped back for supplier returns. Null until dispose() moves.
            'moved_quantity'        => $this->whenLoaded('items', function (): ?string {
                $total = '0.000';
                foreach ($this->items as $item) {
                    $total = bcadd($total, (string) ($item->stock_movement_quantity ?? '0'), 3);
                }

                return bccomp($total, '0', 3) > 0 ? $total : null;
            }),

            'stock_movement'        => $this->whenLoaded('stockMovement', fn () => $this->stockMovement ? [
                'id'            => $this->stockMovement->hash_id,
                'quantity'      => (string) $this->stockMovement->quantity,
                'movement_type' => $this->stockMovement->movement_type?->value,
                'to_location'   => $this->stockMovement->relationLoaded('toLocation') && $this->stockMovement->toLocation ? [
                    'id'   => $this->stockMovement->toLocation->hash_id,
                    'code' => $this->stockMovement->toLocation->code,
                ] : null,
                'from_location' => $this->stockMovement->relationLoaded('fromLocation') && $this->stockMovement->fromLocation ? [
                    'id'   => $this->stockMovement->fromLocation->hash_id,
                    'code' => $this->stockMovement->fromLocation->code,
                ] : null,
            ] : null),

            'inspection'           => $this->whenLoaded('inspection', fn () => $this->inspection ? [
                'id'                => $this->inspection->hash_id,
                'inspection_number' => $this->inspection->inspection_number,
                'status'            => $this->inspection->status?->value,
            ] : null),

            'inspections'          => $this->whenLoaded('inspections', fn () => $this->inspections->map(fn ($inspection): array => [
                'id'                => $inspection->hash_id,
                'inspection_number' => $inspection->inspection_number,
                'status'            => $inspection->status?->value,
                'notes'             => $inspection->notes,
                'product'           => $inspection->relationLoaded('product') && $inspection->product ? [
                    'id'          => $inspection->product->hash_id,
                    'part_number' => $inspection->product->part_number,
                    'name'        => $inspection->product->name,
                ] : null,
            ])->values()),

            'ncr'                  => $this->whenLoaded('ncr', fn () => $this->ncr ? [
                'id'         => $this->ncr->hash_id,
                'ncr_number' => $this->ncr->ncr_number,
                'status'     => $this->ncr->status?->value,
            ] : null),

            'items'                => $this->whenLoaded('items', fn () =>
                ReturnRequestItemResource::collection($this->items)
            ),

            'item_count'           => (int) ($this->items_count ?? $this->items?->count() ?? 0),

            'approval_records'     => $this->whenLoaded('approvalRecords', fn () => $this->approvalRecords->map(fn ($r) => [
                'step_order'    => (int) $r->step_order,
                'role_slug'     => $r->role_slug,
                'action'        => $r->action,
                'remarks'       => $r->remarks,
                'acted_at'      => optional($r->acted_at)->toIso8601String(),
                'approver'      => $r->relationLoaded('approver') && $r->approver ? [
                    'id'   => $r->approver->hash_id,
                    'name' => $r->approver->name,
                ] : null,
                'is_overdue'    => (bool) $r->is_overdue,
                'overdue_hours' => $r->is_overdue ? (int) $r->overdue_hours : null,
            ])->all()),

            'creator'              => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),

            'approved_by'          => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id'   => $this->approver->hash_id,
                'name' => $this->approver->name,
            ] : null),

            'completed_by'         => $this->whenLoaded('completer', fn () => $this->completer ? [
                'id'   => $this->completer->hash_id,
                'name' => $this->completer->name,
            ] : null),

            'rejected_by'          => $this->whenLoaded('rejecter', fn () => $this->rejecter ? [
                'id'   => $this->rejecter->hash_id,
                'name' => $this->rejecter->name,
            ] : null),

            'approved_at'          => optional($this->approved_at)->toIso8601String(),
            'received_at'          => optional($this->received_at)->toIso8601String(),
            'inspected_at'         => optional($this->inspected_at)->toIso8601String(),
            'completed_at'         => optional($this->completed_at)->toIso8601String(),
            'rejected_at'          => optional($this->rejected_at)->toIso8601String(),
            'cancelled_at'         => optional($this->cancelled_at)->toIso8601String(),

            'created_at'           => optional($this->created_at)->toIso8601String(),
            'updated_at'           => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
