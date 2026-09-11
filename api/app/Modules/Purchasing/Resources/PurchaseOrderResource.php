<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->hash_id,
            'po_number'              => $this->po_number,
            'date'                   => optional($this->date)->toDateString(),
            'expected_delivery_date' => optional($this->expected_delivery_date)->toDateString(),
            'confirmed_delivery_date'=> optional($this->confirmed_delivery_date)->toDateString(),
            'subtotal'               => (string) $this->subtotal,
            'vat_amount'             => (string) $this->vat_amount,
            'total_amount'           => (string) $this->total_amount,
            'is_vatable'             => (bool) $this->is_vatable,
            'status'                 => (string) $this->status?->value,
            'status_label'           => $this->status?->label() ?? (string) $this->status,
            // B1 — an accepted goods receipt is the real precondition for
            // billing (BillService::assertBillProvenance refuses without one).
            // Deriving this from status alone advertised an enabled Bill
            // button on POs whose receipt was still pending QC.
            'is_billable'            => (bool) ($this->has_accepted_receipt ?? false),
            'latest_response'        => $this->latestResponseBlock(),
            'requires_vp_approval'   => (bool) $this->requires_vp_approval,
            'is_auto_generated'      => (bool) $this->is_auto_generated,
            'current_approval_step'  => (int) $this->current_approval_step,
            'has_overdue_approval'   => $this->relationLoaded('approvalRecords')
                ? $this->approvalRecords->contains(fn ($r) => $r->action === 'pending' && $r->is_overdue)
                : false,
            'approved_at'            => optional($this->approved_at)->toIso8601String(),
            'sent_to_supplier_at'    => optional($this->sent_to_supplier_at)->toIso8601String(),
            'supplier_dispatch'     => $this->whenLoaded('supplierDispatch', function () use ($request): ?array {
                $dispatch = $this->supplierDispatch;
                if ($dispatch === null) {
                    return null;
                }
                $data = [
                    'status'           => $dispatch->status?->value,
                    'status_label'     => $dispatch->status?->label() ?? (string) $dispatch->status,
                    'channel'          => $dispatch->channel,
                    'attempts'         => (int) $dispatch->attempts,
                    'recipient_count'  => (int) $dispatch->recipient_count,
                    'queued_at'        => optional($dispatch->queued_at)->toIso8601String(),
                    'last_attempt_at'  => optional($dispatch->last_attempt_at)->toIso8601String(),
                    'published_at'     => optional($dispatch->published_at)->toIso8601String(),
                    'confirmed_at'     => optional($dispatch->confirmed_at)->toIso8601String(),
                ];

                // The portal may consume this resource too; keep internal
                // provider errors out of the supplier-facing response.
                if (! $request->is('api/v1/b2b/supplier/*')) {
                    $data['last_error'] = $dispatch->last_error;
                    $data['metadata'] = $dispatch->metadata;
                }

                return $data;
            }),
            'budget_warning_level'   => $this->budget_warning_level,
            'budget_warning_message' => $this->budget_warning_message,
            'budget_acknowledged_at' => optional($this->budget_acknowledged_at)->toIso8601String(),
            'remarks'                => $this->remarks,
            'incoterm'               => $this->incoterm?->value,
            'quantity_received_pct'  => $this->quantity_received_percent,
            'quantity_accepted_pct'  => $this->quantity_accepted_percent,
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
                'status_label'  => $g->status?->label() ?? (string) $g->status,
            ])->all()),
            'bills'                  => $this->whenLoaded('bills', fn () => $this->bills->map(fn ($b) => [
                'id'           => $b->hash_id,
                'bill_number'  => $b->bill_number,
                'total_amount' => (string) $b->total_amount,
                'balance'      => (string) $b->balance,
                'status'       => (string) $b->status?->value,
                'status_label' => $b->status?->label() ?? (string) $b->status,
                'due_date'     => optional($b->due_date)->toDateString(),
                'has_variances' => (bool) $b->has_variances,
                'three_way_overridden' => (bool) $b->three_way_overridden,
                'three_way_review_status' => method_exists($b, 'threeWayReviewStatus') ? $b->threeWayReviewStatus() : null,
            ])->all()),
            'approval_records'       => $this->whenLoaded('approvalRecords', fn () => $this->approvalRecords->map(fn ($r) => [
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
            'creator'                => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id, 'name' => $this->creator->name,
            ] : null),
            'approver'               => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->hash_id, 'name' => $this->approver->name,
            ] : null),
            'created_at'             => optional($this->created_at)->toIso8601String(),
            'updated_at'             => optional($this->updated_at)->toIso8601String(),
            'deleted_at'             => optional($this->deleted_at)?->toIso8601String(),
            // PU-13 — action decisions for detail/action responses, mirroring
            // PurchaseRequestResource. Null on the paginated list to keep the
            // delegation/step queries off per-row execution.
            'actions'                => $request->route('purchaseOrder') !== null && $request->user()
                ? app(PurchaseOrderAccessPolicy::class)->actionsFor($request->user(), $this->resource)
                : null,
        ];
    }

    /**
     * The supplier's most recent reply. Shared shape with the supplier-portal
     * resource and a strict subset of PurchaseOrderResponseResource — keep the
     * three in sync when changing the contract.
     *
     * @return array<string, mixed>|null
     */
    private function latestResponseBlock(): ?array
    {
        if (! $this->relationLoaded('latestResponse') || ! $this->latestResponse) {
            return null;
        }

        $response = $this->latestResponse;

        return [
            'id'                     => $response->hash_id,
            'type'                   => $response->response_type?->value ?? (string) $response->response_type,
            'status'                 => $response->status?->value ?? (string) $response->status,
            'proposed_delivery_date' => optional($response->proposed_delivery_date)->toDateString(),
            'notes'                  => $response->notes,
            'responded_at'           => optional($response->responded_at)->toIso8601String(),
            'resolved_at'            => optional($response->resolved_at)->toIso8601String(),
            'resolution_notes'       => $response->resolution_notes,
            'items'                  => $response->relationLoaded('items')
                ? $response->items->map(static fn ($item): array => [
                    'purchase_order_item_id' => $item->purchase_order_item_id !== null
                        ? app('hashids')->encode((int) $item->purchase_order_item_id)
                        : null,
                    'proposed_quantity'      => $item->proposed_quantity !== null ? (string) $item->proposed_quantity : null,
                    'proposed_unit_price'    => $item->proposed_unit_price !== null ? (string) $item->proposed_unit_price : null,
                    'reason'                 => $item->reason,
                ])->values()->all()
                : [],
        ];
    }
}
