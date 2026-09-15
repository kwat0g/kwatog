<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Enums\RfqInvitationStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteResponseStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use App\Modules\Purchasing\Models\RfqDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SupplierQuoteService
{
    public function list(int $vendorId, array $filters): LengthAwarePaginator
    {
        return RequestForQuoteInvitation::query()
            ->where('vendor_id', $vendorId)
            ->with(['rfq.purchaseRequest:id,pr_number', 'rfq.items'])
            ->whereHas('rfq', fn ($q) => $q->whereIn('status', array_merge(RfqStatus::active(), [RfqStatus::Awarded->value, RfqStatus::PartiallyAwarded->value, RfqStatus::NoAward->value])))
            ->orderByDesc('invited_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function invitation(int $vendorId, RequestForQuote $rfq): RequestForQuoteInvitation
    {
        $invitation = RequestForQuoteInvitation::query()->where('request_for_quote_id', $rfq->id)->where('vendor_id', $vendorId)->firstOrFail();
        if ($invitation->viewed_at === null) $invitation->forceFill(['viewed_at' => now(), 'status' => RfqInvitationStatus::Viewed])->save();
        return $invitation->load(['rfq.items.item:id,code,name,unit_of_measure', 'rfq.addenda', 'quotes.items.rfqItem']);
    }

    public function createDraft(RequestForQuote $rfq, int $vendorId, ?SupplierPortalUser $portalUser, array $data): SupplierQuote
    {
        return DB::transaction(function () use ($rfq, $vendorId, $portalUser, $data): SupplierQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            if ($locked->status !== RfqStatus::Open || $locked->closes_at->isPast()) throw new BusinessRuleException('This RFQ is no longer accepting quotations.');
            $invitation = RequestForQuoteInvitation::query()->where('request_for_quote_id', $locked->id)->where('vendor_id', $vendorId)->lockForUpdate()->first();
            if (! $invitation) throw new BusinessRuleException('Your supplier is not invited to this RFQ.');
            $version = ((int) SupplierQuote::where('request_for_quote_id', $locked->id)->where('vendor_id', $vendorId)->max('version')) + 1;
            $quote = SupplierQuote::create([
                'request_for_quote_id' => $locked->id, 'vendor_id' => $vendorId,
                'invitation_id' => $invitation->id, 'portal_user_id' => $portalUser?->id,
                'version' => $version, 'is_current' => true, 'vat_inclusive' => (bool) ($data['vat_inclusive'] ?? false),
                'freight_amount' => $data['freight_amount'] ?? '0.00', 'other_charges' => $data['other_charges'] ?? '0.00',
                'quote_valid_until' => $data['quote_valid_until'] ?? null, 'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null, 'quotation_path' => $data['quotation_path'] ?? null,
                'quotation_original_filename' => $data['quotation_original_filename'] ?? null,
            ]);
            $quote->forceFill(['status' => SupplierQuoteStatus::Draft])->save();
            $this->replaceItems($quote, $locked, (array) ($data['items'] ?? []));
            return $quote->fresh(['items.rfqItem', 'vendor:id,name']);
        });
    }

    public function update(SupplierQuote $quote, int $vendorId, array $data): SupplierQuote
    {
        if ((int) $quote->vendor_id !== $vendorId) throw new BusinessRuleException('This quotation belongs to another supplier.');
        if ($quote->status === SupplierQuoteStatus::Submitted) {
            $data['quotation_path'] ??= $quote->quotation_path;
            $data['quotation_original_filename'] ??= $quote->quotation_original_filename;
            return $this->createDraft($quote->rfq, $vendorId, $quote->portalUser, $data);
        }
        return DB::transaction(function () use ($quote, $data): SupplierQuote {
            $locked = SupplierQuote::query()->lockForUpdate()->findOrFail($quote->id);
            if ($locked->status !== SupplierQuoteStatus::Draft) throw new BusinessRuleException('Only draft quotations can be edited.');
            $locked->update([
                'vat_inclusive' => array_key_exists('vat_inclusive', $data) ? (bool) $data['vat_inclusive'] : $locked->vat_inclusive,
                'freight_amount' => $data['freight_amount'] ?? $locked->freight_amount,
                'other_charges' => $data['other_charges'] ?? $locked->other_charges,
                'quote_valid_until' => $data['quote_valid_until'] ?? $locked->quote_valid_until,
                'payment_terms' => $data['payment_terms'] ?? $locked->payment_terms,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
                'quotation_path' => $data['quotation_path'] ?? $locked->quotation_path,
                'quotation_original_filename' => $data['quotation_original_filename'] ?? $locked->quotation_original_filename,
            ]);
            if (array_key_exists('items', $data)) $this->replaceItems($locked, $locked->rfq()->firstOrFail(), (array) $data['items']);
            return $locked->fresh(['items.rfqItem']);
        });
    }

    public function submit(SupplierQuote $quote, int $vendorId): SupplierQuote
    {
        return DB::transaction(function () use ($quote, $vendorId): SupplierQuote {
            $locked = SupplierQuote::query()->lockForUpdate()->with(['rfq', 'items.rfqItem'])->findOrFail($quote->id);
            if ((int) $locked->vendor_id !== $vendorId) throw new BusinessRuleException('This quotation belongs to another supplier.');
            if ($locked->status !== SupplierQuoteStatus::Draft) throw new BusinessRuleException('Only draft quotations can be submitted.');
            if ($locked->rfq->status !== RfqStatus::Open || $locked->rfq->closes_at->isPast()) throw new BusinessRuleException('The RFQ deadline has passed.');
            if (! $locked->quotation_path) throw new BusinessRuleException('A formal quotation PDF is required before submission.');
            if ($locked->items->isEmpty()) throw new BusinessRuleException('Respond to at least one RFQ line.');
            foreach ($locked->items as $item) {
                if ($item->response_status === SupplierQuoteResponseStatus::Quoted) {
                    if (Money::lte((string) $item->offered_quantity, '0') || Money::lte((string) $item->unit_price, '0')) throw new BusinessRuleException('Quoted lines need a positive quantity and unit price.');
                    if (Money::gt((string) $item->offered_quantity, (string) $item->rfqItem->quantity)) throw new BusinessRuleException('Offered quantity cannot exceed the requested quantity.');
                    if (! $item->rfqItem->allow_partial_quantity && bccomp((string) $item->offered_quantity, (string) $item->rfqItem->quantity, 4) !== 0) throw new BusinessRuleException('This RFQ line does not allow a partial quantity.');
                }
            }
            $locked->forceFill(['status' => SupplierQuoteStatus::Submitted, 'submitted_at' => now(), 'is_current' => true])->save();
            SupplierQuote::query()->where('request_for_quote_id', $locked->request_for_quote_id)->where('vendor_id', $vendorId)->where('id', '!=', $locked->id)->where('status', SupplierQuoteStatus::Submitted->value)->update(['is_current' => false, 'status' => SupplierQuoteStatus::Superseded->value]);
            $locked->invitation()->update(['status' => RfqInvitationStatus::Submitted->value]);
            return $locked->fresh(['items.rfqItem']);
        });
    }

    public function withdraw(SupplierQuote $quote, int $vendorId, string $reason): SupplierQuote
    {
        return DB::transaction(function () use ($quote, $vendorId, $reason): SupplierQuote {
            $locked = SupplierQuote::query()->lockForUpdate()->with('rfq')->findOrFail($quote->id);
            if ((int) $locked->vendor_id !== $vendorId) throw new BusinessRuleException('This quotation belongs to another supplier.');
            if ($locked->rfq->status !== RfqStatus::Open || $locked->rfq->closes_at->isPast()) throw new BusinessRuleException('A quotation can only be withdrawn while the RFQ is open.');
            if (! in_array($locked->status, [SupplierQuoteStatus::Draft, SupplierQuoteStatus::Submitted], true)) throw new BusinessRuleException('This quotation version is no longer active.');
            $locked->forceFill(['status' => SupplierQuoteStatus::Withdrawn, 'withdrawn_at' => now(), 'withdrawal_reason' => $reason, 'is_current' => false])->save();
            $locked->invitation()->update(['status' => RfqInvitationStatus::Withdrawn->value]);
            return $locked->fresh(['items.rfqItem']);
        });
    }

    public function uploadDocument(RequestForQuote $rfq, int $vendorId, ?SupplierPortalUser $portalUser, UploadedFile $file, string $documentType, ?SupplierQuote $quote = null): RfqDocument
    {
        if ($rfq->status !== RfqStatus::Open || $rfq->closes_at->isPast()) {
            throw new BusinessRuleException('Documents can only be uploaded while the RFQ is open.');
        }
        if (! RequestForQuoteInvitation::query()->where('request_for_quote_id', $rfq->id)->where('vendor_id', $vendorId)->exists()) {
            throw new BusinessRuleException('Your supplier is not invited to this RFQ.');
        }
        if ($quote && ((int) $quote->vendor_id !== $vendorId || (int) $quote->request_for_quote_id !== (int) $rfq->id)) {
            throw new BusinessRuleException('This quotation does not belong to your supplier.');
        }
        $path = $file->store('rfqs/'.$rfq->hash_id.'/'.$vendorId, 'local');
        $document = RfqDocument::create([
            'request_for_quote_id' => $rfq->id,
            'supplier_quote_id' => $quote?->id,
            'vendor_id' => $vendorId,
            'uploaded_by_portal_user' => $portalUser?->id,
            'document_type' => $documentType,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'file_path' => $path,
        ]);
        if ($documentType === 'quotation_pdf') {
            $draft = SupplierQuote::query()
                ->where('request_for_quote_id', $rfq->id)
                ->where('vendor_id', $vendorId)
                ->where('status', SupplierQuoteStatus::Draft->value)
                ->orderByDesc('version')
                ->first();
            $draft?->forceFill([
                'quotation_path' => $path,
                'quotation_original_filename' => $file->getClientOriginalName(),
            ])->save();
        }
        return $document;
    }

    private function replaceItems(SupplierQuote $quote, RequestForQuote $rfq, array $rows): void
    {
        $quote->items()->delete();
        $rfq->loadMissing('items');
        foreach ($rows as $row) {
            $rfqItemId = (int) ($row['request_for_quote_item_id'] ?? 0);
            $rfqItem = $rfq->items->firstWhere('id', $rfqItemId);
            if (! $rfqItem) throw new BusinessRuleException('The quotation contains an invalid RFQ line.');
            $status = ($row['response_status'] ?? 'quoted') === 'no_quote' ? SupplierQuoteResponseStatus::NoQuote : SupplierQuoteResponseStatus::Quoted;
            $quantity = $status === SupplierQuoteResponseStatus::Quoted ? (string) ($row['offered_quantity'] ?? '0') : null;
            $unitPrice = $status === SupplierQuoteResponseStatus::Quoted ? (string) ($row['unit_price'] ?? '0') : null;
            if ($status === SupplierQuoteResponseStatus::Quoted && (Money::lte($quantity, '0') || Money::lte($unitPrice, '0'))) {
                throw new BusinessRuleException('Quoted lines need a positive quantity and unit price.');
            }
            if ($status === SupplierQuoteResponseStatus::Quoted && Money::gt($quantity, (string) $rfqItem->quantity)) {
                throw new BusinessRuleException('Offered quantity cannot exceed the requested quantity.');
            }
            $line = Money::mul($quantity ?? '0', $unitPrice ?? '0');
            $line = Money::add($line, (string) ($row['line_vat_amount'] ?? '0'), (string) ($row['line_freight_amount'] ?? '0'), (string) ($row['line_other_charges'] ?? '0'));
            $quote->items()->create([
                'request_for_quote_item_id' => $rfqItem->id,
                'response_status' => $status->value,
                'offered_quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_vat_amount' => $row['line_vat_amount'] ?? '0',
                'line_freight_amount' => $row['line_freight_amount'] ?? '0',
                'line_other_charges' => $row['line_other_charges'] ?? '0',
                'line_total_delivered_cost' => $line,
                'lead_time_days' => $row['lead_time_days'] ?? null,
                'proposed_delivery_date' => $row['proposed_delivery_date'] ?? null,
                'minimum_order_quantity' => $row['minimum_order_quantity'] ?? null,
                'order_quantity_multiple' => $row['order_quantity_multiple'] ?? null,
                // Compliance is an internal QC decision. Never trust a supplier
                // supplied status to clear or create a blocking exception.
                'compliance_status' => 'pending',
                'compliance_notes' => $row['compliance_notes'] ?? null,
            ]);
        }
        $amounts = $quote->items()->pluck('line_total_delivered_cost')->map(static fn ($v): string => (string) $v)->all();
        $amounts[] = (string) $quote->freight_amount;
        $amounts[] = (string) $quote->other_charges;
        $total = Money::add(...$amounts);
        $quote->forceFill(['total_delivered_cost' => $total])->save();
    }
}
