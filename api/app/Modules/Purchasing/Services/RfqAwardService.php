<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Common\Support\Money;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\RfqAwardStatus;
use App\Modules\Purchasing\Enums\RfqComplianceStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteResponseStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqAward;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RfqAwardService
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly BudgetEnforcementService $budget,
        private readonly OutboxService $outbox,
    ) {}

    /**
     * @param  array<int, array{request_for_quote_item_id:int,supplier_quote_item_id:int,awarded_quantity:string,award_reason:string,single_response_justification?:string|null}>  $rows
     * @return array{rfq: RequestForQuote, purchase_orders: array<int, PurchaseOrder>}
     */
    public function award(RequestForQuote $rfq, array $rows, User $by): array
    {
        return DB::transaction(function () use ($rfq, $rows, $by): array {
            $locked = RequestForQuote::query()->lockForUpdate()->with(['items', 'purchaseRequest'])->findOrFail($rfq->id);
            if (! $by->hasPermission('purchasing.rfq.award')) {
                throw new BusinessRuleException('RFQ award permission is required.');
            }
            if ($by->role?->slug === 'system_admin') {
                throw new BusinessRuleException('System administration does not grant RFQ business award authority.');
            }
            if (! in_array($locked->status, [RfqStatus::Closed, RfqStatus::UnderEvaluation, RfqStatus::Awarded, RfqStatus::PartiallyAwarded], true)) {
                throw new BusinessRuleException('Only closed RFQs can be awarded.');
            }
            if ($locked->awards()->where('status', RfqAwardStatus::Awarded->value)->exists()) {
                return ['rfq' => $locked->fresh(), 'purchase_orders' => $locked->purchaseOrders()->get()->all()];
            }

            $byLine = [];
            foreach ($rows as $row) {
                $quoteItem = SupplierQuoteItem::query()->lockForUpdate()->with(['quote', 'rfqItem'])->findOrFail((int) $row['supplier_quote_item_id']);
                $quote = $quoteItem->quote;
                if ((int) $quote->request_for_quote_id !== $locked->id || $quote->status !== SupplierQuoteStatus::Submitted || ! $quote->is_current) {
                    throw new BusinessRuleException('Only the current submitted quotation for this RFQ can be awarded.');
                }
                if ($quoteItem->response_status !== SupplierQuoteResponseStatus::Quoted) {
                    throw new BusinessRuleException('A no-quote line cannot be awarded.');
                }
                if ($quoteItem->compliance_status === RfqComplianceStatus::Blocking) {
                    throw new BusinessRuleException('A blocking quality exception prevents this line from being awarded.');
                }
                $rfqItem = $locked->items->firstWhere('id', (int) $row['request_for_quote_item_id']);
                if (! $rfqItem || (int) $quoteItem->request_for_quote_item_id !== (int) $rfqItem->id) {
                    throw new BusinessRuleException('The selected quotation line does not belong to this RFQ line.');
                }
                $qty = (string) $row['awarded_quantity'];
                if (Money::lte($qty, '0') || Money::gt($qty, (string) $quoteItem->offered_quantity)) {
                    throw new BusinessRuleException('Awarded quantity must be positive and no more than the supplier offer.');
                }
                if (Money::gt($qty, (string) $rfqItem->quantity)) {
                    throw new BusinessRuleException('Awarded quantity cannot exceed the RFQ quantity.');
                }
                $byLine[$rfqItem->id][] = [$row, $quoteItem, $quote, $qty];
            }

            foreach ($locked->items as $line) {
                $selected = $byLine[$line->id] ?? [];
                if (count($selected) === 1) {
                    $responses = SupplierQuoteItem::query()->where('request_for_quote_item_id', $line->id)->whereHas('quote', fn ($q) => $q->where('request_for_quote_id', $locked->id)->where('status', SupplierQuoteStatus::Submitted->value)->where('is_current', true))->count();
                    if ($responses === 1 && trim((string) ($selected[0][0]['single_response_justification'] ?? '')) === '') {
                        throw new BusinessRuleException('A single-response award requires a justification.');
                    }
                }
                $total = Money::zero();
                foreach ($selected as [$row, $quoteItem, $quote, $qty]) {
                    $total = Money::add($total, $qty);
                    $lineVat = $this->prorateCharge((string) $quoteItem->line_vat_amount, $qty, (string) $quoteItem->offered_quantity);
                    $lineFreight = $this->prorateCharge((string) $quoteItem->line_freight_amount, $qty, (string) $quoteItem->offered_quantity);
                    $lineOther = $this->prorateCharge((string) $quoteItem->line_other_charges, $qty, (string) $quoteItem->offered_quantity);
                    $locked->awards()->create([
                        'request_for_quote_item_id' => $line->id,
                        'supplier_quote_id' => $quote->id,
                        'supplier_quote_item_id' => $quoteItem->id,
                        'vendor_id' => $quote->vendor_id,
                        'awarded_quantity' => $qty,
                        'awarded_unit_price' => (string) $quoteItem->unit_price,
                        'awarded_total_delivered_cost' => Money::add(
                            Money::mul($qty, (string) $quoteItem->unit_price),
                            $lineVat,
                            $lineFreight,
                            $lineOther,
                        ),
                        'award_reason' => $row['award_reason'],
                        'single_response_justification' => $row['single_response_justification'] ?? null,
                        'awarded_by' => $by->id,
                        'awarded_at' => now(),
                    ]);
                }
                if (Money::gt($total, (string) $line->quantity)) {
                    throw new BusinessRuleException('Total awards for an RFQ line cannot exceed its requested quantity.');
                }
            }

            $awards = $locked->awards()->with(['quote', 'quoteItem', 'rfqItem'])->get();
            if ($awards->isEmpty()) {
                $locked->forceFill(['status' => RfqStatus::NoAward, 'no_award_reason' => 'No compliant lines were selected.', 'resolved_at' => now()])->save();
                $this->purchaseOrders->syncConversionStatus(PurchaseRequest::query()->lockForUpdate()->findOrFail($locked->purchase_request_id));
                $this->outbox->record(new RfqLifecycleEvent((int) $locked->id, (string) $locked->hash_id, 'no_award'), 'rfq:'.$locked->id.':no-award:'.$locked->resolved_at?->timestamp);

                return ['rfq' => $locked->fresh(), 'purchase_orders' => []];
            }

            $winningQuoteIds = $awards->pluck('supplier_quote_id')->unique()->values();
            SupplierQuote::query()->whereIn('id', $winningQuoteIds)->update(['status' => SupplierQuoteStatus::Awarded->value]);
            $winningVendorIds = $awards->pluck('vendor_id')->unique()->values();
            $locked->invitations()->whereIn('vendor_id', $winningVendorIds)->update(['status' => 'awarded']);
            $locked->invitations()->whereNotIn('vendor_id', $winningVendorIds)->update(['status' => 'not_awarded']);
            SupplierQuote::query()->where('request_for_quote_id', $locked->id)->where('is_current', true)->whereNotIn('id', $winningQuoteIds)->where('status', SupplierQuoteStatus::Submitted->value)->update(['status' => SupplierQuoteStatus::NotAwarded->value]);

            $awardTotal = Money::add(...$awards->map(static fn (RfqAward $award): string => (string) $award->awarded_total_delivered_cost)->all());
            $sourcePr = PurchaseRequest::query()->lockForUpdate()->with('items')->findOrFail($locked->purchase_request_id);
            foreach ($awards->groupBy('supplier_quote_id') as $quoteAwards) {
                $quote = $quoteAwards->first()->quote;
                $awardTotal = Money::add($awardTotal, (string) $quote->vat_amount, (string) $quote->freight_amount, (string) $quote->other_charges);
            }
            if (Money::gt($awardTotal, $sourcePr->totalEstimatedAmount())) {
                $locked->forceFill([
                    'budget_warning_level' => 'warning',
                    'budget_warning_message' => "Awarded delivered cost {$awardTotal} exceeds the source PR estimate {$sourcePr->totalEstimatedAmount()}.",
                    'budget_acknowledged_by' => null,
                    'budget_acknowledged_at' => null,
                ])->save();
            } elseif ($sourcePr->department_id !== null) {
                [, $level, $message] = $this->budget->checkAvailability((int) $sourcePr->department_id, $awardTotal);
                if ($level !== 'ok') {
                    $locked->forceFill(['budget_warning_level' => $level, 'budget_warning_message' => $message])->save();
                }
            }

            $created = [];
            foreach ($awards->groupBy('vendor_id') as $vendorAwards) {
                $first = $vendorAwards->first();
                $commercial = $this->commercialFor($vendorAwards);
                $expectedDeliveryDate = $vendorAwards
                    ->map(fn (RfqAward $award) => $award->rfqItem->required_delivery_date?->toDateString())
                    ->filter()
                    ->sort()
                    ->first();
                $po = $this->purchaseOrders->create([
                    'vendor_id' => $first->vendor_id,
                    'purchase_request_id' => $locked->purchase_request_id,
                    'request_for_quote_id' => $locked->id,
                    'date' => now()->toDateString(),
                    'expected_delivery_date' => $expectedDeliveryDate,
                    'remarks' => "Generated from {$locked->rfq_number}; awarded supplier quotation v{$first->quote->version}.",
                    'rfq_commercial' => $commercial['header'],
                    'items' => $vendorAwards->map(fn (RfqAward $award): array => [
                        'rfq_award_id' => $award->id,
                        'supplier_quote_version_id' => $award->supplier_quote_id,
                        'item_id' => $award->rfqItem->item_id,
                        'purchase_request_item_id' => $award->rfqItem->purchase_request_item_id,
                        'description' => $award->rfqItem->description,
                        'quantity' => (string) $award->awarded_quantity,
                        'unit' => $award->rfqItem->unit,
                        'unit_price' => (string) $award->awarded_unit_price,
                        'rfq_line_vat_amount' => $commercial['lines'][$award->id]['vat_amount'],
                        'rfq_line_freight_amount' => $commercial['lines'][$award->id]['freight_amount'],
                        'rfq_line_other_charges' => $commercial['lines'][$award->id]['other_charges'],
                    ])->all(),
                ], $by, true);
                $po->forceFill(['request_for_quote_id' => $locked->id])->save();
                $created[] = $po;
            }

            $full = $locked->items->every(fn ($line) => $locked->awards()->where('request_for_quote_item_id', $line->id)->exists());
            $locked->forceFill([
                'status' => $full ? RfqStatus::Awarded : RfqStatus::PartiallyAwarded,
                'evaluation_started_at' => $locked->evaluation_started_at ?? now(),
                'resolved_at' => now(),
            ])->save();
            $this->purchaseOrders->syncConversionStatus($sourcePr);
            $this->outbox->record(new RfqLifecycleEvent((int) $locked->id, (string) $locked->hash_id, 'awarded'), 'rfq:'.$locked->id.':awarded:'.$locked->resolved_at?->timestamp);

            return ['rfq' => $locked->fresh(), 'purchase_orders' => $created];
        });
    }

    /**
     * Allocate quote line charges by awarded quantity. Header charges remain
     * attached once to the generated PO for each winning quote version.
     *
     * @param  Collection<int, RfqAward>  $awards
     * @return array{header: array{vat_amount:string,freight_amount:string,other_charges:string}, lines: array<int, array{vat_amount:string,freight_amount:string,other_charges:string}>}
     */
    private function commercialFor($awards): array
    {
        $header = ['vat_amount' => Money::zero(), 'freight_amount' => Money::zero(), 'other_charges' => Money::zero()];
        $lines = [];
        foreach ($awards->groupBy('supplier_quote_id') as $quoteAwards) {
            $quote = $quoteAwards->first()->quote;
            $header['vat_amount'] = Money::add($header['vat_amount'], (string) $quote->vat_amount);
            $header['freight_amount'] = Money::add($header['freight_amount'], (string) $quote->freight_amount);
            $header['other_charges'] = Money::add($header['other_charges'], (string) $quote->other_charges);
            foreach ($quoteAwards as $award) {
                $quoteItem = $award->quoteItem;
                $lineVat = $this->prorateCharge((string) $quoteItem->line_vat_amount, (string) $award->awarded_quantity, (string) $quoteItem->offered_quantity);
                $lines[$award->id] = [
                    'vat_amount' => $lineVat,
                    'freight_amount' => $this->prorateCharge((string) $quoteItem->line_freight_amount, (string) $award->awarded_quantity, (string) $quoteItem->offered_quantity),
                    'other_charges' => $this->prorateCharge((string) $quoteItem->line_other_charges, (string) $award->awarded_quantity, (string) $quoteItem->offered_quantity),
                ];
                $header['vat_amount'] = Money::add($header['vat_amount'], $lineVat);
            }
        }

        return ['header' => $header, 'lines' => $lines];
    }

    private function prorateCharge(string $charge, string $awardedQuantity, string $offeredQuantity): string
    {
        if (Money::isZero($charge) || Money::isZero($offeredQuantity)) {
            return Money::zero();
        }

        return Money::mul($charge, Money::div($awardedQuantity, $offeredQuantity));
    }

    public function reviewQuality(RequestForQuote $rfq, SupplierQuoteItem $quoteItem, array $data, User $by): SupplierQuoteItem
    {
        return DB::transaction(function () use ($rfq, $quoteItem, $data, $by): SupplierQuoteItem {
            $lockedRfq = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $lockedItem = SupplierQuoteItem::query()->lockForUpdate()->with('quote')->findOrFail($quoteItem->id);
            if ((int) $lockedItem->quote->request_for_quote_id !== (int) $lockedRfq->id) {
                throw new BusinessRuleException('The quotation line does not belong to this RFQ.');
            }
            if (! $by->hasPermission('purchasing.rfq.quality_review')) {
                throw new BusinessRuleException('RFQ quality-review permission is required.');
            }
            if (! in_array($lockedRfq->status, [RfqStatus::Closed, RfqStatus::UnderEvaluation], true)) {
                throw new BusinessRuleException('Quality review is available after RFQ closure.');
            }
            $lockedItem->forceFill([
                'compliance_status' => $data['compliance_status'],
                'compliance_notes' => $data['compliance_notes'] ?? null,
            ])->save();

            return $lockedItem->fresh(['quote', 'rfqItem']);
        });
    }
}
