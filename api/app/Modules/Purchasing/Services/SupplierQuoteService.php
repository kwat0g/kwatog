<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Enums\RfqInvitationStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteResponseStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Enums\VatTreatment;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Models\SupplierQuote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One quotation per supplier per RFQ, edited in place while the RFQ is open.
 * Draft is the supplier's private workspace; submitted is what Ogami
 * evaluates; withdraw returns it to draft. The same save path serves the
 * supplier portal and the buyer's manual capture of a phone/email quote.
 */
class SupplierQuoteService
{
    public const SUPPLIER_DOCUMENT_TYPES = ['quotation_pdf', 'certificate_of_analysis', 'resin_datasheet', 'safety_document'];

    public function __construct(private readonly RfqCommercialCalculator $calculator) {}

    public function list(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = RequestForQuoteInvitation::query()
            ->where('vendor_id', $vendorId)
            // A draft RFQ has not been sent to anyone yet.
            ->whereHas('rfq', fn ($q) => $q->whereNotNull('issued_at'))
            ->with(['rfq']);

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && RfqStatus::tryFrom($status) !== null) {
            $query->whereHas('rfq', fn ($q) => $q->where('status', $status));
        }
        if (($filters['search'] ?? '') !== '') {
            $term = SearchOperator::contains((string) $filters['search']);
            $query->whereHas('rfq', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('rfq_number', SearchOperator::like(), $term)
                ->orWhere('title', SearchOperator::like(), $term)));
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort = in_array($filters['sort'] ?? null, ['closes_at', 'rfq_number'], true) ? $filters['sort'] : null;
        if ($sort !== null) {
            $query->join('request_for_quotes', 'request_for_quotes.id', '=', 'request_for_quote_invitations.request_for_quote_id')
                ->orderBy('request_for_quotes.'.$sort, $direction)
                ->select('request_for_quote_invitations.*');
        }
        $query->orderByDesc('request_for_quote_invitations.invited_at');

        return $query->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function invitation(int $vendorId, RequestForQuote $rfq): RequestForQuoteInvitation
    {
        $invitation = RequestForQuoteInvitation::query()
            ->where('request_for_quote_id', $rfq->id)
            ->where('vendor_id', $vendorId)
            ->whereHas('rfq', fn ($q) => $q->whereNotNull('issued_at'))
            ->firstOrFail();
        if ($invitation->viewed_at === null && $invitation->status === RfqInvitationStatus::Invited) {
            $invitation->forceFill(['viewed_at' => now(), 'status' => RfqInvitationStatus::Viewed])->save();
        }

        return $invitation->load([
            'rfq.items' => fn ($q) => $q->orderBy('id'),
            'rfq.items.item:id,code,name,unit_of_measure',
            'rfq.documents' => fn ($q) => $q->where(fn ($inner) => $inner
                ->where(fn ($shared) => $shared->whereNull('vendor_id')->where('document_type', 'requirement_document'))
                ->orWhere('vendor_id', $vendorId))->orderBy('id'),
            'rfq.awards' => fn ($q) => $q->where('vendor_id', $vendorId)->with('rfqItem'),
            'rfq.quotes' => fn ($q) => $q->where('vendor_id', $vendorId)->with(['items', 'documents']),
        ]);
    }

