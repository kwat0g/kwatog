<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'bill_number'    => $this->bill_number,
            'date'           => optional($this->date)->toDateString(),
            'due_date'       => optional($this->due_date)->toDateString(),
            'is_vatable'     => (bool) $this->is_vatable,
            'subtotal'       => (string) $this->subtotal,
            'vat_amount'     => (string) $this->vat_amount,
            'total_amount'   => (string) $this->total_amount,
            'amount_paid'    => (string) $this->amount_paid,
            'balance'        => (string) $this->balance,
            'status'         => $this->status?->value,
            'is_overdue'     => $this->isOverdue(),
            'aging_bucket'   => $this->agingBucket(),
            'remarks'        => $this->remarks,
            'has_variances'  => (bool) $this->has_variances,
            'three_way_overridden' => (bool) $this->three_way_overridden,
            'three_way_override_reason' => $this->three_way_override_reason,
            'three_way_match_url' => $this->purchase_order_id ? '/api/v1/purchasing/three-way-match/'.$this->hash_id : null,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'vendor'         => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->hash_id, 'name' => $this->vendor->name,
            ] : null),
            'items'          => BillItemResource::collection($this->whenLoaded('items')),
            'payments'       => BillPaymentResource::collection($this->whenLoaded('payments')),
            'journal_entry'  => $this->whenLoaded('journalEntry', fn () => $this->journalEntry ? [
                'id'           => $this->journalEntry->hash_id,
                'entry_number' => $this->journalEntry->entry_number,
                'status'       => $this->journalEntry->status?->value,
            ] : null),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
