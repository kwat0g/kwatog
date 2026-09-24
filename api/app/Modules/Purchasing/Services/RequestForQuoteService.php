<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\RfqInvitationStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteResponseStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use App\Modules\Purchasing\Policies\RequestForQuoteAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RFQ lifecycle: draft → open → closed → awarded, or cancelled. Award lives
 * in RfqAwardService, supplier quotations in SupplierQuoteService, money in
 * RfqCommercialCalculator. See docs/SUPPLIER-RFQ-BIDDING-PLAN.md.
 */
class RequestForQuoteService
{
    public const INTERNAL_DOCUMENT_TYPES = ['requirement_document', 'quotation_pdf', 'certificate_of_analysis', 'resin_datasheet'];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly VendorSourcingService $sourcing,
        private readonly PurchaseRequestAccessPolicy $purchaseRequestAccess,
        private readonly RequestForQuoteAccessPolicy $access,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly OutboxService $outbox,
        private readonly SupplierPerformanceService $performance,
        private readonly RfqCommercialCalculator $calculator,
        private readonly TaxPolicyService $taxPolicy,
    ) {}

    public function list(array $filters, User $user): LengthAwarePaginator
    {
        $query = RequestForQuote::query()
            ->with(['purchaseRequest:id,pr_number', 'creator:id,name'])
            ->withCount([
                'invitations',
                'invitations as responded_count' => fn ($q) => $q->whereIn('status', self::respondedStatuses()),
            ]);
        $this->access->visibleTo($query, $user);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = SearchOperator::contains((string) $filters['search']);
            $query->where(fn ($q) => $q
                ->where('rfq_number', SearchOperator::like(), $term)
                ->orWhere('title', SearchOperator::like(), $term));
        }

        return $query->orderByDesc('created_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(RequestForQuote $rfq, ?User $user = null): RequestForQuote
    {
        if ($user && ! $this->access->canSee($user, $rfq)) {
            throw new BusinessRuleException('You do not have access to this RFQ.');
        }

        $rfq->load([
            'purchaseRequest:id,pr_number,department_id,required_delivery_date',
            'creator:id,name',
            'items' => fn ($q) => $q->orderBy('id'),
            'items.item:id,code,name,unit_of_measure',
            'items.awards',
            'invitations' => fn ($q) => $q->orderBy('id'),
            'invitations.vendor:id,name,email',
            // Drafts are the supplier's private workspace; only submitted or
            // resolved quotes are ever shown internally.
            'quotes' => fn ($q) => $q->evaluable()->orderBy('id')->with(['vendor:id,name', 'items', 'documents']),
            'awards' => fn ($q) => $q->orderBy('id'),
            'awards.vendor:id,name',
            'awards.rfqItem',
            'awards.purchaseOrderItem.purchaseOrder:id,po_number',
            'documents' => fn ($q) => $q->orderBy('id')->with('vendor:id,name'),
        ])->loadCount([
            'invitations',
            'invitations as responded_count' => fn ($q) => $q->whereIn('status', self::respondedStatuses()),
        ]);

        // How each invited supplier can be reached, so the buyer sees who
        // needs a manually entered quote.
        $portalVendorIds = SupplierPortalUser::query()
            ->where('is_active', true)
            ->whereIn('vendor_id', $rfq->invitations->pluck('vendor_id'))
            ->pluck('vendor_id')
            ->flip();
        foreach ($rfq->invitations as $invitation) {
            $invitation->setAttribute('reach', $invitation->vendor
                ? $this->reach($invitation->vendor, $portalVendorIds->has($invitation->vendor_id))
                : 'none');
        }

        return $rfq;
    }

    /**
     * What the RFQ form needs from a PR: the lines with the quantity still
     * to source, and the suppliers to choose from.
     *
     * @return array{lines: list<array<string, mixed>>, suppliers: list<array<string, mixed>>}
     */
    public function setup(PurchaseRequest $pr): array
    {
        $pr->loadMissing('items.item');
        $remaining = $this->purchaseOrders->remainingQuantitiesByPrLine($pr);

        return [
            'lines' => $pr->items->map(fn ($line): array => [
                'id' => (string) $line->hash_id,
                'description' => (string) $line->description,
                'item_code' => $line->item?->code,
                'unit' => $line->unit,
                'remaining_quantity' => $remaining[(int) $line->id] ?? '0.000',
                'has_item' => $line->item_id !== null,
            ])->values()->all(),
            'suppliers' => array_map(static fn (array $row): array => [
                ...$row,
                'id' => app('hashids')->encode($row['id']),
            ], $this->supplierOptions($pr)),
        ];
    }

    /**
     * Every active vendor, qualified ones first, with how Ogami can reach
     * them — so the buyer sees before publishing who needs a manual quote.
     *
     * @return list<array{id:int, name:string, qualified:bool, lead_time_days:?int, reach:string, email:?string}>
     */
    public function supplierOptions(PurchaseRequest $pr): array
    {
        $pr->loadMissing('items');
        $candidatesByLine = $pr->items->map(fn ($line) => $line->item_id === null
            ? collect()
            : collect($this->sourcing->candidatesForItem((int) $line->item_id))->keyBy('vendor_id'));
        $portalVendorIds = SupplierPortalUser::query()->where('is_active', true)->distinct()->pluck('vendor_id')->flip();

        return Vendor::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(function (Vendor $vendor) use ($candidatesByLine, $portalVendorIds): array {
                $qualified = $candidatesByLine->isNotEmpty() && $candidatesByLine->every(
                    fn ($candidates): bool => (bool) ($candidates->get($vendor->id)['qualified'] ?? false),
                );
                $leadTimes = $candidatesByLine->map(fn ($candidates) => $candidates->get($vendor->id)['lead_time_days'] ?? null)->filter();

                return [
                    'id' => (int) $vendor->id,
                    'name' => (string) $vendor->name,
                    'qualified' => $qualified,
                    'lead_time_days' => $leadTimes->isEmpty() ? null : (int) $leadTimes->max(),
                    'reach' => $this->reach($vendor, $portalVendorIds->has($vendor->id)),
                    'email' => $vendor->email,
                ];
            })
            ->sortByDesc('qualified')
            ->values()
            ->all();
    }

    public function createFromPurchaseRequest(PurchaseRequest $pr, array $data, User $by): RequestForQuote
    {
        $rfq = DB::transaction(function () use ($pr, $data, $by): RequestForQuote {
            $locked = PurchaseRequest::query()->lockForUpdate()->with(['items.item'])->findOrFail($pr->id);
            $active = $locked->rfqs()->whereIn('status', RfqStatus::active())->latest('id')->first();
            if ($active) {
                // A double-click or retry returns the RFQ already running.
                return $active;
            }
            if (! $this->purchaseRequestAccess->canStartRfq($by, $locked)) {
                throw new BusinessRuleException('This purchase request cannot start an RFQ. It must be approved, still have unordered quantity, and be marked for RFQ or need manual sourcing.');
            }

            $remaining = $this->purchaseOrders->remainingQuantitiesByPrLine($locked);
            $lines = $locked->items->filter(fn ($line): bool => bccomp($remaining[(int) $line->id] ?? '0', '0', 3) > 0);
            if ($lines->isEmpty()) {
                throw new BusinessRuleException('Every line on this purchase request is already on a purchase order.');
            }
            // An award becomes a PO, and a PO line needs an inventory item.
            // Refuse now rather than after suppliers have quoted.
            $unlinked = $lines->first(fn ($line): bool => $line->item_id === null);
            if ($unlinked !== null) {
                throw new BusinessRuleException("Link \"{$unlinked->description}\" to an inventory item on the purchase request before starting an RFQ.");
            }

            $rfq = RequestForQuote::create([
                'rfq_number' => $this->sequences->generate('rfq'),
                'purchase_request_id' => $locked->id,
                'created_by' => $by->id,
                'title' => $data['title'],
                'instructions' => $data['instructions'] ?? null,
                'closes_at' => $this->localDateTime((string) $data['closes_at']),
            ]);
            $rfq->forceFill(['status' => RfqStatus::Draft])->save();

            foreach ($lines as $line) {
                $rfq->items()->create([
                    'purchase_request_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'specification' => $data['specifications'][$line->id] ?? null,
                    'quantity' => $remaining[(int) $line->id],
                    'unit' => $line->unit,
                    'required_delivery_date' => $this->requiredDate($locked, $data['required_delivery_dates'][$line->id] ?? null),
                ]);
            }
            $this->syncInvitations($rfq, $locked, (array) ($data['invitations'] ?? []), $by);

            $locked->forceFill([
                'sourcing_method' => PurchaseRequestSourcingMethod::Rfq,
                'po_conversion_status' => PurchaseRequestConversionStatus::SourcingPending,
                'po_conversion_note' => "RFQ {$rfq->rfq_number} sourcing in progress.",
                'po_conversion_at' => now(),
            ])->save();

            if (! empty($data['publish'])) {
                $this->openLocked($rfq);
            }

            return $rfq;
        });

        return $this->show($rfq->fresh(), $by);
    }

    public function update(RequestForQuote $rfq, array $data, User $by): RequestForQuote
    {
        DB::transaction(function () use ($rfq, $data, $by): void {
            $locked = RequestForQuote::query()->lockForUpdate()->with(['items', 'purchaseRequest.items'])->findOrFail($rfq->id);
            $this->assertManage($by);
            if ($locked->status !== RfqStatus::Draft) {
                throw new BusinessRuleException('Only draft RFQs can be edited.');
            }
            $locked->fill([
                'title' => $data['title'] ?? $locked->title,
                'instructions' => array_key_exists('instructions', $data) ? $data['instructions'] : $locked->instructions,
            ]);
            if (array_key_exists('closes_at', $data)) {
                $locked->closes_at = $this->localDateTime((string) $data['closes_at']);
            }
            $locked->save();

            // On edit the form works with the RFQ's own lines, so overrides are
            // keyed by RFQ line id (on create they are keyed by PR line id).
            foreach ($locked->items as $item) {
                $changes = [];
                if (array_key_exists((int) $item->id, (array) ($data['required_delivery_dates'] ?? []))) {
                    $changes['required_delivery_date'] = $this->requiredDate($locked->purchaseRequest, $data['required_delivery_dates'][$item->id]);
                }
                if (array_key_exists((int) $item->id, (array) ($data['specifications'] ?? []))) {
                    $changes['specification'] = $data['specifications'][$item->id];
                }
                if ($changes !== []) {
                    $item->update($changes);
                }
            }
            if (array_key_exists('invitations', $data)) {
                $this->syncInvitations($locked, $locked->purchaseRequest, (array) $data['invitations'], $by);
            }
        });

        return $this->show($rfq->fresh(), $by);
    }

    public function publish(RequestForQuote $rfq, User $by): RequestForQuote
    {
        DB::transaction(function () use ($rfq, $by): void {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertManage($by);
            if ($locked->status !== RfqStatus::Draft) {
                throw new BusinessRuleException('Only draft RFQs can be published.');
            }
            $this->openLocked($locked);
        });

        return $this->show($rfq->fresh(), $by);
    }

    public function extend(RequestForQuote $rfq, array $data, User $by): RequestForQuote
    {
        DB::transaction(function () use ($rfq, $data, $by): void {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertManage($by);
            if (trim((string) ($data['reason'] ?? '')) === '') {
                throw new BusinessRuleException('An extension reason is required.');
            }
            if ($locked->status !== RfqStatus::Open) {
                throw new BusinessRuleException('Only open RFQs can be extended.');
            }
            if (! $locked->closes_at->isFuture()) {
                throw new BusinessRuleException('The deadline has already passed, so the RFQ is closing. Compare the quotations received, or cancel and start a new RFQ.');
            }
            $deadline = $this->localDateTime((string) $data['closes_at']);
            if ($deadline->lte($locked->closes_at) || $deadline->isPast()) {
                throw new BusinessRuleException('The new deadline must be in the future and later than the current deadline.');
            }
            $locked->forceFill(['closes_at' => $deadline, 'last_extension_reason' => $data['reason']])->save();
            $this->recordLifecycle($locked, 'extended', 'extended:'.$deadline->timestamp);
        });

        return $this->show($rfq->fresh(), $by);
    }

    /** Close before the deadline once every invited supplier has submitted. */
    public function closeNow(RequestForQuote $rfq, User $by): RequestForQuote
    {
        DB::transaction(function () use ($rfq, $by): void {
            $locked = RequestForQuote::query()->lockForUpdate()->with('invitations')->findOrFail($rfq->id);
            $this->assertManage($by);
            if ($locked->status !== RfqStatus::Open) {
                throw new BusinessRuleException('Only open RFQs can be closed.');
            }
            if (! $this->access->allInvitedResponded($locked)) {
                throw new BusinessRuleException('Close now is available once every invited supplier has submitted a quotation. Otherwise the RFQ closes at its deadline.');
            }
            $this->closeLocked($locked);
        });

        return $this->show($rfq->fresh(), $by);
    }

    /**
     * Close every open RFQ past its deadline. The scheduler runs this each
     * minute; reads call it too so a stopped scheduler never leaves an RFQ
     * showing "open" after its deadline.
     */
    public function closeAllDue(): int
    {
        $closed = 0;
        RequestForQuote::query()
            ->where('status', RfqStatus::Open->value)
            ->where('closes_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use (&$closed): void {
                $this->closeDue(RequestForQuote::query()->findOrFail($id));
                $closed++;
            });

        return $closed;
    }

    /** Scheduler and lazy path: close an open RFQ whose deadline has passed. */
    public function closeDue(RequestForQuote $rfq): RequestForQuote
    {
        return DB::transaction(function () use ($rfq): RequestForQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            if ($locked->status === RfqStatus::Open && ! $locked->closes_at->isFuture()) {
                $this->closeLocked($locked);
            }

            return $locked->fresh();
        });
    }

    public function cancel(RequestForQuote $rfq, string $reason, User $by): RequestForQuote
    {
        DB::transaction(function () use ($rfq, $reason, $by): void {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertManage($by);
            if (! in_array($locked->status, [RfqStatus::Draft, RfqStatus::Open, RfqStatus::Closed], true)) {
                throw new BusinessRuleException('Only a draft, open or closed RFQ can be cancelled.');
            }
            $wasPublished = $locked->status !== RfqStatus::Draft;
            $locked->forceFill(['status' => RfqStatus::Cancelled, 'cancellation_reason' => $reason, 'resolved_at' => now()])->save();
            $this->handBack($locked, "RFQ {$locked->rfq_number} was cancelled. Convert by Direct PO or start a new RFQ.");
            if ($wasPublished) {
                $this->recordLifecycle($locked, 'cancelled', 'cancelled');
            }
        });

        return $this->show($rfq->fresh(), $by);
    }

    /**
     * Sealed-bid comparison. Available once closed; the lazy close covers a
     * scheduler that has not ticked yet.
     */
    public function comparison(RequestForQuote $rfq, User $by): RequestForQuote
    {
        $rfq = $this->closeDue($rfq);
        if (! in_array($rfq->status, [RfqStatus::Closed, RfqStatus::Awarded], true)) {
            throw new BusinessRuleException('Supplier prices stay sealed until the RFQ closes.');
        }

        $shown = $this->show($rfq, $by);
        $snapshots = $this->performance->latestForVendors($shown->quotes->pluck('vendor_id')->all());
        // Rank on what a line really costs Ogami. Input VAT is recoverable
        // for a VAT-registered buyer, so a non-VAT supplier must not win
        // merely because its price carries no VAT.
        $exVat = $this->taxPolicy->isVatRegistered();
        $shown->setAttribute('ranking_basis', $exVat ? 'ex_vat' : 'gross');
        $best = [];
        foreach ($shown->quotes as $quote) {
            $snapshot = $snapshots->get((int) $quote->vendor_id);
            $quote->setAttribute('supplier_performance', $snapshot ? [
                'overall_score' => $snapshot->overall_score !== null ? (string) $snapshot->overall_score : null,
                'tier' => $snapshot->tier,
                'on_time_delivery_rate' => $snapshot->on_time_delivery_rate !== null ? (string) $snapshot->on_time_delivery_rate : null,
                'quality_pass_rate' => $snapshot->quality_pass_rate !== null ? (string) $snapshot->quality_pass_rate : null,
                'ncr_rate' => $snapshot->ncr_rate !== null ? (string) $snapshot->ncr_rate : null,
                'period' => sprintf('%04d-%02d', $snapshot->period_year, $snapshot->period_month),
            ] : null);

            $allocated = $this->calculator->allocate($quote);
            $net = $this->calculator->allocateNet($quote);
            foreach ($quote->items as $line) {
                if (! isset($allocated[(int) $line->id]) || bccomp((string) $line->offered_quantity, '0', 4) <= 0) {
                    continue;
                }
                $grossUnit = bcdiv($allocated[(int) $line->id], (string) $line->offered_quantity, 4);
                $netUnit = bcdiv($net[(int) $line->id], (string) $line->offered_quantity, 4);
                $unit = $exVat ? $netUnit : $grossUnit;
                $line->setAttribute('allocated_delivered_cost', $allocated[(int) $line->id]);
                $line->setAttribute('unit_delivered_cost', $grossUnit);
                $line->setAttribute('unit_net_cost', $netUnit);
                $line->setAttribute('meets_required_date', $this->meetsRequiredDate($shown, $line));
                $key = (int) $line->request_for_quote_item_id;
                if (! $quote->isExpired() && (! isset($best[$key]) || bccomp($unit, $best[$key][1], 4) < 0)) {
                    $best[$key] = [(int) $line->id, $unit];
                }
            }
        }
        foreach ($shown->quotes as $quote) {
            foreach ($quote->items as $line) {
                $line->setAttribute('is_recommended', ($best[(int) $line->request_for_quote_item_id][0] ?? null) === (int) $line->id);
            }
        }

        return $shown;
    }

    public function uploadInternalDocument(RequestForQuote $rfq, UploadedFile $file, string $documentType, ?int $vendorId, User $by): RfqDocument
    {
        return DB::transaction(function () use ($rfq, $file, $documentType, $vendorId, $by): RfqDocument {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertManage($by);
            if (! in_array($locked->status, [RfqStatus::Draft, RfqStatus::Open], true)) {
                throw new BusinessRuleException('Documents can only be added while the RFQ is a draft or open.');
            }
            if ($documentType === 'requirement_document') {
                $vendorId = null;
            } elseif ($vendorId === null || ! $locked->invitations()->where('vendor_id', $vendorId)->exists()) {
                throw new BusinessRuleException('Choose an invited supplier for this quotation document.');
            }
            $path = $file->store('rfqs/'.$locked->hash_id.'/internal', 'local');
            try {
                return $locked->documents()->create([
                    'vendor_id' => $vendorId,
                    'supplier_quote_id' => $vendorId === null ? null : SupplierQuote::query()->where('request_for_quote_id', $locked->id)->where('vendor_id', $vendorId)->value('id'),
                    'uploaded_by_user' => $by->id,
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

    public function canDownload(User $user, RfqDocument $document): bool
    {
        $document->loadMissing('rfq');
        if (! $this->access->canSee($user, $document->rfq)) {
            return false;
        }
        if ($document->document_type === 'requirement_document') {
            return true;
        }

        // A supplier's own documents are sealed with its prices.
        return ($user->hasPermission('purchasing.rfq.view') || $user->hasPermission('purchasing.rfq.manage'))
            && in_array($document->rfq->status, [RfqStatus::Closed, RfqStatus::Awarded], true);
    }

    /** @return list<string> */
    public static function respondedStatuses(): array
    {
        return [RfqInvitationStatus::Submitted->value, RfqInvitationStatus::Awarded->value, RfqInvitationStatus::NotAwarded->value];
    }

    public function reach(Vendor $vendor, bool $hasPortalAccount): string
    {
        if ($hasPortalAccount) {
            return 'portal';
        }

        return is_string($vendor->email) && filter_var($vendor->email, FILTER_VALIDATE_EMAIL) ? 'email' : 'none';
    }

    private function openLocked(RequestForQuote $rfq): void
    {
        if (! $rfq->invitations()->exists()) {
            throw new BusinessRuleException('Invite at least one supplier before publishing.');
        }
        if (! $rfq->closes_at || ! $rfq->closes_at->isFuture()) {
            throw new BusinessRuleException('The RFQ deadline must be in the future. Edit the draft to set a new deadline.');
        }
        $rfq->forceFill(['status' => RfqStatus::Open, 'issued_at' => now()])->save();
        $this->recordLifecycle($rfq, 'published', 'published');
    }

    /**
     * Closed with at least one submitted quote → ready to compare. With none,
     * there is nothing to evaluate: cancel and hand the PR back.
     */
    private function closeLocked(RequestForQuote $rfq): void
    {
        $submitted = SupplierQuote::query()
            ->where('request_for_quote_id', $rfq->id)
            ->where('status', SupplierQuoteStatus::Submitted->value)
            ->exists();
        if ($submitted) {
            $rfq->forceFill(['status' => RfqStatus::Closed, 'closed_at' => now()])->save();
            $this->recordLifecycle($rfq, 'closed', 'closed');

            return;
        }
        $rfq->forceFill([
            'status' => RfqStatus::Cancelled,
            'closed_at' => now(),
            'resolved_at' => now(),
            'cancellation_reason' => 'No quotations were received before the deadline.',
        ])->save();
        $this->handBack($rfq, "RFQ {$rfq->rfq_number} closed without quotations. Convert by Direct PO or start a new RFQ.");
        $this->recordLifecycle($rfq, 'cancelled', 'cancelled');
    }

    /** Recompute the PR's coverage and, if anything is left, say where it went. */
    public function handBack(RequestForQuote $rfq, string $note): void
    {
        $pr = PurchaseRequest::query()->lockForUpdate()->findOrFail($rfq->purchase_request_id);
        $this->purchaseOrders->syncConversionStatus($pr);
        $pr->refresh();
        if ($pr->status === PurchaseRequestStatus::Approved) {
            $pr->forceFill(['po_conversion_note' => $note])->save();
        }
    }

    /** @param array<int, array{vendor_id:int, exception_reason?:?string}> $rows */
    private function syncInvitations(RequestForQuote $rfq, PurchaseRequest $pr, array $rows, User $by): void
    {
        if ($rows === []) {
            throw new BusinessRuleException('Invite at least one supplier.');
        }
        $options = collect($this->supplierOptions($pr))->keyBy('id');
        $keep = [];
        foreach ($rows as $row) {
            $vendorId = (int) $row['vendor_id'];
            if (isset($keep[$vendorId])) {
                throw new BusinessRuleException('A supplier may only be invited once per RFQ.');
            }
            $vendor = Vendor::withTrashed()->find($vendorId);
            if (! $vendor || ! $vendor->isPurchasable()) {
                throw new BusinessRuleException('Vendor '.($vendor?->name ?? '#'.$vendorId).' is inactive. Reactivate it or choose another supplier.');
            }
            $reason = trim((string) ($row['exception_reason'] ?? ''));
            if (! ($options->get($vendorId)['qualified'] ?? false) && $reason === '') {
                throw new BusinessRuleException("Give a reason for inviting {$vendor->name}: it is not an approved supplier for every line.");
            }
            $keep[$vendorId] = true;
            $rfq->invitations()->updateOrCreate(
                ['vendor_id' => $vendorId],
                ['invited_by' => $by->id, 'invited_at' => now(), 'exception_reason' => $reason === '' ? null : $reason],
            );
        }
        $rfq->invitations()->whereNotIn('vendor_id', array_keys($keep))->delete();
    }

    private function requiredDate(PurchaseRequest $pr, mixed $entered): ?string
    {
        if (is_string($entered) && trim($entered) !== '') {
            return $entered;
        }
        // Fall back to the PR need-by date only while it is still ahead, so a
        // lapsed date never becomes an overdue-at-birth PO via the award.
        $prDate = $pr->required_delivery_date?->toDateString();

        return $prDate !== null && $prDate > now()->toDateString() ? $prDate : null;
    }

    private function meetsRequiredDate(RequestForQuote $rfq, SupplierQuoteItem $line): ?bool
    {
        $required = $rfq->items->firstWhere('id', (int) $line->request_for_quote_item_id)?->required_delivery_date;
        if ($required === null || $line->response_status !== SupplierQuoteResponseStatus::Quoted) {
            return null;
        }
        if ($line->proposed_delivery_date !== null) {
            return $line->proposed_delivery_date->lte($required);
        }
        if ($line->lead_time_days !== null) {
            return today()->addDays((int) $line->lead_time_days)->lte($required);
        }

        return null;
    }

    private function assertManage(User $user): void
    {
        if (! $this->access->canManage($user)) {
            throw new BusinessRuleException('RFQ management permission is required.');
        }
    }

    private function localDateTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
    }

    private function recordLifecycle(RequestForQuote $rfq, string $kind, string $suffix): void
    {
        $this->outbox->record(
            new RfqLifecycleEvent((int) $rfq->id, (string) $rfq->hash_id, $kind),
            'rfq:'.$rfq->id.':'.$suffix,
        );
    }
}