    /**
     * Save the supplier's quotation; with $submit it is validated and
     * submitted in the same transaction (also how a submitted quote is
     * updated). A buyer capturing a phone/email quote passes $capturedBy.
     */
    public function save(RequestForQuote $rfq, int $vendorId, array $data, bool $submit, ?SupplierPortalUser $portalUser = null, ?User $capturedBy = null): SupplierQuote
    {
        return DB::transaction(function () use ($rfq, $vendorId, $data, $submit, $portalUser, $capturedBy): SupplierQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->with('items')->findOrFail($rfq->id);
            $this->assertAcceptingQuotes($locked);
            $invitation = RequestForQuoteInvitation::query()
                ->where('request_for_quote_id', $locked->id)
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->first();
            if (! $invitation) {
                throw new BusinessRuleException('This supplier is not invited to the RFQ.');
            }
            $quote = SupplierQuote::query()
                ->where('request_for_quote_id', $locked->id)
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->first();
            // A buyer's manual entry is for suppliers who quote by phone or
            // email. It must never replace what a supplier entered itself.
            if ($capturedBy !== null && $quote?->portal_user_id !== null) {
                throw new BusinessRuleException('This supplier entered its own quotation in the portal. Ask the supplier to update it there.');
            }
            if (! $submit && $quote?->status === SupplierQuoteStatus::Submitted) {
                throw new BusinessRuleException('This quotation is already submitted. Submit your changes, or withdraw it first to keep working on a draft.');
            }

            $quote ??= new SupplierQuote([
                'request_for_quote_id' => $locked->id,
                'vendor_id' => $vendorId,
                'invitation_id' => $invitation->id,
            ]);
            $quote->fill([
                'portal_user_id' => $portalUser?->id ?? $quote->portal_user_id,
                'captured_by' => $capturedBy?->id ?? $quote->captured_by,
                'vat_treatment' => VatTreatment::from((string) ($data['vat_treatment'] ?? VatTreatment::Exclusive->value)),
                'freight_amount' => Money::round2((string) ($data['freight_amount'] ?? '0')),
                'quote_valid_until' => $data['quote_valid_until'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            if (! $quote->exists) {
                $quote->status = SupplierQuoteStatus::Draft;
            }
            $quote->save();
            $this->replaceItems($quote, $locked, (array) ($data['items'] ?? []));

            // Documents uploaded before the first save belong to this quote now.
            RfqDocument::query()
                ->where('request_for_quote_id', $locked->id)
                ->where('vendor_id', $vendorId)
                ->whereNull('supplier_quote_id')
                ->update(['supplier_quote_id' => $quote->id]);

            if ($submit) {
                $this->assertSubmittable($quote, $locked);
                $quote->forceFill(['status' => SupplierQuoteStatus::Submitted, 'submitted_at' => now()])->save();
                $invitation->forceFill(['status' => RfqInvitationStatus::Submitted])->save();
            }

            return $quote->fresh(['items', 'documents', 'vendor:id,name']);
        });
    }

    /** Take a submitted quotation out of evaluation; it becomes a draft again. */
    public function withdraw(RequestForQuote $rfq, int $vendorId): SupplierQuote
    {
        return DB::transaction(function () use ($rfq, $vendorId): SupplierQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertAcceptingQuotes($locked);
            $quote = SupplierQuote::query()
                ->where('request_for_quote_id', $locked->id)
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->first();
            if (! $quote || $quote->status !== SupplierQuoteStatus::Submitted) {
                throw new BusinessRuleException('There is no submitted quotation to withdraw.');
            }
            $quote->forceFill(['status' => SupplierQuoteStatus::Draft, 'submitted_at' => null])->save();
            RequestForQuoteInvitation::query()
                ->where('request_for_quote_id', $locked->id)
                ->where('vendor_id', $vendorId)
                ->update(['status' => RfqInvitationStatus::Viewed->value]);

            return $quote->fresh(['items', 'documents', 'vendor:id,name']);
        });
    }

    public function uploadDocument(RequestForQuote $rfq, int $vendorId, SupplierPortalUser $portalUser, UploadedFile $file, string $documentType): RfqDocument
    {
        return DB::transaction(function () use ($rfq, $vendorId, $portalUser, $file, $documentType): RfqDocument {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertAcceptingQuotes($locked);
            if (! RequestForQuoteInvitation::query()->where('request_for_quote_id', $locked->id)->where('vendor_id', $vendorId)->exists()) {
                throw new BusinessRuleException('This supplier is not invited to the RFQ.');
            }
            $path = $file->store('rfqs/'.$locked->hash_id.'/'.$vendorId, 'local');
            try {
                return RfqDocument::create([
                    'request_for_quote_id' => $locked->id,
                    'supplier_quote_id' => SupplierQuote::query()->where('request_for_quote_id', $locked->id)->where('vendor_id', $vendorId)->value('id'),
                    'vendor_id' => $vendorId,
                    'uploaded_by_portal_user' => $portalUser->id,
                    'document_type' => $documentType,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'file_path' => $path,
                ]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });
    }

    public function documentForSupplier(int $vendorId, RequestForQuote $rfq, RfqDocument $document): RfqDocument
    {
        $invited = RequestForQuoteInvitation::query()
            ->where('request_for_quote_id', $rfq->id)
            ->where('vendor_id', $vendorId)
            ->exists();
        $shared = $document->vendor_id === null && $document->document_type === 'requirement_document';
        if ((int) $document->request_for_quote_id !== (int) $rfq->id || ! $invited || (! $shared && (int) $document->vendor_id !== $vendorId)) {
            throw new BusinessRuleException('RFQ document is not available to this supplier.');
        }

        return $document;
    }

    private function assertAcceptingQuotes(RequestForQuote $rfq): void
    {
        if ($rfq->status !== RfqStatus::Open || ! $rfq->closes_at->isFuture()) {
            throw new BusinessRuleException('This RFQ is no longer accepting quotations.');
        }
    }

    private function assertSubmittable(SupplierQuote $quote, RequestForQuote $rfq): void
    {
        $quote->load('items');
        if (! $quote->items->contains(fn ($line): bool => $line->response_status === SupplierQuoteResponseStatus::Quoted)) {
            throw new BusinessRuleException('Quote at least one line before submitting.');
        }
        if ($quote->quote_valid_until !== null && $quote->quote_valid_until->lt(today())) {
            throw new BusinessRuleException('The quotation validity date has already passed.');
        }
        $pdf = RfqDocument::query()
            ->where('request_for_quote_id', $rfq->id)
            ->where('vendor_id', $quote->vendor_id)
            ->where('document_type', 'quotation_pdf')
            ->latest('id')
            ->first();
        if (! $pdf) {
            throw new BusinessRuleException('Attach the formal quotation PDF before submitting.');
        }
        $quote->forceFill(['quotation_path' => $pdf->file_path, 'quotation_original_filename' => $pdf->original_filename])->save();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function replaceItems(SupplierQuote $quote, RequestForQuote $rfq, array $rows): void
    {
        $quote->items()->delete();
        $seen = [];
        foreach ($rows as $row) {
            $rfqItem = $rfq->items->firstWhere('id', (int) ($row['request_for_quote_item_id'] ?? 0));
            if (! $rfqItem || isset($seen[$rfqItem->id])) {
                throw new BusinessRuleException('The quotation contains an invalid or repeated RFQ line.');
            }
            $seen[$rfqItem->id] = true;
            $quoted = ($row['response_status'] ?? 'quoted') !== SupplierQuoteResponseStatus::NoQuote->value;
            $quantity = $quoted ? (string) ($row['offered_quantity'] ?? '0') : null;
            $price = $quoted ? (string) ($row['unit_price'] ?? '0') : null;
            if ($quoted && (bccomp($quantity, '0', 4) <= 0 || bccomp($price, '0', 4) <= 0)) {
                throw new BusinessRuleException("\"{$rfqItem->description}\" needs a positive quantity and unit price, or mark it No quote.");
            }
            if ($quoted && bccomp($quantity, (string) $rfqItem->quantity, 4) > 0) {
                throw new BusinessRuleException("The offered quantity for \"{$rfqItem->description}\" is more than the {$rfqItem->quantity} requested.");
            }
            $quote->items()->create([
                'request_for_quote_item_id' => $rfqItem->id,
                'response_status' => $quoted ? SupplierQuoteResponseStatus::Quoted->value : SupplierQuoteResponseStatus::NoQuote->value,
                'offered_quantity' => $quantity,
                'unit_price' => $price,
                'line_total_delivered_cost' => $this->calculator->lineGoods($quantity, $price),
                'lead_time_days' => $quoted ? ($row['lead_time_days'] ?? null) : null,
                'proposed_delivery_date' => $quoted ? ($row['proposed_delivery_date'] ?? null) : null,
            ]);
        }

        $quote->load('items');
        $goods = $this->calculator->goods($quote->items);
        $totals = $this->calculator->totals($quote->vat_treatment, $goods, (string) $quote->freight_amount);
        $quote->forceFill(['vat_amount' => $totals['vat'], 'total_delivered_cost' => $totals['total']])->save();
    }
}
