<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\PpapElementStatus;
use App\Modules\Quality\Enums\PpapElementType;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapElement;
use App\Modules\Quality\Models\PpapSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PpapService
{
    /** PPAP approval validity window. */
    private const APPROVAL_VALIDITY_YEARS = 3;

    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = PpapSubmission::query()->with(['vendor:id,name', 'item:id,item_code,name', 'elements']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], \App\Modules\Accounting\Models\Vendor::class);
            if ($vid) $q->where('vendor_id', $vid);
        }
        if (! empty($filters['item_id'])) {
            $iid = HashIdFilter::decode($filters['item_id'], Item::class);
            if ($iid) $q->where('item_id', $iid);
        }
        if (! empty($filters['search'])) {
            $q->where('ppap_number', 'ilike', '%'.$filters['search'].'%');
        }

        return $q->latest('id')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(PpapSubmission $ppap): PpapSubmission
    {
        return $ppap->load([
            'vendor:id,name', 'item:id,item_code,name', 'product:id,name',
            'purchaseOrder:id,po_number', 'submitter:id,name', 'reviewer:id,name',
            'approver:id,name', 'elements',
        ]);
    }

    /**
     * Create a submission. Auto-attaches a Part Submission Warrant element (the
     * one element required at every level) so a fresh submission is never empty.
     */
    public function create(array $data, User $by): PpapSubmission
    {
        return DB::transaction(function () use ($data, $by) {
            $ppap = PpapSubmission::create([
                'ppap_number'       => $this->sequences->generate('ppap'),
                'vendor_id'         => $data['vendor_id'],
                'item_id'           => $data['item_id'],
                'product_id'        => $data['product_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'ppap_level'        => (string) $data['ppap_level'],
                'submission_date'   => $data['submission_date'] ?? now()->toDateString(),
                'status'            => PpapStatus::Draft->value,
                'submitted_by'      => $by->id,
                'notes'             => $data['notes'] ?? null,
            ]);

            PpapElement::create([
                'ppap_submission_id' => $ppap->id,
                'element_type'       => PpapElementType::PartSubmissionWarrant->value,
                'status'             => PpapElementStatus::Pending->value,
            ]);

            return $this->show($ppap);
        });
    }

    public function update(PpapSubmission $ppap, array $data): PpapSubmission
    {
        if ($ppap->status->isTerminal() || $ppap->status === PpapStatus::Approved) {
            throw new RuntimeException('Cannot edit a finalized PPAP submission.');
        }
        $ppap->update(array_filter([
            'ppap_level' => $data['ppap_level'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'notes'      => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->show($ppap);
    }

    public function submit(PpapSubmission $ppap): PpapSubmission
    {
        if ($ppap->status !== PpapStatus::Draft) {
            throw new RuntimeException('Only draft PPAP submissions can be submitted.');
        }
        if ($ppap->elements()->count() < 1) {
            throw new RuntimeException('PPAP submission requires at least one element.');
        }
        $ppap->update([
            'status'          => PpapStatus::Submitted->value,
            'submission_date' => now()->toDateString(),
        ]);
        return $this->show($ppap);
    }

    public function review(PpapSubmission $ppap, User $by): PpapSubmission
    {
        if (! in_array($ppap->status, [PpapStatus::Submitted, PpapStatus::UnderReview], true)) {
            throw new RuntimeException('Only submitted PPAP submissions can be reviewed.');
        }
        $ppap->update([
            'status'      => PpapStatus::UnderReview->value,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);
        return $this->show($ppap);
    }

    /**
     * Approve. All elements must be accepted or not-applicable. Sets a 3-year
     * expiry — after which vendorHasActivePpap() will block the part again.
     */
    public function approve(PpapSubmission $ppap, User $by): PpapSubmission
    {
        if (! in_array($ppap->status, [PpapStatus::Submitted, PpapStatus::UnderReview], true)) {
            throw new RuntimeException('Only submitted/under-review PPAP submissions can be approved.');
        }
        $unresolved = $ppap->elements()
            ->whereNotIn('status', [PpapElementStatus::Accepted->value, PpapElementStatus::NotApplicable->value])
            ->count();
        if ($unresolved > 0) {
            throw new RuntimeException("Cannot approve: {$unresolved} element(s) not yet accepted.");
        }

        $ppap->update([
            'status'      => PpapStatus::Approved->value,
            'approved_by' => $by->id,
            'approved_at' => now(),
            'expires_at'  => now()->addYears(self::APPROVAL_VALIDITY_YEARS),
        ]);
        return $this->show($ppap);
    }

    public function reject(PpapSubmission $ppap, string $reason, User $by): PpapSubmission
    {
        if ($ppap->status->isTerminal()) {
            throw new RuntimeException('PPAP submission is already finalized.');
        }
        $ppap->update([
            'status'           => PpapStatus::Rejected->value,
            'rejection_reason' => $reason,
            'reviewed_by'      => $by->id,
            'reviewed_at'      => now(),
        ]);
        return $this->show($ppap);
    }

    public function updateElement(PpapElement $element, array $data): PpapElement
    {
        $element->update(array_filter([
            'status'        => $data['status'] ?? null,
            'document_path' => $data['document_path'] ?? null,
            'notes'         => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));
        return $element->fresh();
    }

    /** Mark approved-but-expired submissions as expired. Returns count. */
    public function expireOverdue(): int
    {
        return PpapSubmission::query()
            ->where('status', PpapStatus::Approved->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => PpapStatus::Expired->value]);
    }

    /**
     * PO-gate helper: may this vendor supply this item?
     *
     * Returns TRUE when there is an approved, non-expired PPAP for the vendor+item
     * OR when no PPAP has ever been registered for that pair (you can't gate a
     * part that was never put under PPAP control). Returns FALSE only when a
     * registration exists but none is currently approved.
     */
    public function vendorHasActivePpap(int $vendorId, int $itemId): bool
    {
        $hasAny = PpapSubmission::query()
            ->where('vendor_id', $vendorId)
            ->where('item_id', $itemId)
            ->exists();

        if (! $hasAny) {
            return true; // never registered → not gated
        }

        return PpapSubmission::query()
            ->where('vendor_id', $vendorId)
            ->where('item_id', $itemId)
            ->activeApproved()
            ->exists();
    }
}
