<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Models\AssetDepreciationRun;
use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly fixed-asset depreciation runner.
 *
 * Each asset row is rounded once to centavos, and the consolidated journal is
 * built from those exact same rows. Period execution is serialized by the
 * locked asset set and recorded in asset_depreciation_runs so a retry cannot
 * create a second journal for an already-posted period.
 */
class DepreciationService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{posted_count:int, total_amount:string, journal_entry_id:?int}
     */
    public function runForMonth(int $year, int $month, User $by, bool $allowBackfill = false): array
    {
        $this->assertSupportedPeriod($year, $month);

        return DB::transaction(function () use ($year, $month, $by, $allowBackfill): array {
            $periodStart = CarbonImmutable::create($year, $month, 1)->startOfMonth();
            $periodEnd = $periodStart->endOfMonth();
            $assets = $this->assetsInServiceFor($periodStart, $periodEnd);

            if (! $allowBackfill) {
                $this->assertPriorPeriodsComplete($assets, $periodStart);
            }

            $run = $this->lockOrCreateRun($year, $month);
            $existing = AssetDepreciation::query()
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->lockForUpdate()
                ->get();

            if ($run->journal_entry_id !== null) {
                $this->assertRunRowsMatch($existing, $run);

                return [
                    'posted_count' => (int) $run->posted_count,
                    'total_amount' => (string) $run->total_amount,
                    'journal_entry_id' => (int) $run->journal_entry_id,
                ];
            }

            if ($existing->isNotEmpty()) {
                return $this->reconcileLegacyPeriod($existing, $assets, $run);
            }

            $rows = [];
            $totalAmount = Money::zero();

            foreach ($assets as $asset) {
                $row = $this->calculateRow($asset);
                if ($row === null) {
                    continue;
                }

                $rows[] = $row;
                $totalAmount = Money::add($totalAmount, $row['amount']);
            }

            if ($rows === []) {
                // A zero-value period has no journal identity to reconcile.
                // Do not retain an empty run marker that could mask a later
                // asset acquisition/backfill for the same period.
                $run->delete();

                return ['posted_count' => 0, 'total_amount' => Money::zero(), 'journal_entry_id' => null];
            }

            $depExp = Account::where('code', $this->settings->requiredString('accounting.accounts.depreciation_expense_code'))->firstOrFail();
            $accDep = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_accumulated_depreciation_code'))->firstOrFail();
            $periodLabel = sprintf('%04d-%02d', $year, $month);
            $lines = [
                ['account_id' => $depExp->id, 'debit' => $totalAmount, 'credit' => Money::zero(), 'description' => 'Monthly depreciation'],
                ['account_id' => $accDep->id, 'debit' => Money::zero(), 'credit' => $totalAmount, 'description' => 'Monthly depreciation'],
            ];

            $je = $this->journals->create([
                'date' => $periodEnd->toDateString(),
                'description' => 'Asset depreciation — '.$periodLabel,
                'reference_type' => 'asset_depreciation',
                'reference_id' => null,
                'lines' => $lines,
            ], $by);
            $this->journals->post($je, $by);

            foreach ($rows as $row) {
                /** @var Asset $asset */
                $asset = $row['asset'];
                AssetDepreciation::create([
                    'asset_id' => $asset->id,
                    'period_year' => $year,
                    'period_month' => $month,
                    'depreciation_amount' => $row['amount'],
                    'accumulated_after' => $row['accumulated_after'],
                    'journal_entry_id' => $je->id,
                    'created_at' => now(),
                ]);
                $asset->forceFill(['accumulated_depreciation' => $row['accumulated_after']])->save();
            }

            $run->forceFill([
                'journal_entry_id' => $je->id,
                'posted_count' => count($rows),
                'total_amount' => $totalAmount,
            ])->save();

            return [
                'posted_count' => count($rows),
                'total_amount' => $totalAmount,
                'journal_entry_id' => $je->id,
            ];
        });
    }

    /**
     * Explicit rebuild path. It walks from the earliest asset acquisition
     * month to the requested completed period, making out-of-order work
     * deliberate and chronological instead of silently using a current
     * accumulated balance for an arbitrary historical month.
     *
     * @return array{posted_count:int, total_amount:string, journal_entry_id:?int, processed_periods:int}
     */
    public function runBackfillTo(int $year, int $month, User $by): array
    {
        $this->assertSupportedPeriod($year, $month);

        $periodEnd = CarbonImmutable::create($year, $month, 1)->endOfMonth();
        $earliestAcquisition = Asset::query()
            ->whereDate('acquisition_date', '<=', $periodEnd->toDateString())
            ->min('acquisition_date');

        if ($earliestAcquisition === null) {
            return [
                'posted_count' => 0,
                'total_amount' => Money::zero(),
                'journal_entry_id' => null,
                'processed_periods' => 0,
            ];
        }

        $cursor = CarbonImmutable::parse((string) $earliestAcquisition)->startOfMonth();
        $target = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $last = ['posted_count' => 0, 'total_amount' => Money::zero(), 'journal_entry_id' => null];
        $processed = 0;

        while ($cursor->lte($target)) {
            $last = $this->runForMonth($cursor->year, $cursor->month, $by, true);
            $processed++;
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $last + ['processed_periods' => $processed];
    }

    /**
     * @return Collection<int, Asset>
     */
    private function assetsInServiceFor(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Collection
    {
        return Asset::query()
            ->whereDate('acquisition_date', '<=', $periodEnd->toDateString())
            ->where('status', '!=', AssetStatus::Disposed->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param Collection<int, Asset> $assets
     */
    private function assertPriorPeriodsComplete(Collection $assets, CarbonImmutable $periodStart): void
    {
        $previousPeriod = $periodStart->subMonthNoOverflow()->startOfMonth();

        foreach ($assets as $asset) {
            $depreciable = Money::clampMin(
                Money::sub((string) $asset->acquisition_cost, (string) $asset->salvage_value),
                Money::zero(),
            );
            if (! Money::gt($depreciable, Money::zero())
                || ! Money::lt((string) $asset->accumulated_depreciation, $depreciable)) {
                continue;
            }

            $cursor = CarbonImmutable::parse($asset->acquisition_date->toDateString())->startOfMonth();
            while ($cursor->lte($previousPeriod)) {
                if ($this->assetWasInServiceFor($asset, $cursor)) {
                    $complete = AssetDepreciation::query()
                        ->where('asset_id', $asset->getKey())
                        ->where('period_year', $cursor->year)
                        ->where('period_month', $cursor->month)
                        ->whereNotNull('journal_entry_id')
                        ->exists();
                    if (! $complete) {
                        throw new BusinessRuleException(sprintf(
                            'Depreciation period %04d-%02d is missing for asset %s; run the explicit backfill workflow first.',
                            $cursor->year,
                            $cursor->month,
                            $asset->asset_code,
                        ));
                    }
                }
                $cursor = $cursor->addMonthNoOverflow();
            }
        }
    }

    /**
     * @return array{asset:Asset, amount:string, accumulated_after:string}|null
     */
    private function calculateRow(Asset $asset): ?array
    {
        $monthly = (string) $asset->monthly_depreciation;
        $depreciable = Money::clampMin(
            Money::sub((string) $asset->acquisition_cost, (string) $asset->salvage_value),
            Money::zero(),
        );
        $alreadyAccumulated = Money::round2((string) $asset->accumulated_depreciation);
        $remaining = Money::clampMin(Money::sub($depreciable, $alreadyAccumulated), Money::zero());

        if (! Money::gt($monthly, Money::zero()) || ! Money::gt($remaining, Money::zero())) {
            return null;
        }

        $amount = Money::lt($monthly, $remaining) ? Money::round2($monthly) : $remaining;

        return [
            'asset' => $asset,
            'amount' => $amount,
            'accumulated_after' => Money::add($alreadyAccumulated, $amount),
        ];
    }

    private function assetWasInServiceFor(Asset $asset, CarbonImmutable $periodStart): bool
    {
        $periodEnd = $periodStart->endOfMonth();
        if ($asset->acquisition_date->gt($periodEnd)) {
            return false;
        }

        return $asset->status !== AssetStatus::Disposed
            || $asset->disposed_date === null
            || $asset->disposed_date->gte($periodStart);
    }

    private function lockOrCreateRun(int $year, int $month): AssetDepreciationRun
    {
        AssetDepreciationRun::query()->insertOrIgnore([
            'period_year' => $year,
            'period_month' => $month,
            'posted_count' => 0,
            'total_amount' => Money::zero(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AssetDepreciationRun::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param Collection<int, AssetDepreciation> $existing
     * @param Collection<int, Asset> $assets
     * @return array{posted_count:int, total_amount:string, journal_entry_id:?int}
     */
    private function reconcileLegacyPeriod(Collection $existing, Collection $assets, AssetDepreciationRun $run): array
    {
        $journalIds = $existing->pluck('journal_entry_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        if ($existing->contains(fn (AssetDepreciation $row): bool => $row->journal_entry_id === null) || $journalIds->count() !== 1) {
            throw new BusinessRuleException('Depreciation period has incomplete journal identity; repair it before rerunning.');
        }

        $journalId = (int) $journalIds->first();
        if ($run->journal_entry_id !== null && (int) $run->journal_entry_id !== $journalId) {
            throw new BusinessRuleException('Depreciation period journal identity does not reconcile with its asset rows.');
        }

        $existingAssetIds = $existing->pluck('asset_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($assets as $asset) {
            if (in_array((int) $asset->getKey(), $existingAssetIds, true)) {
                continue;
            }
            if ($this->calculateRow($asset) !== null) {
                throw new BusinessRuleException('Depreciation period has partial asset rows; repair it before rerunning.');
            }
        }

        $total = Money::zero();
        foreach ($existing as $row) {
            $total = Money::add($total, (string) $row->depreciation_amount);
        }
        $run->forceFill([
            'journal_entry_id' => $journalId,
            'posted_count' => $existing->count(),
            'total_amount' => $total,
        ])->save();

        return [
            'posted_count' => $existing->count(),
            'total_amount' => $total,
            'journal_entry_id' => $journalId,
        ];
    }

    /**
     * @param Collection<int, AssetDepreciation> $rows
     */
    private function assertRunRowsMatch(Collection $rows, AssetDepreciationRun $run): void
    {
        if ($rows->isEmpty() || $rows->contains(fn (AssetDepreciation $row): bool => (int) $row->journal_entry_id !== (int) $run->journal_entry_id)) {
            throw new BusinessRuleException('Depreciation run identity has no matching asset rows.');
        }

        $total = Money::zero();
        foreach ($rows as $row) {
            $total = Money::add($total, (string) $row->depreciation_amount);
        }
        if ($rows->count() !== (int) $run->posted_count
            || Money::cmp($total, (string) $run->total_amount) !== 0) {
            throw new BusinessRuleException('Depreciation run summary does not reconcile with its asset rows.');
        }
    }

    private function assertSupportedPeriod(int $year, int $month): void
    {
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new BusinessRuleException('Depreciation period must use a year from 2020 to 2100 and a month from 1 to 12.');
        }

        $target = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        if ($target->gte(CarbonImmutable::now()->startOfMonth())) {
            throw new BusinessRuleException('Depreciation can only be posted for a completed period.');
        }
    }
}
