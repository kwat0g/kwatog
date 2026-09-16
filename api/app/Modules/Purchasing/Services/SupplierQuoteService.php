<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Enums\RfqInvitationStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteResponseStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Models\RfqQuoteReconfirmation;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SupplierQuoteService
{
    public function __construct(private readonly OutboxService $outbox, private readonly TaxPolicyService $taxPolicy) {}

    public function list(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = RequestForQuoteInvitation::query()
            ->where('vendor_id', $vendorId)
            ->with(['rfq.purchaseRequest:id,pr_number', 'rfq.items'])
            ->whereHas('rfq', fn ($q) => $q->whereIn('status', array_merge(RfqStatus::active(), [RfqStatus::Awarded->value, RfqStatus::PartiallyAwarded->value, RfqStatus::NoAward->value])));

        if (($filters['status'] ?? '') !== '') {
            // Statuses name the RFQ lifecycle; invitation_status names this
            // vendor's own progress. Filter on whichever column the value
            // belongs to instead of guessing.
            $status = (string) $filters['status'];
            if (RfqStatus::tryFrom($status) !== null) {
                $query->whereHas('rfq', fn ($q) => $q->where('status', $status));
            } elseif (RfqInvitationStatus::tryFrom($status) !== null) {
                $query->where('status', $status);
            }
        }

        if (($filters['search'] ?? '') !== '') {
            $search = (string) $filters['search'];
            $query->whereHas('rfq', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('rfq_number', SearchOperator::like(), SearchOperator::contains($search))
                ->orWhere('title', SearchOperator::like(), SearchOperator::contains($search))));
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sorts = [
            'closes_at' => fn () => $query->join('request_for_quotes', 'request_for_quotes.id', '=', 'request_for_quote_invitations.request_for_quote_id')
                ->orderBy('request_for_quotes.closes_at', $direction)
                ->orderByDesc('request_for_quote_invitations.invited_at')
                ->select('request_for_quote_invitations.*'),
            'rfq_number' => fn () => $query->join('request_for_quotes', 'request_for_quotes.id', '=', 'request_for_quote_invitations.request_for_quote_id')
                ->orderBy('request_for_quotes.rfq_number', $direction)
                ->select('request_for_quote_invitations.*'),
        ];
        ($sorts[(string) ($filters['sort'] ?? '')] ?? fn () => $query->orderByDesc('request_for_quote_invitations.invited_at'))();

        return $query->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function invitation(int $vendorId, RequestForQuote $rfq): RequestForQuoteInvitation
    {
        $invitation = RequestForQuoteInvitation::query()->where('request_for_quote_id', $rfq->id)->where('vendor_id', $vendorId)->firstOrFail();
        if ($invitation->viewed_at === null && $invitation->status === RfqInvitationStatus::Invited) {
            $invitation->forceFill(['viewed_at' => now(), 'status' => RfqInvitationStatus::Viewed])->save();
        }

        return $invitation->load([
            'rfq.items.item:id,code,name,unit_of_measure',
            'rfq.addenda',
            'rfq.documents' => fn ($query) => $query->whereNull('vendor_id')->where('document_type', 'requirement_document'),
            'rfq.awards' => fn ($query) => $query->where('vendor_id', $vendorId)->with(['rfqItem', 'quote']),
            'quotes' => fn ($query) => $query->where('vendor_id', $vendorId)->with(['items.rfqItem', 'documents']),
        ]);
    }

    public function createDraft(RequestForQuote $rfq, int $vendorId, ?SupplierPortalUser $portalUser, array $data): SupplierQuote
    {
        return DB::transaction(function () use ($rfq, $vendorId, $portalUser, $data): SupplierQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            if ($locked->status !== RfqStatus::Open || $locked->closes_at->isPast()) {
                throw new BusinessRuleException('This RFQ is no longer accepting quotations.');
            }
            $invitation = RequestForQuoteInvitation::query()->where('request_for_quote_id', $locked->id)->where('vendor_id', $vendorId)->lockForUpdate()->first();
            if (! $invitation) {
                throw new BusinessRuleException('Your supplier is not invited to this RFQ.');
            }
            $existingDraft = SupplierQuote::query()
                ->where('request_for_quote_id', $locked->id)
                ->where('vendor_id', $vendorId)
                ->where('status', SupplierQuoteStatus::Draft->value)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();
            if ($existingDraft) {
                $vatInclusive = (bool) ($data['vat_inclusive'] ?? $existingDraft->vat_inclusive);
                $existingDraft->update([
                    'vat_inclusive' => $vatInclusive,
                    'vat_amount' => $this->resolveHeaderVat((array) ($data['items'] ?? []), $vatInclusive, $data['vat_amount'] ?? null),
                    'freight_amount' => $data['freight_amount'] ?? $existingDraft->freight_amount,
                    'other_charges' => $data['other_charges'] ?? $existingDraft->other_charges,
                    'quote_valid_until' => $data['quote_valid_until'] ?? $existingDraft->quote_valid_until,
                    'payment_terms' => $data['payment_terms'] ?? $existingDraft->payment_terms,
                    'notes' => array_key_exists('notes', $data) ? $data['notes'] : $existingDraft->notes,
                ]);
                $this->replaceItems($existingDraft, $locked, (array) ($data['items'] ?? []));

                return $existingDraft->fresh(['items.rfqItem', 'vendor:id,name']);
            }
            $version = ((int) SupplierQuote::where('request_for_quote_id', $locked->id)->where('vendor_id', $vendorId)->max('version')) + 1;
            $vatInclusive = (bool) ($data['vat_inclusive'] ?? false);
            $quote = SupplierQuote::create([
                'request_for_quote_id' => $locked->id, 'vendor_id' => $vendorId,
                'invitation_id' => $invitation->id, 'portal_user_id' => $portalUser?->id,
                'version' => $version, 'is_current' => true, 'vat_inclusive' => $vatInclusive,
                'vat_amount' => $this->resolveHeaderVat((array) ($data['items'] ?? []), $vatInclusive, $data['vat_amount'] ?? null),
                'freight_amount' => $data['freight_amount'] ?? '0.00', 'other_charges' => $data['other_charges'] ?? '0.00',
                'quote_valid_until' => $data['quote_valid_until'] ?? null, 'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $quote->forceFill(['status' => SupplierQuoteStatus::Draft])->save();
            $this->replaceItems($quote, $locked, (array) ($data['items'] ?? []));

            return $quote->fresh(['items.rfqItem', 'vendor:id,name']);
        });
    }

    public function captureManual(RequestForQuote $rfq, array $data, User $by): SupplierQuote
    {
        return DB::transaction(function () use ($rfq, $data, $by): SupplierQuote {
            $lockedRfq = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            if ($lockedRfq->status !== RfqStatus::Open || $lockedRfq->closes_at->isPast()) {
                throw new BusinessRuleException('Manual quotations can only be captured while the RFQ is open.');
            }
            $invitation = RequestForQuoteInvitation::query()
                ->where('request_for_quote_id', $lockedRfq->id)
                ->where('vendor_id', (int) $data['vendor_id'])
                ->lockForUpdate()
                ->first();
            if (! $invitation) {
                throw new BusinessRuleException('The selected supplier is not invited to this RFQ.');
            }
            $document = RfqDocument::query()->lockForUpdate()->findOrFail((int) $data['quotation_document_id']);
            if ((int) $document->request_for_quote_id !== (int) $lockedRfq->id || (int) $document->vendor_id !== (int) $data['vendor_id'] || $document->document_type !== 'quotation_pdf') {
                throw new BusinessRuleException('The quotation document does not belong to this RFQ and supplier.');
            }
            $version = ((int) SupplierQuote::query()->where('request_for_quote_id', $lockedRfq->id)->where('vendor_id', (int) $data['vendor_id'])->max('version')) + 1;
            $vatInclusive = (bool) ($data['vat_inclusive'] ?? false);
            $quote = SupplierQuote::create([
                'request_for_quote_id' => $lockedRfq->id,
                'vendor_id' => (int) $data['vendor_id'],
                'invitation_id' => $invitation->id,
                'captured_by' => $by->id,
                'version' => $version,
                'is_current' => true,
                'vat_inclusive' => $vatInclusive,
                'vat_amount' => $this->resolveHeaderVat((array) $data['items'], $vatInclusive, $data['vat_amount'] ?? null),
                'freight_amount' => $data['freight_amount'] ?? '0.00',
                'other_charges' => $data['other_charges'] ?? '0.00',
                'quote_valid_until' => $data['quote_valid_until'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'quotation_path' => $document->file_path,
                'quotation_original_filename' => $document->original_filename,
            ]);
            $quote->forceFill(['status' => SupplierQuoteStatus::Draft])->save();
            $this->replaceItems($quote, $lockedRfq, (array) $data['items']);
            $quote->forceFill(['status' => SupplierQuoteStatus::Submitted, 'submitted_at' => now()])->save();
            SupplierQuote::query()->where('request_for_quote_id', $lockedRfq->id)->where('vendor_id', (int) $data['vendor_id'])->where('id', '!=', $quote->id)->where('status', SupplierQuoteStatus::Submitted->value)->update(['is_current' => false, 'status' => SupplierQuoteStatus::Superseded->value]);
            $invitation->forceFill(['status' => RfqInvitationStatus::Submitted])->save();
            $document->forceFill(['supplier_quote_id' => $quote->id])->save();
            $this->outbox->record(new RfqLifecycleEvent((int) $lockedRfq->id, (string) $lockedRfq->hash_id, 'quote_submitted'), 'rfq:'.$lockedRfq->id.':manual-quote-submitted:'.$quote->id);

            return $quote->fresh(['items.rfqItem', 'vendor:id,name']);
        });
    }

    public function update(SupplierQuote $quote, int $vendorId, array $data): SupplierQuote
    {
        if ((int) $quote->vendor_id !== $vendorId) {
            throw new BusinessRuleException('This quotation belongs to another supplier.');
        }
        if ($quote->status === SupplierQuoteStatus::Submitted) {
            return $this->createDraft($quote->rfq, $vendorId, $quote->portalUser, $data);
        }

        return DB::transaction(function () use ($quote, $data, $vendorId): SupplierQuote {
            $rfq = RequestForQuote::query()->lockForUpdate()->findOrFail($quote->request_for_quote_id);
            if ($rfq->status !== RfqStatus::Open || $rfq->closes_at->isPast()) {
                throw new BusinessRuleException('This RFQ is no longer accepting quotations.');
            }
            $locked = SupplierQuote::query()->lockForUpdate()->findOrFail($quote->id);
            if ((int) $locked->vendor_id !== $vendorId) {
                throw new BusinessRuleException('This quotation belongs to another supplier.');
            }
            if ($locked->status !== SupplierQuoteStatus::Draft) {
                throw new BusinessRuleException('Only draft quotations can be edited.');
            }
            $vatInclusive = array_key_exists('vat_inclusive', $data) ? (bool) $data['vat_inclusive'] : $locked->vat_inclusive;
            $locked->update([
                'vat_inclusive' => $vatInclusive,
                'vat_amount' => $this->resolveHeaderVat(
                    array_key_exists('items', $data) ? (array) $data['items'] : null,
                    $vatInclusive,
                    $data['vat_amount'] ?? null,
                    $locked,
                ),
                'freight_amount' => $data['freight_amount'] ?? $locked->freight_amount,
                'other_charges' => $data['other_charges'] ?? $locked->other_charges,
                'quote_valid_until' => $data['quote_valid_until'] ?? $locked->quote_valid_until,
                'payment_terms' => $data['payment_terms'] ?? $locked->payment_terms,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ]);
            if (array_key_exists('items', $data)) {
                $this->replaceItems($locked, $rfq, (array) $data['items']);
            } else {
                $this->recalculateTotal($locked);
            }

            return $locked->fresh(['items.rfqItem']);
        });
    }

    public function submit(SupplierQuote $quote, int $vendorId): SupplierQuote
    {
        return DB::transaction(function () use ($quote, $vendorId): SupplierQuote {
            $rfq = RequestForQuote::query()->lockForUpdate()->findOrFail($quote->request_for_quote_id);
            $locked = SupplierQuote::query()->lockForUpdate()->with(['items.rfqItem'])->findOrFail($quote->id);
            if ((int) $locked->vendor_id !== $vendorId) {
                throw new BusinessRuleException('This quotation belongs to another supplier.');
            }
            if ($locked->status !== SupplierQuoteStatus::Draft) {
                throw new BusinessRuleException('Only draft quotations can be submitted.');
            }
            if ($rfq->status !== RfqStatus::Open || $rfq->closes_at->isPast()) {
                throw new BusinessRuleException('The RFQ deadline has passed.');
            }
            if (! $locked->quotation_path) {
                throw new BusinessRuleException('A formal quotation PDF is required before submission.');
            }
            if ($locked->items->isEmpty()) {
                throw new BusinessRuleException('Respond to at least one RFQ line.');
            }
            foreach ($locked->items as $item) {
                if ($item->response_status === SupplierQuoteResponseStatus::Quoted) {
                    if (Money::lte((string) $item->offered_quantity, '0') || Money::lte((string) $item->unit_price, '0')) {
                        throw new BusinessRuleException('Quoted lines need a positive quantity and unit price.');
                    }
                    if (Money::gt((string) $item->offered_quantity, (string) $item->rfqItem->quantity)) {
                        throw new BusinessRuleException('Offered quantity cannot exceed the requested quantity.');
                    }
                    if (! $item->rfqItem->allow_partial_quantity && bccomp((string) $item->offered_quantity, (string) $item->rfqItem->quantity, 4) !== 0) {
                        throw new BusinessRuleException('This RFQ line does not allow a partial quantity.');
                    }
                }
            }
            $locked->forceFill(['status' => SupplierQuoteStatus::Submitted, 'submitted_at' => now(), 'is_current' => true])->save();
            SupplierQuote::query()->where('request_for_quote_id', $locked->request_for_quote_id)->where('vendor_id', $vendorId)->where('id', '!=', $locked->id)->where('status', SupplierQuoteStatus::Submitted->value)->update(['is_current' => false, 'status' => SupplierQuoteStatus::Superseded->value]);
            $locked->invitation()->update(['status' => RfqInvitationStatus::Submitted->value]);
            $this->outbox->record(
                new RfqLifecycleEvent((int) $rfq->id, (string) $rfq->hash_id, 'quote_submitted'),
                'rfq:'.$rfq->id.':quote-submitted:'.$locked->id.':'.($locked->submitted_at?->timestamp ?? now()->timestamp),
            );

            return $locked->fresh(['items.rfqItem']);
        });
    }

    public function withdraw(SupplierQuote $quote, int $vendorId, string $reason): SupplierQuote
    {
        return DB::transaction(function () use ($quote, $vendorId, $reason): SupplierQuote {
            $rfq = RequestForQuote::query()->lockForUpdate()->findOrFail($quote->request_for_quote_id);
            $locked = SupplierQuote::query()->lockForUpdate()->findOrFail($quote->id);
            if ((int) $locked->vendor_id !== $vendorId) {
                throw new BusinessRuleException('This quotation belongs to another supplier.');
            }
            if ($rfq->status !== RfqStatus::Open || $rfq->closes_at->isPast()) {
                throw new BusinessRuleException('A quotation can only be withdrawn while the RFQ is open.');
            }
            if (! in_array($locked->status, [SupplierQuoteStatus::Draft, SupplierQuoteStatus::Submitted], true)) {
                throw new BusinessRuleException('This quotation version is no longer active.');
            }
            $locked->forceFill(['status' => SupplierQuoteStatus::Withdrawn, 'withdrawn_at' => now(), 'withdrawal_reason' => $reason, 'is_current' => false])->save();
            $locked->invitation()->update(['status' => RfqInvitationStatus::Withdrawn->value]);
            $this->outbox->record(
                new RfqLifecycleEvent((int) $rfq->id, (string) $rfq->hash_id, 'quote_withdrawn'),
                'rfq:'.$rfq->id.':quote-withdrawn:'.$locked->id.':'.($locked->withdrawn_at?->timestamp ?? now()->timestamp),
            );

            return $locked->fresh(['items.rfqItem']);
        });
    }

    public function uploadDocument(RequestForQuote $rfq, int $vendorId, ?SupplierPortalUser $portalUser, UploadedFile $file, string $documentType, ?SupplierQuote $quote = null): RfqDocument
    {
        return DB::transaction(function () use ($rfq, $vendorId, $portalUser, $file, $documentType, $quote): RfqDocument {
            $lockedRfq = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            if ($lockedRfq->status !== RfqStatus::Open || $lockedRfq->closes_at->isPast()) {
                throw new BusinessRuleException('Documents can only be uploaded while the RFQ is open.');
            }
            $invitation = RequestForQuoteInvitation::query()
                ->where('request_for_quote_id', $lockedRfq->id)
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->first();
            if (! $invitation) {
                throw new BusinessRuleException('Your supplier is not invited to this RFQ.');
            }

            $lockedQuote = null;
            if ($quote !== null) {
                $lockedQuote = SupplierQuote::query()->lockForUpdate()->findOrFail($quote->id);
                if ((int) $lockedQuote->vendor_id !== $vendorId || (int) $lockedQuote->request_for_quote_id !== (int) $lockedRfq->id) {
                    throw new BusinessRuleException('This quotation does not belong to your supplier.');
                }
                if (! in_array($lockedQuote->status, [SupplierQuoteStatus::Draft, SupplierQuoteStatus::Submitted], true)) {
                    throw new BusinessRuleException('This quotation version is no longer active.');
                }
            }
            if ($documentType === 'quotation_pdf' && ($lockedQuote === null || $lockedQuote->status !== SupplierQuoteStatus::Draft)) {
                throw new BusinessRuleException('A quotation PDF must be attached to the current draft quotation.');
            }

            $path = $file->store('rfqs/'.$lockedRfq->hash_id.'/'.$vendorId, 'local');
            try {
                $document = RfqDocument::create([
                    'request_for_quote_id' => $lockedRfq->id,
                    'supplier_quote_id' => $lockedQuote?->id,
                    'vendor_id' => $vendorId,
                    'uploaded_by_portal_user' => $portalUser?->id,
                    'document_type' => $documentType,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'file_path' => $path,
                ]);
                if ($documentType === 'quotation_pdf') {
                    $lockedQuote->forceFill([
                        'quotation_path' => $path,
                        'quotation_original_filename' => $file->getClientOriginalName(),
                    ])->save();
                }
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }

            return $document;
        });
    }

    public function confirmReconfirmation(RfqQuoteReconfirmation $reconfirmation, int $vendorId, SupplierPortalUser $portalUser): RfqQuoteReconfirmation
    {
        return DB::transaction(function () use ($reconfirmation, $vendorId, $portalUser): RfqQuoteReconfirmation {
            $locked = RfqQuoteReconfirmation::query()->lockForUpdate()->with(['purchaseOrder', 'supplierQuote'])->findOrFail($reconfirmation->id);
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
            if ((int) $po->vendor_id !== $vendorId || $locked->status !== RfqQuoteReconfirmation::PENDING) {
                throw new BusinessRuleException('This quotation reconfirmation is not available to your supplier.');
            }
            $locked->forceFill([
                'status' => RfqQuoteReconfirmation::CONFIRMED,
                'supplier_portal_user_id' => $portalUser->id,
                'confirmed_at' => now(),
            ])->save();

            return $locked->fresh(['purchaseOrder', 'supplierQuote']);
        });
    }

    /**
     * VAT derivation for a supplier quotation.
     *
     * A quotation is the supplier's own commercial document, so Ogami does not
     * dictate its VAT treatment — but it does enforce arithmetic consistency
     * with whatever the supplier declares:
     *
     * - If any line carries its own VAT, the header is the sum of those lines
     *   (the supplier's breakdown wins; a contradicting header is refused).
     * - Otherwise the header VAT is DERIVED from the quoted lines' base
     *   (Σ quantity × unit price) and the declared treatment:
     *   VAT-inclusive → back-extracted base × rate / (1 + rate) (the 12/112
     *   rule); VAT-exclusive → base × rate; explicit zero → the supplier's
     *   no-VAT declaration (non-VAT-registered).
     * - An explicit non-zero header figure is accepted only within a few
     *   centavos of its treatment's derived value — enough slack for per-line
     *   rounding, not enough to misstate the tax.
     * - A non-zero declared VAT with no quoted lines is refused: it has no
     *   derivable base and could be anything.
     *
     * Exposed public for tests.
     *
     * @param  array<int, array<string, mixed>>|null  $rows  null = derive from the quote's persisted items
     * @param  SupplierQuote|null  $existing  set when updating a draft without new items
     */
    public function resolveHeaderVat(?array $rows, bool $vatInclusive, ?string $declaredVat, ?SupplierQuote $existing = null): string
    {
        $vatRate = $this->taxPolicy->requiredVatRate();
        $lines = $rows !== null ? $rows : ($existing?->items ?? collect())->map(fn (SupplierQuoteItem $item): array => [
            'response_status' => $item->response_status->value,
            'offered_quantity' => (string) $item->offered_quantity,
            'unit_price' => (string) $item->unit_price,
            'line_vat_amount' => (string) $item->line_vat_amount,
        ])->all();

        $lineVatSum = Money::zero();
        $taxableBase = Money::zero();
        foreach ($lines as $row) {
            if (($row['response_status'] ?? null) === SupplierQuoteResponseStatus::NoQuote->value) {
                continue;
            }
            $lineVatSum = Money::add($lineVatSum, (string) ($row['line_vat_amount'] ?? '0'));
            $taxableBase = Money::add($taxableBase, Money::mul((string) ($row['offered_quantity'] ?? '0'), (string) ($row['unit_price'] ?? '0')));
        }

        if (Money::gt($lineVatSum, Money::zero())) {
            // The supplier's line breakdown is the source of truth; the header
            // figure is only accepted when it agrees within one centavo per line.
            $declared = $declaredVat !== null && trim((string) $declaredVat) !== '' ? Money::round2($declaredVat) : $lineVatSum;
            $tolerance = Money::mul((string) count($lines), '0.01');
            $difference = Money::gte($declared, $lineVatSum)
                ? Money::sub($declared, $lineVatSum)
                : Money::sub($lineVatSum, $declared);
            if (Money::gt($difference, $tolerance)) {
                throw new BusinessRuleException(sprintf(
                    'VAT amount %s does not match the quotation lines (their VAT sums to %s).',
                    $declared,
                    $lineVatSum,
                ));
            }

            return (string) $lineVatSum;
        }

        if (Money::gt($taxableBase, Money::zero())) {
            $expected = $vatInclusive
                // Back-extract: gross VAT on the base, then remove it from the
                // gross — the 12/112 rule when the rate is 12%. Composed so the
                // intermediate stays exact to the centavo (Money scale rules).
                ? Money::round2(Money::div(Money::mul($taxableBase, $vatRate), Money::add('1', $vatRate)))
                : Money::mul($taxableBase, $vatRate);
            $declared = $declaredVat !== null && trim($declaredVat) !== '' ? Money::round2($declaredVat) : $expected;

            // An explicit zero is the supplier's no-VAT declaration (typically
            // non-VAT-registered). Honoured verbatim — the formal quotation PDF
            // remains the authority purchasing reviews against.
            if (Money::isZero($declared)) {
                return Money::zero();
            }

            // Centavos of slack: per-line rounding of the base can move the
            // derived figure by a centavo per line; anything beyond that is a
            // misstatement, not rounding.
            $tolerance = Money::add(Money::mul((string) max(1, count($lines)), '0.01'), '0.01');
            $difference = Money::gte($declared, $expected)
                ? Money::sub($declared, $expected)
                : Money::sub($expected, $declared);
            if (Money::gt($difference, $tolerance)) {
                throw new BusinessRuleException(sprintf(
                    'VAT amount %s does not match the quoted lines. Expected %s (%s).',
                    $declared,
                    $expected,
                    $vatInclusive ? 'VAT-inclusive' : 'VAT-exclusive at the prevailing rate',
                ));
            }

            return (string) $declared;
        }

        if ($declaredVat !== null && $declaredVat !== '' && ! Money::isZero(Money::round2($declaredVat))) {
            throw new BusinessRuleException('VAT amount cannot be entered without quoted lines or a VAT-inclusive declaration.');
        }

        return Money::zero();
    }

    private function replaceItems(SupplierQuote $quote, RequestForQuote $rfq, array $rows): void
    {
        $quote->items()->delete();
        $rfq->loadMissing('items');
        foreach ($rows as $row) {
            $rfqItemId = (int) ($row['request_for_quote_item_id'] ?? 0);
            $rfqItem = $rfq->items->firstWhere('id', $rfqItemId);
            if (! $rfqItem) {
                throw new BusinessRuleException('The quotation contains an invalid RFQ line.');
            }
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
        $amounts[] = (string) $quote->vat_amount;
        $amounts[] = (string) $quote->freight_amount;
        $amounts[] = (string) $quote->other_charges;
        $total = Money::add(...$amounts);
        $quote->forceFill(['total_delivered_cost' => $total])->save();
    }

    private function recalculateTotal(SupplierQuote $quote): void
    {
        $amounts = $quote->items()->pluck('line_total_delivered_cost')->map(static fn ($v): string => (string) $v)->all();
        $amounts[] = (string) $quote->vat_amount;
        $amounts[] = (string) $quote->freight_amount;
        $amounts[] = (string) $quote->other_charges;
        $quote->forceFill(['total_delivered_cost' => Money::add(...$amounts)])->save();
    }
}
