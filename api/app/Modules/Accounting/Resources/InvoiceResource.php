<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Enums\InvoiceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'invoice_number' => str_starts_with((string) $this->invoice_number, 'DRAFT-')
                ? null
                : $this->invoice_number,
            'date'           => optional($this->date)->toDateString(),
            'due_date'       => optional($this->due_date)->toDateString(),
            'is_vatable'     => (bool) $this->is_vatable,
            'subtotal'       => (string) $this->subtotal,
            'vat_amount'     => (string) $this->vat_amount,
            'total_amount'   => (string) $this->total_amount,
            'amount_paid'    => (string) $this->amount_paid,
            'balance'        => (string) $this->balance,
            'status'         => $this->status?->value,
            // Display-friendly status: surface "unpaid" alias when finalized + zero collected
            'display_status' => $this->status === InvoiceStatus::Finalized && (string) $this->amount_paid === '0.00'
                ? 'unpaid'
                : $this->status?->value,
            'is_overdue'     => $this->isOverdue(),
            'aging_bucket'   => $this->agingBucket(),
            'remarks'        => $this->remarks,
            'customer'       => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->hash_id, 'name' => $this->customer->name,
            ] : null),
            'items'          => InvoiceItemResource::collection($this->whenLoaded('items')),
            'collections'    => CollectionResource::collection($this->whenLoaded('collections')),
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
