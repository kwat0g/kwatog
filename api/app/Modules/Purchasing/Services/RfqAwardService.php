<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\RfqInvitationStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteResponseStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqAward;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use App\Modules\Purchasing\Policies\RequestForQuoteAccessPolicy;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One winner per RFQ line, for the quantity that supplier offered. Lines the
 * buyer leaves unawarded — and any quantity a winner could not cover — go back
 * to the PR for a Direct PO or a new RFQ. One draft PO per winning supplier,
 * priced by RfqCommercialCalculator so it equals what the comparison showed.
 */
class RfqAwardService
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly RequestForQuoteService $rfqs,
        private readonly RfqCommercialCalculator $calculator,
        private readonly RequestForQuoteAccessPolicy $access,
        private readonly OutboxService $outbox,
    ) {}

    /**
     * @param  array<int, array{request_for_quote_item_id:int, supplier_quote_item_id:int}>  $lines
     * @return array{rfq: RequestForQuote, purchase_orders: array<int, PurchaseOrder>}
     */
    public function award(RequestForQuote $rfq, string $reason, array $lines, User $by): array
    {
        return DB::transaction(function () use ($rfq, $reason, $lines, $by): array {
            $locked = RequestForQuote::query()->lockForUpdate()->with('items')->findOrFail($rfq->id);
            if (! $this->access->canAward($by)) {
                throw new BusinessRuleException('RFQ award needs purchasing authority. System administration does not grant it.');
            }
            if ($locked->status === RfqStatus::Awarded) {
                throw new BusinessRuleException('This RFQ has already been awarded.');
            }
            if ($locked->status !== RfqStatus::Closed) {
                throw new BusinessRuleException('Only a closed RFQ can be awarded.');
            }
            if (trim($reason) === '') {
                throw new BusinessRuleException('Record the reason for this award.');
            }
            if ($lines === []) {
                throw new BusinessRuleException('Choose a supplier for at least one line, or cancel the RFQ.');
            }

            /** @var array<int, SupplierQuoteItem> $winners keyed by RFQ line id */
            $winners = [];
            foreach ($lines as $row) {
                $rfqItem = $locked->items->firstWhere('id', (int) $row['request_for_quote_item_id']);
                if (! $rfqItem) {
                    throw new BusinessRuleException('An award line does not belong to this RFQ.');
                }
                if (isset($winners[$rfqItem->id])) {
                    throw new BusinessRuleException("Choose one supplier for \"{$rfqItem->description}\".");
                }
                $quoteItem = SupplierQuoteItem::query()->lockForUpdate()->with('quote')->findOrFail((int) $row['supplier_quote_item_id']);
                $quote = $quoteItem->quote;
                if ((int) $quote->request_for_quote_id !== (int) $locked->id
                    || (int) $quoteItem->request_for_quote_item_id !== (int) $rfqItem->id) {
                    throw new BusinessRuleException("The selected quotation does not match \"{$rfqItem->description}\".");
                }
                if ($quote->status !== SupplierQuoteStatus::Submitted) {
                    throw new BusinessRuleException('Only a submitted quotation can be awarded.');
                }
                if ($quoteItem->response_status !== SupplierQuoteResponseStatus::Quoted || bccomp((string) $quoteItem->offered_quantity, '0', 4) <= 0) {
                    throw new BusinessRuleException("The supplier did not quote \"{$rfqItem->description}\".");
                }
                if ($quote->isExpired()) {
                    throw new BusinessRuleException("The quotation for \"{$rfqItem->description}\" expired on {$quote->quote_valid_until->toDateString()} and cannot be awarded.");
                }
                $winners[$rfqItem->id] = $quoteItem;
            }

            $awards = new EloquentCollection;
            foreach ($winners as $rfqItemId => $quoteItem) {
                $allocated = $this->calculator->allocate($quoteItem->quote)[(int) $quoteItem->id];
                $awards->push($locked->awards()->create([
                    'request_for_quote_item_id' => $rfqItemId,
                    'supplier_quote_id' => $quoteItem->supplier_quote_id,
                    'supplier_quote_item_id' => $quoteItem->id,
                    'vendor_id' => $quoteItem->quote->vendor_id,
                    'awarded_quantity' => (string) $quoteItem->offered_quantity,
                    'awarded_unit_price' => (string) $quoteItem->unit_price,
                    'awarded_total_delivered_cost' => $allocated,
                    'award_reason' => $reason,
                    'awarded_by' => $by->id,
                    'awarded_at' => now(),
                ]));
            }

            $winningQuoteIds = $awards->pluck('supplier_quote_id')->unique()->values();
            $winningVendorIds = $awards->pluck('vendor_id')->unique()->values();
            SupplierQuote::query()->whereIn('id', $winningQuoteIds)->update(['status' => SupplierQuoteStatus::Awarded->value]);
            SupplierQuote::query()
                ->where('request_for_quote_id', $locked->id)
                ->where('status', SupplierQuoteStatus::Submitted->value)
                ->update(['status' => SupplierQuoteStatus::NotAwarded->value]);
            $locked->invitations()->whereIn('vendor_id', $winningVendorIds)->update(['status' => RfqInvitationStatus::Awarded->value]);
            $locked->invitations()->whereNotIn('vendor_id', $winningVendorIds)->update(['status' => RfqInvitationStatus::NotAwarded->value]);

            $created = [];
            foreach ($awards->load(['quote.items', 'quoteItem', 'rfqItem'])->groupBy('supplier_quote_id') as $quoteAwards) {
                $created[] = $this->purchaseOrderFor($locked, $quoteAwards, $by);
            }

            $locked->forceFill(['status' => RfqStatus::Awarded, 'resolved_at' => now()])->save();
            $this->rfqs->handBack($locked, "RFQ {$locked->rfq_number} awarded part of this request. Convert the rest by Direct PO or start a new RFQ.");
            $this->outbox->record(new RfqLifecycleEvent((int) $locked->id, (string) $locked->hash_id, 'awarded'), 'rfq:'.$locked->id.':awarded');

            return ['rfq' => $locked->fresh(), 'purchase_orders' => $created];
        });
    }

    /** @param Collection<int, RfqAward> $awards all for one quote */
    private function purchaseOrderFor(RequestForQuote $rfq, $awards, User $by): PurchaseOrder
    {
        $quote = $awards->first()->quote;
        $commercial = $this->calculator->forPurchaseOrder($quote, $awards->map(fn (RfqAward $award) => $award->quoteItem));
        $expected = $awards
            ->map(fn (RfqAward $award) => $award->rfqItem->required_delivery_date?->toDateString())
            ->filter()
            ->sort()
            ->first();

        return $this->purchaseOrders->create([
            'vendor_id' => $quote->vendor_id,
            'purchase_request_id' => $rfq->purchase_request_id,
            'request_for_quote_id' => $rfq->id,
            'date' => now()->toDateString(),
            'expected_delivery_date' => $expected,
            'remarks' => "Generated from {$rfq->rfq_number}.".($quote->payment_terms ? " Payment terms: {$quote->payment_terms}." : ''),
            'rfq_commercial' => $commercial['header'],
            'items' => $awards->map(fn (RfqAward $award): array => [
                'rfq_award_id' => $award->id,
                'supplier_quote_version_id' => $quote->id,
                'item_id' => $award->rfqItem->item_id,
                'purchase_request_item_id' => $award->rfqItem->purchase_request_item_id,
                'description' => $award->rfqItem->description,
                'quantity' => bcadd((string) $award->awarded_quantity, '0', 3),
                'unit' => $award->rfqItem->unit,
                'unit_price' => $commercial['unit_prices'][(int) $award->supplier_quote_item_id],
                'rfq_line_vat_amount' => '0.00',
                'rfq_line_freight_amount' => '0.00',
                'rfq_line_other_charges' => '0.00',
            ])->values()->all(),
        ], $by, true);
    }
}
