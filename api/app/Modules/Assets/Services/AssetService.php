<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Common\Support\SearchOperator;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Models\AssetTransfer;
use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Sprint 8 — Task 70. */
class AssetService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly JournalEntryService $journals,
        private readonly AccountingPeriodService $periods,
        private readonly SettingsService $settings,
        private readonly ApprovalService $approvals,
        private readonly DepreciationService $depreciation,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Asset::query()->with('department:id,name,code', 'disposalRequester:id,name');

        foreach (['category', 'status', 'department_id'] as $f) {
            if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('asset_code', SearchOperator::like(), $term)
                ->orWhere('name', SearchOperator::like(), $term));
        }
        return $q->orderBy('asset_code')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(Asset $asset): Asset
    {
        // `depreciations.journalEntry` is eager-loaded because AssetResource
        // publishes each row's journal hash. Resolving it per row cost one
        // `select * from journal_entries` per month of history — measured 6
        // extra queries for 6 rows, so a five-year asset paid 60 on every
        // detail load. `AssetDepreciationController::index` already loads the
        // relation this way.
        return $asset->load([
            'department:id,name,code',
            'depreciations.journalEntry:id',
            'disposalRequester:id,name',
            'approvalRecords.approver:id,name',
        ]);
    }

    public function create(array $data): Asset
    {
        return DB::transaction(function () use ($data) {
            $salvage = $data['salvage_value'] ?? Money::zero();
            if ($salvage === null || trim((string) $salvage) === '') {
                $salvage = Money::zero();
            }
            $this->assertSalvageWithinCost((string) $salvage, (string) $data['acquisition_cost']);

            $asset = Asset::create([
                'asset_code'        => $this->sequences->generate('asset'),
                'name'              => $data['name'],
                'description'       => $data['description'] ?? null,
                'category'          => AssetCategory::from((string) $data['category'])->value,
                'department_id'     => $data['department_id'] ?? null,
                'acquisition_date'  => $data['acquisition_date'],
                'acquisition_cost'  => $data['acquisition_cost'],
                'useful_life_years' => (int) $data['useful_life_years'],
                'depreciation_method' => $data['depreciation_method'] ?? \App\Modules\Assets\Enums\DepreciationMethod::StraightLine->value,
                'salvage_value'     => $salvage,
                'status'            => AssetStatus::Active->value,
                'location'          => $data['location'] ?? null,
                'insurance_policy_no' => $data['insurance_policy_no'] ?? null,
                'insurance_provider'  => $data['insurance_provider'] ?? null,
                'insurance_expiry'    => $data['insurance_expiry'] ?? null,
                'insured_value'       => $data['insured_value'] ?? null,
            ]);
            return $asset->fresh();
        });
    }

    public function update(Asset $asset, array $data): Asset
    {
        return DB::transaction(function () use ($asset, $data) {
            // Lock-then-guard: re-read so a concurrent dispose cannot slip a
            // write onto an asset that just became immutable.
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            if ($locked->status === AssetStatus::Disposed) {
                throw new BusinessRuleException('Disposed assets are immutable.');
            }

            $hasDepreciationHistory = AssetDepreciation::query()
                ->where('asset_id', $locked->getKey())
                ->exists();
            if ($hasDepreciationHistory) {
                if (array_key_exists('useful_life_years', $data)
                    && (int) $data['useful_life_years'] !== (int) $locked->useful_life_years) {
                    throw new BusinessRuleException('Useful life cannot change after depreciation has been posted.');
                }
                $requestedSalvage = $data['salvage_value'] ?? Money::zero();
                if ($requestedSalvage === null || trim((string) $requestedSalvage) === '') {
                    $requestedSalvage = Money::zero();
                }
                if (array_key_exists('salvage_value', $data)
                    && Money::cmp((string) $requestedSalvage, (string) $locked->salvage_value) !== 0) {
                    throw new BusinessRuleException('Salvage value cannot change after depreciation has been posted.');
                }
            }

            $changes = array_intersect_key($data, array_flip([
                'name', 'description', 'department_id', 'location',
                'useful_life_years', 'salvage_value',
            ]));
            if (array_key_exists('salvage_value', $changes)
                && ($changes['salvage_value'] === null || trim((string) $changes['salvage_value']) === '')) {
                $changes['salvage_value'] = Money::zero();
            }
            // Only a *change* has to satisfy the residual-value bound. An
            // unchanged value that already breaks it is left alone: acquisition
            // cost is not updatable and salvage freezes once history exists, so
            // rejecting the resubmitted value would make the row un-editable
            // entirely. See UpdateAssetRequest::withValidator().
            if (array_key_exists('salvage_value', $changes)
                && Money::cmp((string) $changes['salvage_value'], (string) $locked->salvage_value) !== 0) {
                $this->assertSalvageWithinCost(
                    (string) $changes['salvage_value'],
                    (string) $locked->acquisition_cost,
                );
            }
            $locked->fill($changes);
            $locked->save();
            return $locked->fresh();
        });
    }

    /**
     * Salvage value is residual value: it cannot exceed acquisition cost.
     *
     * Enforced here as well as in the FormRequests so the invariant holds for
     * every caller (commands, jobs, seeders), not just HTTP. Over the bound,
     * `Asset::getMonthlyDepreciationAttribute()` clamps the depreciable base to
     * zero, so the asset would be accepted and then never depreciate — a
     * missing expense that raises nothing.
     */
    private function assertSalvageWithinCost(string $salvage, string $acquisitionCost): void
    {
        if (Money::gt($salvage, $acquisitionCost)) {
            throw new BusinessRuleException(sprintf(
                'Salvage value %s cannot exceed the acquisition cost %s.',
                Money::round2($salvage),
                Money::round2($acquisitionCost),
            ));
        }
    }

    /**
     * REQUEST phase of disposal (AS-03): store the proposal and open the
     * seeded `asset_disposal` approval chain. No ledger effect happens here —
     * the JE only posts from approveDisposal() once every step has approved.
     *
     * `assets.dispose` is the request gate (the route + FormRequest); the
     * maker must still find a checker: ApprovalService refuses self-action,
     * so a single-user role cannot push its own disposal through.
     */
    public function requestDisposal(Asset $asset, array $data, User $by): Asset
    {
        return DB::transaction(function () use ($asset, $data, $by) {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            if ($locked->status === AssetStatus::Disposed) {
                throw new BusinessRuleException('Asset already disposed.');
            }
            if ($this->approvals->nextStep($locked) !== null) {
                throw new BusinessRuleException('A disposal request is already pending approval for this asset.');
            }

            $disposedDate = CarbonImmutable::parse((string) ($data['disposed_date'] ?? now()->toDateString()))->startOfDay();
            $acquisitionDate = CarbonImmutable::parse($locked->acquisition_date->toDateString())->startOfDay();
            if ($disposedDate->lt($acquisitionDate)) {
                throw new BusinessRuleException('Disposal date cannot be before the acquisition date.');
            }
            if ($disposedDate->gt(CarbonImmutable::today())) {
                throw new BusinessRuleException('Disposal date cannot be in the future.');
            }
            $this->periods->assertPostingAllowed($disposedDate->toDateString());

            $reason = trim((string) ($data['remarks'] ?? $data['disposal_reason'] ?? ''));
            if ($reason === '') {
                throw new BusinessRuleException('A disposal reason is required.');
            }

            $disposalAmount = Money::round2((string) ($data['disposal_amount'] ?? Money::zero()));

            $locked->forceFill([
                'disposal_request_amount' => $disposalAmount,
                'disposal_request_date'   => $disposedDate->toDateString(),
                'disposal_request_reason' => $reason,
                'disposal_requested_by'   => $by->id,
            ])->save();

            try {
                $this->approvals->submit($locked, 'asset_disposal', $disposalAmount);
            } catch (\Throwable $e) {
                // Same shape as return_request submit: a missing/inactive
                // workflow definition must strand neither the proposal
                // columns nor the operator — fail the submission whole.
                Log::warning('asset_disposal approval submit failed', [
                    'asset_id' => $locked->getKey(),
                    'error'    => $e->getMessage(),
                ]);

                throw new BusinessRuleException(
                    'The asset disposal approval workflow is not configured, so this disposal cannot be requested. '
                    . 'Ask an administrator to set up the "Asset Disposal Approval" workflow.'
                );
            }

            return $locked->fresh();
        });
    }

    /**
     * EXECUTE trigger of disposal: records one approver step, and when the
     * chain is complete runs the existing disposal logic (JE + status flip).
     * Mirrors PurchaseRequestService::approve — completion is evaluated in
     * the service that owns the document, inside the same transaction, not
     * in a listener.
     */
    public function approveDisposal(Asset $asset, User $by, ?string $remarks = null): Asset
    {
        return DB::transaction(function () use ($asset, $by, $remarks) {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            if ($locked->status === AssetStatus::Disposed) {
                throw new BusinessRuleException('Asset already disposed.');
            }
            if ($this->approvals->nextStep($locked) === null) {
                throw new BusinessRuleException('No pending disposal request to approve.');
            }
            $this->assertMayDecideDisposal($by);

            $this->approvals->approve($locked, $by, $remarks);

            if ($this->approvals->isFullyApproved($locked)) {
                $this->dispose($locked, [
                    'disposal_amount' => (string) $locked->disposal_request_amount,
                    'disposed_date'   => optional($locked->disposal_request_date)?->toDateString(),
                    'remarks'         => (string) $locked->disposal_request_reason,
                ], $by);
            }

            return $locked->fresh();
        });
    }

    /**
     * Rejection leaves the asset untouched: still active, no JE, proposal
     * cleared. A new request may be raised at any time.
     */
    public function rejectDisposal(Asset $asset, User $by, string $reason): Asset
    {
        return DB::transaction(function () use ($asset, $by, $reason) {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            if ($this->approvals->nextStep($locked) === null) {
                throw new BusinessRuleException('No pending disposal request to reject.');
            }
            $this->assertMayDecideDisposal($by);

            $this->approvals->reject($locked, $by, $reason);
            $this->clearDisposalRequest($locked);

            return $locked->fresh();
        });
    }

    /**
     * The requester may withdraw a pending disposal request; nobody else.
     */
    public function cancelDisposalRequest(Asset $asset, User $by): Asset
    {
        return DB::transaction(function () use ($asset, $by) {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            if ($this->approvals->nextStep($locked) === null) {
                throw new BusinessRuleException('No pending disposal request to cancel.');
            }
            if ((int) $locked->disposal_requested_by !== (int) $by->id) {
                throw new ForbiddenActionException('Only the requester can cancel a pending disposal request.');
            }

            ApprovalRecord::query()
                ->where('approvable_type', $locked->getMorphClass())
                ->where('approvable_id', $locked->getKey())
                ->where('is_current', true)
                ->whereIn('action', ['pending', 'skipped'])
                ->update(['action' => 'superseded', 'is_current' => false]);

            $this->clearDisposalRequest($locked);

            return $locked->fresh();
        });
    }

    /**
     * Defence in depth (the route already carries
     * `permission:assets.dispose.approve`): the approve/reject arms must stay
     * unreachable for callers that never passed that middleware.
     */
    private function assertMayDecideDisposal(User $by): void
    {
        if (! $by->hasPermission('assets.dispose.approve')) {
            throw new ForbiddenActionException('You do not have permission to approve asset disposals.');
        }
    }

    private function clearDisposalRequest(Asset $locked): void
    {
        $locked->forceFill([
            'disposal_request_amount' => null,
            'disposal_request_date'   => null,
            'disposal_request_reason' => null,
            'disposal_requested_by'   => null,
        ])->save();
    }

    /**
     * Dispose an asset.
     *
     * Depreciation runs THROUGH the disposal month, so any months lacking a
     * posted run row — including the disposal month itself — are caught up
     * first, and the JE below reverses the fully caught-up balance.
     *
     * Posts a JE that:
     *   DR Cash on Hand (disposal_amount)
     *   DR Accumulated Depreciation (asset.accumulated_depreciation)
     *   DR Loss on Disposal      (if cost - accum > disposal_amount)
     *   CR Property Plant & Equipment (asset.acquisition_cost)
     *   CR Gain on Disposal      (if disposal_amount > book_value)
     *
     * AS-03: this is the EXECUTE arm, reached only from approveDisposal()
     * once the seeded chain has fully approved. The guard below makes that a
     * property of the method itself, so no future route or caller can post
     * the derecognition JE on a single permission again.
     */
    public function dispose(Asset $asset, array $data, User $by): Asset
    {
        return DB::transaction(function () use ($asset, $data, $by) {
            // Lock-then-guard: re-read so a concurrent dispose cannot post a
            // second disposal JE from a stale snapshot.
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            if ($locked->status === AssetStatus::Disposed) {
                throw new BusinessRuleException('Asset already disposed.');
            }
            if (! $this->approvals->isFullyApproved($locked)) {
                throw new BusinessRuleException('Asset disposal requires an approved disposal request; submit one for approval first.');
            }

            $disposedDate = CarbonImmutable::parse((string) ($data['disposed_date'] ?? now()->toDateString()))->startOfDay();
            $acquisitionDate = CarbonImmutable::parse($locked->acquisition_date->toDateString())->startOfDay();
            if ($disposedDate->lt($acquisitionDate)) {
                throw new BusinessRuleException('Disposal date cannot be before the acquisition date.');
            }
            if ($disposedDate->gt(CarbonImmutable::today())) {
                throw new BusinessRuleException('Disposal date cannot be in the future.');
            }

            // Surface the canonical closed-period error before checking the
            // operator-entered reason. This keeps a closed-period rejection
            // actionable even when an old client omitted the new reason field.
            $this->periods->assertPostingAllowed($disposedDate->toDateString());

            $reason = trim((string) ($data['remarks'] ?? $data['disposal_reason'] ?? ''));
            if ($reason === '') {
                throw new BusinessRuleException('A disposal reason is required.');
            }

            // Catch up every unposted month through the disposal month under
            // the same lock and transaction, then build the disposal JE from
            // the updated balance. $locked is kept current by the catch-up.
            $this->depreciation->catchUpThrough($locked, $disposedDate, $by);

            $disposalAmount = Money::round2((string) ($data['disposal_amount'] ?? Money::zero()));
            $cost = Money::round2((string) $locked->acquisition_cost);
            $accumulated = Money::round2((string) $locked->accumulated_depreciation);
            $bookValue = Money::clampMin(Money::sub($cost, $accumulated), Money::zero());

            $cashAcct  = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_cash_code'))->firstOrFail();
            $accumAcct = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_accumulated_depreciation_code'))->firstOrFail();
            $assetAcct = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_cost_code'))->firstOrFail();
            $lossAcct  = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_disposal_loss_code'))->firstOrFail();
            $gainAcct  = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_disposal_gain_code'))->firstOrFail();

            // Only non-zero lines are journalised. JournalEntryService rejects a
            // line whose debit and credit are both zero, so emitting the cash or
            // accumulated-depreciation line unconditionally made two ordinary
            // disposals impossible: a zero-proceeds scrapping (the common case
            // for a worn-out mold or written-off machine, and explicitly allowed
            // by DisposeAssetRequest's `min:0`), and the disposal of an asset
            // that has not been depreciated yet. Dropping a zero line changes no
            // balance — it carries no accounting information — and the entry
            // stays balanced because acquisition cost is credited either way.
            $lines = [];
            if (Money::gt($disposalAmount, Money::zero())) {
                $lines[] = ['account_id' => $cashAcct->id, 'debit' => $disposalAmount, 'credit' => Money::zero(), 'description' => 'Disposal proceeds'];
            }
            if (Money::gt($accumulated, Money::zero())) {
                $lines[] = ['account_id' => $accumAcct->id, 'debit' => $accumulated, 'credit' => Money::zero(), 'description' => 'Reverse accumulated depreciation'];
            }
            if (Money::lt($disposalAmount, $bookValue)) {
                $loss = Money::sub($bookValue, $disposalAmount);
                $lines[] = ['account_id' => $lossAcct->id, 'debit' => $loss, 'credit' => Money::zero(), 'description' => 'Loss on disposal'];
            }
            if (Money::gt($cost, Money::zero())) {
                $lines[] = ['account_id' => $assetAcct->id, 'debit' => Money::zero(), 'credit' => $cost, 'description' => 'Remove asset at cost'];
            }
            if (Money::gt($disposalAmount, $bookValue)) {
                $gain = Money::sub($disposalAmount, $bookValue);
                $lines[] = ['account_id' => $gainAcct->id, 'debit' => Money::zero(), 'credit' => $gain, 'description' => 'Gain on disposal'];
            }
            if ($lines === []) {
                // Reachable only for a zero-cost, zero-proceeds, never-depreciated
                // asset (StoreAssetRequest allows acquisition_cost 0). There is no
                // entry to post, and silently disposing without one would leave the
                // register and the ledger telling different stories.
                throw new BusinessRuleException('This asset has no cost, proceeds or accumulated depreciation to journalise; correct its acquisition cost before disposal.');
            }

            $je = $this->journals->create([
                'date'           => $disposedDate->toDateString(),
                'description'    => 'Disposal of asset '.$locked->asset_code.' — '.$locked->name.' — Reason: '.$reason,
                'reference_type' => Asset::class,
                'reference_id'   => $locked->id,
                'lines'          => $lines,
            ], $by);
            $this->journals->post($je, $by);

            $locked->forceFill([
                'status'          => AssetStatus::Disposed->value,
                'disposed_date'   => $disposedDate->toDateString(),
                'disposal_amount' => $disposalAmount,
                'disposal_reason' => $reason,
                // The proposal columns describe a live request; once executed
                // the canonical disposal_* columns are the record. Cleared in
                // the same save so one disposal = one audit row.
                'disposal_request_amount' => null,
                'disposal_request_date'   => null,
                'disposal_request_reason' => null,
                'disposal_requested_by'   => null,
            ])->save();

            return $locked->fresh();
        });
    }

    public function delete(Asset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            if ($locked->status !== AssetStatus::Active) {
                throw new BusinessRuleException('Only active assets without financial or custody history can be deleted.');
            }
            if (AssetDepreciation::query()->where('asset_id', $locked->getKey())->exists()) {
                throw new BusinessRuleException('Asset has depreciation history; dispose instead.');
            }
            if (AssetTransfer::query()->where('asset_id', $locked->getKey())->exists()) {
                throw new BusinessRuleException('Asset has custody history; it cannot be deleted.');
            }
            if ($this->approvals->nextStep($locked) !== null) {
                throw new BusinessRuleException('Asset has a pending disposal request; resolve it before deletion.');
            }

            $locked->delete();
        });
    }
}
