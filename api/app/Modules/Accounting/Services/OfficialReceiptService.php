<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\Money;
use App\Modules\Accounting\Events\OfficialReceiptIssued;
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
        $created = false;
        $receipt = DB::transaction(function () use ($collection, $by, &$created) {
            $lockedCollection = Collection::query()
                ->lockForUpdate()
                ->with('invoice')
                ->findOrFail($collection->getKey());
            $existing = OfficialReceipt::query()
                ->where('collection_id', $lockedCollection->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $invoice = $lockedCollection->invoice;
            if (! $invoice) {
                throw new BusinessRuleException('A collection must belong to an invoice before an official receipt can be issued.');
            }

            $created = true;

            return OfficialReceipt::create([
                'or_number'     => $this->sequences->generate('official_receipt'),
                'invoice_id'    => $invoice?->id,
                'collection_id' => $lockedCollection->id,
                'customer_id'   => $invoice?->customer_id,
                'amount'        => (string) $lockedCollection->amount,
                'date'          => $lockedCollection->collection_date ?? now()->toDateString(),
                'created_by'    => $by->id,
            ]);
        });

        if ($created) {
            event(new OfficialReceiptIssued($receipt));
        }

        return $receipt;
    }

    /**
     * Issue an OR directly for an invoice (e.g. full cash sale) for a given amount.
     */
    public function issueForInvoice(Invoice $invoice, string $amount, User $by): OfficialReceipt
    {
        $amount = Money::round2($amount);
        if (Money::lte($amount, '0') || Money::gt($amount, (string) $invoice->total_amount)) {
            throw new BusinessRuleException('Official receipt amount must be greater than zero and no more than the invoice total.');
        }

        $receipt = DB::transaction(fn () => OfficialReceipt::create([
            'or_number'   => $this->sequences->generate('official_receipt'),
            'invoice_id'  => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount'      => $amount,
            'date'        => now()->toDateString(),
            'created_by'  => $by->id,
        ]));

        event(new OfficialReceiptIssued($receipt));

        return $receipt;
    }
}
