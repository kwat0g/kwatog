<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Resources;

use App\Common\Support\HashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\ReturnManagement\Models\ReturnCase */
class ReturnCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $portalAudience = $this->isPortalAudience($request);

        return [
            'id' => $this->hash_id,
            'case_number' => $this->case_number,
            'type' => $this->enumValue($this->type),
            'intake_kind' => $this->enumValue($this->intake_kind) ?? 'discrepancy',
            'can_resolve_trace' => app(\App\Modules\ReturnManagement\Services\ReturnCaseService::class)->canResolveTrace($this->resource, $portalAudience),
            'status' => $this->enumValue($this->status),
            'status_label' => $this->status?->label(),
            'can_revise_agreement' => app(\App\Modules\ReturnManagement\Services\ReturnCaseService::class)->canReviseAgreement($this->resource),
            'description' => $this->description,
            'preferred_resolution' => $this->enumValue($this->preferred_resolution),
            'resolution' => $this->enumValue($this->resolution),
            // Resolution notes are internal review notes. Customer-facing
            // explanations belong in public timeline events.
            'resolution_notes' => $portalAudience ? null : $this->resolution_notes,
            'expected_date' => $this->expected_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),

            'party' => $this->partySummary(),
            'owner' => $this->whenLoaded('owner', fn (): ?array => $this->owner ? [
                'id' => $this->owner->hash_id,
                'name' => $this->owner->name,
            ] : null),
            'source' => $this->sourceSummary(),

            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines
                ->map(fn ($line): array => $this->lineSummary($line))
                ->all()),
            'events' => $this->whenLoaded('events', fn (): array => $this->events
                ->filter(fn ($event): bool => ! $portalAudience || (bool) $event->is_public)
                ->map(fn ($event): array => [
                    'id' => $event->hash_id,
                    'action' => $event->action,
                    'message' => $event->message,
                    'actor_type' => $this->enumValue($event->actor_type),
                    'actor_name' => $event->actor_name,
                    'is_public' => (bool) $event->is_public,
                    'created_at' => $event->created_at?->toIso8601String(),
                    // Metadata is intentionally omitted: it may contain
                    // operational identifiers or private review context.
                ])
                ->values()
                ->all()),
            'attachments' => $this->whenLoaded('attachments', fn (): array => $this->attachments
                ->filter(fn ($attachment): bool => ! $portalAudience || $this->attachmentIsPublic($attachment))
                ->map(fn ($attachment): array => [
                    'id' => $attachment->hash_id,
                    'event_id' => $this->hashForeignKey($attachment->event_id),
                    'file_name' => $attachment->file_name,
                    'mime_type' => $attachment->mime_type,
                    'size' => (int) $attachment->size,
                    'created_at' => $attachment->created_at?->toIso8601String(),
                    // The private filesystem path is never part of the API.
                ])
                ->values()
                ->all()),

            'return_request' => $this->whenLoaded('returnRequest', fn (): ?array => $this->returnRequest ? [
                'id' => $this->returnRequest->hash_id,
                'rma_number' => $this->returnRequest->rma_number,
                'status' => $this->enumValue($this->returnRequest->status),
            ] : null),
            'return_credit_note' => $this->whenLoaded('returnRequest', fn (): ?array => $this->returnRequest?->creditNote ? [
                'id' => $this->returnRequest->creditNote->hash_id,
                'credit_note_number' => $this->returnRequest->creditNote->credit_note_number,
                'status' => $this->enumValue($this->returnRequest->creditNote->status),
                'total_amount' => (string) $this->returnRequest->creditNote->total_amount,
            ] : null),
            'credit_note' => $this->whenLoaded('creditNote', fn (): ?array => $this->creditNote ? [
                'id' => $this->creditNote->hash_id,
                'credit_note_number' => $this->creditNote->credit_note_number,
                'status' => $this->enumValue($this->creditNote->status),
                'total_amount' => (string) $this->creditNote->total_amount,
            ] : null),
            'replacement_order' => $this->replacementOrderSummary(),
            'replacement_delivery' => $this->whenLoaded('replacementDelivery', fn (): ?array => $this->replacementDelivery ? [
                'id' => $this->replacementDelivery->hash_id,
                'delivery_number' => $this->replacementDelivery->delivery_number,
                'status' => $this->enumValue($this->replacementDelivery->status),
            ] : null),
            'resolution_goods_receipt_note' => $this->whenLoaded('resolutionGoodsReceiptNote', fn (): ?array => $this->resolutionGoodsReceiptNote ? [
                'id' => $this->resolutionGoodsReceiptNote->hash_id,
                'grn_number' => $this->resolutionGoodsReceiptNote->grn_number,
                'status' => $this->enumValue($this->resolutionGoodsReceiptNote->status),
            ] : null),
            'resolution_receipts' => $this->whenLoaded('receiptAllocations', fn (): array => $this->receiptAllocations
                ->groupBy('goods_receipt_note_id')->map(function ($allocations): array {
                    $receipt = $allocations->first()->goodsReceiptNote;
                    return [
                        'id' => $receipt->hash_id, 'grn_number' => $receipt->grn_number,
                        'status' => $this->enumValue($receipt->status),
                        'lines' => $allocations->groupBy('return_case_line_id')->map(function ($rows): array {
                            $line = $rows->first()->caseLine;
                            return ['case_line_id' => $line->hash_id, 'description' => $line->description, 'unit' => $line->unit,
                                'quantity' => $rows->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 3), '0.000')];
                        })->values()->all(),
                    ];
                })->values()->all()),
        ];
    }

    private function isPortalAudience(Request $request): bool
    {
        return $request->is('api/v1/b2b/customer/*') || $request->is('api/v1/b2b/supplier/*');
    }

    /** @return array{id: ?string, name: ?string, kind: string}|null */
    private function partySummary(): ?array
    {
        if ($this->type?->value === 'customer' && $this->relationLoaded('customer')) {
            return $this->customer ? [
                'id' => $this->customer->hash_id,
                'name' => $this->customer->name,
                'kind' => 'customer',
            ] : null;
        }

        if ($this->type?->value === 'supplier' && $this->relationLoaded('vendor')) {
            return $this->vendor ? [
                'id' => $this->vendor->hash_id,
                'name' => $this->vendor->name,
                'kind' => 'supplier',
            ] : null;
        }

        return null;
    }

    /** @return array{kind: string, id: string, label: string}|null */
    private function sourceSummary(): ?array
    {
        if ($this->relationLoaded('delivery') && $this->delivery) {
            return [
                'kind' => 'delivery',
                'id' => $this->delivery->hash_id,
                'label' => $this->delivery->delivery_number,
            ];
        }

        if ($this->relationLoaded('goodsReceiptNote') && $this->goodsReceiptNote) {
            return [
                'kind' => 'grn',
                'id' => $this->goodsReceiptNote->hash_id,
                'label' => $this->goodsReceiptNote->grn_number,
            ];
        }

        if ($this->relationLoaded('purchaseOrder') && $this->purchaseOrder) {
            return [
                'kind' => 'purchase_order',
                'id' => $this->purchaseOrder->hash_id,
                'label' => $this->purchaseOrder->po_number,
            ];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function lineSummary(Model $line): array
    {
        $product = $line->relationLoaded('product') ? $line->product : null;
        $item = $line->relationLoaded('item') ? $line->item : null;
        $returned = '0.000';
        $redelivered = $this->relationLoaded('receiptAllocations')
            ? $this->receiptAllocations->where('return_case_line_id', $line->id)->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 3), '0.000')
            : '0.000';
        $redeliveryRemaining = bcsub(bcadd((string) ($line->verified_missing_quantity ?? '0'), (string) ($line->verified_defective_quantity ?? '0'), 3), $redelivered, 3);
        if ($this->relationLoaded('returnRequest') && $this->returnRequest?->relationLoaded('items')) {
            foreach ($this->returnRequest->items as $returnLine) {
                if (($line->source_delivery_item_id && (int) $returnLine->source_delivery_item_id === (int) $line->source_delivery_item_id)
                    || ($line->source_grn_item_id && (int) $returnLine->source_grn_item_id === (int) $line->source_grn_item_id)) {
                    $returned = bcadd($returned, (string) ($returnLine->returned_quantity ?? '0'), 3);
                }
            }
        }


        return [
            'id' => $line->hash_id,
            'source_delivery_item_id' => $this->hashForeignKey($line->source_delivery_item_id),
            'source_po_item_id' => $this->hashForeignKey($line->source_po_item_id),
            'source_grn_item_id' => $this->hashForeignKey($line->source_grn_item_id),
            'product_id' => $this->hashForeignKey($line->product_id),
            'item_id' => $this->hashForeignKey($line->item_id),
            'product_label' => $product ? trim($product->part_number.' '.$product->name) : null,
            'item_label' => $item ? trim($item->code.' '.$item->name) : null,
            'description' => $line->description,
            'unit' => $line->unit,
            'expected_quantity' => (string) $line->expected_quantity,
            'received_quantity' => (string) $line->received_quantity,
            'missing_quantity' => (string) $line->missing_quantity,
            'defective_quantity' => (string) $line->defective_quantity,
            'returned_quantity' => $returned,
            'redelivered_quantity' => $redelivered,
            'remaining_redelivery_quantity' => bccomp($redeliveryRemaining, '0', 3) > 0 ? $redeliveryRemaining : '0.000',
            'verified_missing_quantity' => $line->verified_missing_quantity === null
                ? null : (string) $line->verified_missing_quantity,
            'verified_defective_quantity' => $line->verified_defective_quantity === null
                ? null : (string) $line->verified_defective_quantity,
            'lot_number' => $line->lot_number,
            'serial_number' => $line->serial_number,
            'reason' => $line->reason,
        ];
    }

    private function attachmentIsPublic(Model $attachment): bool
    {
        if ($attachment->event_id === null) {
            return true;
        }

        return $attachment->relationLoaded('event')
            && $attachment->event !== null
            && (bool) $attachment->event->is_public;
    }

    private function replacementOrderSummary(): mixed
    {
        if ($this->relationLoaded('replacementSalesOrder') && $this->replacementSalesOrder) {
            return [
                'id' => $this->replacementSalesOrder->hash_id,
                'number' => $this->replacementSalesOrder->so_number,
                'status' => $this->enumValue($this->replacementSalesOrder->status),
                'type' => 'sales_order',
            ];
        }

        if ($this->relationLoaded('replacementPurchaseOrder') && $this->replacementPurchaseOrder) {
            return [
                'id' => $this->replacementPurchaseOrder->hash_id,
                'number' => $this->replacementPurchaseOrder->po_number,
                'status' => $this->enumValue($this->replacementPurchaseOrder->status),
                'type' => 'purchase_order',
            ];
        }

        return null;
    }

    private function hashForeignKey(int|string|null $id): ?string
    {
        return $id === null ? null : HashId::encode((int) $id);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }
}
