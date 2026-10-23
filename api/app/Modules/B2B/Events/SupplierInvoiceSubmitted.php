<?php

declare(strict_types=1);

namespace App\Modules\B2B\Events;

use App\Modules\Accounting\Models\Bill;
use App\Common\Services\CurrencyDisplayService;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierInvoiceSubmitted
{
    use Dispatchable, SerializesModels;

    /** @var array<string, mixed> */
    public readonly array $snapshot;

    public function __construct(public readonly Bill $bill)
    {
        $bill->loadMissing(['vendor', 'purchaseOrder', 'supplierInvoice']);
        $invoice = $bill->supplierInvoice;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $this->snapshot = [
            'recipient_email' => $bill->vendor?->email,
            'invoice_number' => (string) ($invoice?->number ?? $bill->supplier_invoice_number ?? $bill->bill_number),
            'invoice_date' => ($invoice?->date ?? $bill->supplier_invoice_date ?? $bill->date)?->format('F j, Y'),
            'match_count' => (string) ($invoice?->bills()->count() ?? 1),
            'gross_total' => (string) ($invoice?->gross_total ?? $bill->total_amount),
            'display_total' => app(CurrencyDisplayService::class)->format($invoice?->gross_total ?? $bill->total_amount),
            'purchase_order_number' => $bill->purchaseOrder?->po_number,
            'supplier_name' => (string) ($bill->vendor?->contact_person ?: $bill->vendor?->name ?: 'Supplier'),
            'bill_hash_id' => (string) $bill->hash_id,
            'portal_url' => $base.'/portal/supplier/invoices/'.($invoice?->hash_id ?? $bill->hash_id),
        ];
    }
}
