<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Sprint 8 — Task 70. */
class AssetService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly JournalEntryService $journals,
        private readonly SettingsService $settings,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Asset::query()->with('department:id,name,code');

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
        return $asset->load(['department:id,name,code', 'depreciations']);
    }

    public function create(array $data): Asset
    {
        return DB::transaction(function () use ($data) {
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
                'salvage_value'     => $data['salvage_value'] ?? null,
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
            $locked->fill(array_intersect_key($data, array_flip([
                'name', 'description', 'department_id', 'location',
                'useful_life_years', 'salvage_value',
            ])));
            $locked->save();
            return $locked->fresh();
        });
    }

    /**
     * Dispose an asset.
     *
     * Posts a JE that:
     *   DR Cash on Hand (disposal_amount)
     *   DR Accumulated Depreciation (asset.accumulated_depreciation)
     *   DR Loss on Disposal      (if cost - accum > disposal_amount)
     *   CR Property Plant & Equipment (asset.acquisition_cost)
     *   CR Gain on Disposal      (if disposal_amount > book_value)
     */
    public function dispose(Asset $asset, array $data, User $by): Asset
    {
        if ($asset->status === AssetStatus::Disposed) {
            throw new BusinessRuleException('Asset already disposed.');
        }
        return DB::transaction(function () use ($asset, $data, $by) {
            // Lock-then-guard: re-read so a concurrent dispose cannot post a
            // second disposal JE from a stale snapshot.
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            if ($locked->status === AssetStatus::Disposed) {
                throw new BusinessRuleException('Asset already disposed.');
            }

            $disposalAmount = (float) ($data['disposal_amount'] ?? 0);
            $cost           = (float) $locked->acquisition_cost;
            $accum          = (float) $locked->accumulated_depreciation;
            $bookValue      = max(0.0, $cost - $accum);

            $cashAcct  = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_cash_code'))->firstOrFail();
            $accumAcct = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_accumulated_depreciation_code'))->firstOrFail();
            $assetAcct = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_cost_code'))->firstOrFail();
            $lossAcct  = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_disposal_loss_code'))->firstOrFail();
            $gainAcct  = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_disposal_gain_code'))->firstOrFail();

            $lines = [
                ['account_id' => $cashAcct->id,  'debit' => number_format($disposalAmount, 2, '.', ''), 'credit' => '0.00', 'description' => 'Disposal proceeds'],
                ['account_id' => $accumAcct->id, 'debit' => number_format($accum, 2, '.', ''),          'credit' => '0.00', 'description' => 'Reverse accumulated depreciation'],
            ];
            if ($disposalAmount < $bookValue) {
                $loss = $bookValue - $disposalAmount;
                $lines[] = ['account_id' => $lossAcct->id, 'debit' => number_format($loss, 2, '.', ''), 'credit' => '0.00', 'description' => 'Loss on disposal'];
            }
            $lines[] = ['account_id' => $assetAcct->id, 'debit' => '0.00', 'credit' => number_format($cost, 2, '.', ''), 'description' => 'Remove asset at cost'];
            if ($disposalAmount > $bookValue) {
                $gain = $disposalAmount - $bookValue;
                $lines[] = ['account_id' => $gainAcct->id, 'debit' => '0.00', 'credit' => number_format($gain, 2, '.', ''), 'description' => 'Gain on disposal'];
            }

            $je = $this->journals->create([
                'date'           => $data['disposed_date'] ?? now()->toDateString(),
                'description'    => 'Disposal of asset '.$locked->asset_code.' — '.$locked->name,
                'reference_type' => Asset::class,
                'reference_id'   => $locked->id,
                'lines'          => $lines,
            ], $by);
            $this->journals->post($je, $by);

            $locked->forceFill([
                'status'          => AssetStatus::Disposed->value,
                'disposed_date'   => $data['disposed_date'] ?? now()->toDateString(),
                'disposal_amount' => $disposalAmount,
            ])->save();

            return $locked->fresh();
        });
    }

    public function delete(Asset $asset): void
    {
        if ($asset->status !== AssetStatus::Active) {
            throw new BusinessRuleException('Only active assets that have not been depreciated can be deleted.');
        }
        if ($asset->depreciations()->exists()) {
            throw new BusinessRuleException('Asset has depreciation history; dispose instead.');
        }
        $asset->delete();
    }
}
