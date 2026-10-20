<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

use App\Modules\Accounting\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceFinalized
{
    use Dispatchable, SerializesModels;

    public readonly array $snapshot;

    public function __construct(public readonly Invoice $invoice)
    {
        $invoice->loadMissing(['customer', 'items', 'salesOrder']);

        $this->snapshot = [
            'invoice_number' => (string) $invoice->invoice_number,
            'customer_email' => $invoice->customer?->email,
            'customer_contact' => $invoice->customer?->contact_person,
            'customer_name' => $invoice->customer?->name,
            'invoice_date' => $invoice->date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total_amount' => (string) $invoice->total_amount,
            'balance' => (string) $invoice->balance,
            'sales_order_number' => $invoice->salesOrder?->so_number,
            'items' => $invoice->items->map(static fn ($item): array => [
                'description' => (string) $item->description,
                'quantity' => (string) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (string) $item->unit_price,
                'total' => (string) $item->total,
            ])->all(),
            'hash_id' => $invoice->hash_id,
        ];
    }
}
