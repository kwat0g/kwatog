<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Accounting\Models\Collection;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\OfficialReceipt;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * OGAMI-008 — issues a BIR Official Receipt acknowledging cash received.
 *
 * The Sales Invoice records the sale; the Official Receipt records the actual
 * collection. BIR requires both as distinct documents with their own serials.
 */
class OfficialReceiptService
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    /**
     * Issue an OR for a recorded collection (payment against an invoice).
     */
    public function issueForCollection(Collection $collection, User $by): OfficialReceipt
    {
        return DB::transaction(function () use ($collection, $by) {
            $collection->loadMissing('invoice');
            $invoice = $collection->invoice;

            return OfficialReceipt::create([
                'or_number'     => $this->sequences->generate('official_receipt'),
                'invoice_id'    => $invoice?->id,
                'collection_id' => $collection->id,
                'customer_id'   => $invoice?->customer_id,
                'amount'        => (string) $collection->amount,
                'date'          => $collection->collection_date ?? now()->toDateString(),
                'created_by'    => $by->id,
            ]);
        });
    }

    /**
     * Issue an OR directly for an invoice (e.g. full cash sale) for a given amount.
     */
    public function issueForInvoice(Invoice $invoice, string $amount, User $by): OfficialReceipt
    {
        return DB::transaction(fn () => OfficialReceipt::create([
            'or_number'   => $this->sequences->generate('official_receipt'),
            'invoice_id'  => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount'      => $amount,
            'date'        => now()->toDateString(),
            'created_by'  => $by->id,
        ]));
    }
}
