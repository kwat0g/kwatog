<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RequestForQuoteService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly VendorSourcingService $sourcing,
        private readonly PurchaseRequestAccessPolicy $purchaseRequestAccess,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly OutboxService $outbox,
        private readonly SupplierPerformanceService $performance,
    ) {}

    public function list(array $filters, User $user): LengthAwarePaginator
    {
        $query = RequestForQuote::query()->with(['purchaseRequest:id,pr_number', 'creator:id,name']);
        if (! $user->hasPermission('purchasing.rfq.manage') && ! $user->hasPermission('purchasing.rfq.evaluate') && ! $user->hasPermission('purchasing.rfq.quality_review')) {
            $query->where(function ($q) use ($user): void {
                $q->where('created_by', $user->id)
                    ->orWhereHas('purchaseRequest', fn ($pr) => $pr->where('requested_by', $user->id));
            });
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->where('rfq_number', SearchOperator::like(), SearchOperator::contains($filters['search']));
        }

        return $query->orderByDesc('created_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(RequestForQuote $rfq, ?User $user = null): RequestForQuote
    {
        if ($user && ! $this->canSee($rfq, $user)) {
            throw new BusinessRuleException('You do not have access to this RFQ.');
        }

        return $rfq->load([
            'purchaseRequest:id,pr_number,department_id',
            'purchaseRequest.department:id,name,code',
            'creator:id,name',
            'items.item:id,code,name,unit_of_measure',
            'invitations.vendor:id,name,email',
            'quotes' => fn ($q) => $q->where('is_current', true)->with(['vendor:id,name', 'items.rfqItem']),
            'awards.vendor:id,name',
            'awards.rfqItem',
            'awards.quote',
            'documents',
            'addenda.publisher:id,name',
        ]);
    }

    public function createFromPurchaseRequest(PurchaseRequest $pr, array $data, User $by): RequestForQuote
    {
        return DB::transaction(function () use ($pr, $data, $by): RequestForQuote {
            $locked = PurchaseRequest::query()->lockForUpdate()->with(['items.item'])->findOrFail($pr->id);
            if (! $this->purchaseRequestAccess->canView($by, $locked)) {
                throw new BusinessRuleException('You do not have access to this purchase request.');
            }
            if ($locked->status !== PurchaseRequestStatus::Approved) {
                throw new BusinessRuleException('Only approved purchase requests can start an RFQ.');
            }
            if (! $locked->is_auto_generated || $locked->sourcing_method !== PurchaseRequestSourcingMethod::Rfq) {
                throw new BusinessRuleException('This purchase request is not marked for competitive RFQ sourcing.');
            }
            if ($locked->purchaseOrders()->where('status', '!=', 'cancelled')->exists()) {
                throw new BusinessRuleException('This purchase request already has an active purchase order.');
            }
            $activeRfq = $locked->rfqs()->whereIn('status', RfqStatus::active())->latest('id')->first();
            if ($activeRfq) {
                return $this->show($activeRfq, $by);
            }

            $rfq = RequestForQuote::create([
                'rfq_number' => $this->sequences->generate('rfq'),
                'purchase_request_id' => $locked->id,
                'created_by' => $by->id,
                'title' => $data['title'],
                'instructions' => $data['instructions'] ?? null,
                'currency' => 'PHP',
                'closes_at' => $this->localDateTime((string) $data['closes_at']),
            ]);
            $rfq->forceFill(['status' => RfqStatus::Draft])->save();

            foreach ($locked->items as $line) {
                $rfq->items()->create([
                    'purchase_request_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'specification' => $data['specifications'][$line->id] ?? null,
                    'quantity' => (string) $line->quantity,
                    'unit' => $line->unit,
                    'required_delivery_date' => $data['required_delivery_dates'][$line->id] ?? null,
                    'allow_partial_quantity' => (bool) ($data['allow_partial_quantity'][$line->id] ?? true),
                    'allow_substitute' => false,
                ]);
            }

            $seenVendors = [];
            foreach ((array) ($data['invitations'] ?? []) as $invitation) {
                $vendorId = (int) $invitation['vendor_id'];
                if (isset($seenVendors[$vendorId])) {
                    throw new BusinessRuleException('A supplier may only be invited once per RFQ.');
                }
                $seenVendors[$vendorId] = true;
                if (! $this->isQualifiedForRfq($locked, $vendorId) && trim((string) ($invitation['exception_reason'] ?? '')) === '') {
                    throw new BusinessRuleException('A reason is required when inviting a non-qualified supplier.');
                }
                $rfq->invitations()->create([
                    'vendor_id' => $vendorId,
                    'invited_by' => $by->id,
                    'invited_at' => now(),
                    'exception_reason' => $invitation['exception_reason'] ?? null,
                ]);
            }

            $locked->forceFill([
                'po_conversion_status' => PurchaseRequestConversionStatus::SourcingPending,
                'po_conversion_note' => 'RFQ sourcing in progress.',
                'po_conversion_at' => now(),
            ])->save();

            return $this->show($rfq->fresh(), $by);
        });
    }

    public function update(RequestForQuote $rfq, array $data, User $by): RequestForQuote
    {
        return DB::transaction(function () use ($rfq, $data, $by): RequestForQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertDraft($locked, $by);
            $locked->update([
                'title' => $data['title'] ?? $locked->title,
                'instructions' => array_key_exists('instructions', $data) ? $data['instructions'] : $locked->instructions,
                'closes_at' => array_key_exists('closes_at', $data)
                    ? $this->localDateTime((string) $data['closes_at'])
                    : $locked->closes_at,
            ]);

            return $this->show($locked->fresh(), $by);
        });
    }

    public function publish(RequestForQuote $rfq, User $by): RequestForQuote
    {
        return DB::transaction(function () use ($rfq, $by): RequestForQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->with('invitations')->findOrFail($rfq->id);
            $this->assertDraft($locked, $by);
            if ($locked->invitations->isEmpty()) {
                throw new BusinessRuleException('Select at least one supplier before publishing.');
            }
            if (! $locked->closes_at || $locked->closes_at->isPast()) {
                throw new BusinessRuleException('The RFQ deadline must be in the future.');
            }
            $locked->forceFill(['status' => RfqStatus::Open, 'issued_at' => now()])->save();
            $this->recordLifecycle($locked, 'published', 'published');

            return $this->show($locked->fresh(), $by);
        });
    }

    public function extend(RequestForQuote $rfq, array $data, User $by): RequestForQuote
    {
        return DB::transaction(function () use ($rfq, $data, $by): RequestForQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertBuyer($by);
            if (trim((string) ($data['reason'] ?? '')) === '') {
                throw new BusinessRuleException('An extension reason is required.');
            }
            if ($locked->status !== RfqStatus::Open) {
                throw new BusinessRuleException('Only open RFQs can be extended.');
            }
            $newDeadline = $this->localDateTime((string) $data['closes_at']);
            if ($newDeadline->lte($locked->closes_at)) {
                throw new BusinessRuleException('The new deadline must be later than the current deadline.');
            }
            $locked->forceFill(['closes_at' => $newDeadline, 'last_extension_reason' => $data['reason']])->save();
            $this->recordLifecycle($locked, 'extended', 'extended:'.($locked->updated_at?->timestamp ?? now()->timestamp));

            return $this->show($locked->fresh(), $by);
        });
    }

    public function addendum(RequestForQuote $rfq, array $data, User $by): RequestForQuote
    {
        return DB::transaction(function () use ($rfq, $data, $by): RequestForQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertBuyer($by);
            if ($locked->status !== RfqStatus::Open) {
                throw new BusinessRuleException('Only open RFQs can receive addenda.');
            }
            $sequence = ((int) $locked->addenda()->max('sequence')) + 1;
            $locked->addenda()->create([
                'published_by' => $by->id, 'sequence' => $sequence,
                'title' => $data['title'], 'body' => $data['body'],
                'material_change' => (bool) ($data['material_change'] ?? false), 'published_at' => now(),
            ]);
            if ((bool) ($data['material_change'] ?? false)) {
                $locked->forceFill(['closes_at' => $locked->closes_at->addDays((int) ($data['extension_days'] ?? 2))])->save();
            }
            $this->recordLifecycle($locked, 'addendum', 'addendum:'.$sequence);

            return $this->show($locked->fresh(), $by);
        });
    }

    public function closeDue(RequestForQuote $rfq): RequestForQuote
    {
        return DB::transaction(function () use ($rfq): RequestForQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->with('quotes')->findOrFail($rfq->id);
            if ($locked->status === RfqStatus::Open && $locked->closes_at->isPast()) {
                $hasValid = $locked->quotes->contains(fn (SupplierQuote $quote): bool => $quote->status === SupplierQuoteStatus::Submitted && $quote->is_current);
                $locked->forceFill([
                    'status' => $hasValid ? RfqStatus::Closed : RfqStatus::NoAward,
                    'closed_at' => now(),
                    'evaluation_started_at' => $hasValid ? now() : null,
                    'resolved_at' => $hasValid ? null : now(),
                    'no_award_reason' => $hasValid ? null : 'No valid supplier quotations were received before the deadline.',
                ])->save();
                $this->recordLifecycle($locked, $hasValid ? 'closed' : 'no_award', 'closed:'.($locked->closed_at?->timestamp ?? now()->timestamp));
                if (! $hasValid) {
                    $this->purchaseOrders->syncConversionStatus($locked->purchaseRequest()->lockForUpdate()->firstOrFail());
                }
            }

            return $locked->fresh();
        });
    }

    public function cancel(RequestForQuote $rfq, string $reason, User $by): RequestForQuote
    {
        return DB::transaction(function () use ($rfq, $reason, $by): RequestForQuote {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertBuyer($by);
            if (in_array($locked->status, [RfqStatus::Awarded, RfqStatus::PartiallyAwarded], true)) {
                throw new BusinessRuleException('An awarded RFQ cannot be cancelled.');
            }
            $locked->purchaseRequest()->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => RfqStatus::Cancelled, 'cancellation_reason' => $reason, 'resolved_at' => now()])->save();
            $this->purchaseOrders->syncConversionStatus($locked->purchaseRequest()->lockForUpdate()->firstOrFail());
            $this->recordLifecycle($locked, 'cancelled', 'cancelled:'.($locked->resolved_at?->timestamp ?? now()->timestamp));

            return $this->show($locked->fresh(), $by);
        });
    }

    public function comparison(RequestForQuote $rfq, User $by): RequestForQuote
    {
        $rfq = $this->closeDue($rfq);
        if (! in_array($rfq->status, [RfqStatus::Closed, RfqStatus::UnderEvaluation, RfqStatus::Awarded, RfqStatus::PartiallyAwarded, RfqStatus::NoAward], true)) {
            throw new BusinessRuleException('Supplier prices remain sealed until the RFQ closes.');
        }

        $shown = $this->show($rfq, $by);
        $snapshots = $this->performance->latestForVendors($shown->quotes->pluck('vendor_id')->all());
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
        }
        foreach ($shown->items as $item) {
            $candidates = $shown->quotes->flatMap(fn ($quote) => $quote->items->filter(
                fn ($line) => (int) $line->request_for_quote_item_id === (int) $item->id
                    && ($line->response_status?->value ?? (string) $line->response_status) === 'quoted'
                    && ($line->compliance_status?->value ?? (string) $line->compliance_status) !== 'blocking'
                    && Money::gt((string) $line->offered_quantity, '0'),
            ));
            $best = $candidates->sortBy(fn ($line) => Money::div((string) $line->line_total_delivered_cost, (string) $line->offered_quantity))->first();
            foreach ($candidates as $line) {
                $line->setAttribute('is_recommended', $best !== null && $line->id === $best->id);
            }
        }

        return $shown;
    }

    public function uploadInternalDocument(RequestForQuote $rfq, UploadedFile $file, string $documentType, ?int $vendorId, User $by): RfqDocument
    {
        return DB::transaction(function () use ($rfq, $file, $documentType, $vendorId, $by) {
            $locked = RequestForQuote::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertBuyer($by);
            if (! in_array($locked->status, [RfqStatus::Draft, RfqStatus::Open], true)) {
                throw new BusinessRuleException('RFQ documents cannot be changed after the sourcing event is resolved.');
            }
            if ($vendorId !== null && ! $locked->invitations()->where('vendor_id', $vendorId)->exists()) {
                throw new BusinessRuleException('The selected supplier is not invited to this RFQ.');
            }
            $path = $file->store('rfqs/'.$locked->hash_id.'/internal', 'local');
            try {
                return $locked->documents()->create([
                    'vendor_id' => $vendorId,
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

    private function isQualifiedForRfq(PurchaseRequest $pr, int $vendorId): bool
    {
        foreach ($pr->items as $line) {
            if ($line->item_id === null) {
                return false;
            }
            $qualified = collect($this->sourcing->candidatesForItem((int) $line->item_id))->firstWhere('vendor_id', $vendorId);
            if (! $qualified || ! $qualified['qualified']) {
                return false;
            }
        }

        return true;
    }

    private function canSee(RequestForQuote $rfq, User $user): bool
    {
        return $user->hasPermission('purchasing.rfq.manage')
            || $user->hasPermission('purchasing.rfq.evaluate')
            || $user->hasPermission('purchasing.rfq.quality_review')
            || $rfq->created_by === $user->id
            || $rfq->purchaseRequest()->where('requested_by', $user->id)->exists();
    }

    private function assertBuyer(User $user): void
    {
        if (! $user->hasPermission('purchasing.rfq.manage')) {
            throw new BusinessRuleException('RFQ management permission is required.');
        }
    }

    private function assertDraft(RequestForQuote $rfq, User $by): void
    {
        $this->assertBuyer($by);
        if ($rfq->status !== RfqStatus::Draft) {
            throw new BusinessRuleException('Only draft RFQs can be edited.');
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
