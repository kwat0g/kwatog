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
 *
 * Assets are depreciated THROUGH their disposal month: `AssetService::dispose()`
 * calls `catchUpThrough()` to post any missing months (including the disposal
 * month itself) before the derecognition journal is built, and the monthly run
 * excludes assets disposed on or before the period end so the disposal-month
 * charge is never posted twice.
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
            $assets = $this->assetsInServiceFor($periodEnd);

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

            $existingByAsset = $existing->keyBy(fn (AssetDepreciation $row): int => (int) $row->asset_id);

            $rows = [];
            $totalAmount = Money::zero();

            foreach ($assets as $asset) {
                $posted = $existingByAsset->get((int) $asset->getKey());
                if ($posted !== null) {
                    if ($posted->journal_entry_id === null) {
                        throw new BusinessRuleException('Depreciation period has incomplete journal identity; repair it before rerunning.');
                    }
                    // This asset's month is already posted — by an earlier
                    // consolidated run (legacy data without a run marker) or
                    // by a disposal catch-up journal.
                    continue;
                }

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
     * Depreciate one asset through $throughMonth (inclusive) so a disposal can
     * derecognise a fully caught-up accumulated balance. Called by
     * AssetService::dispose() inside its own transaction, under the asset row
     * lock, so every supplemental journal commits or rolls back with the
     * disposal journal. The UNIQUE (asset_id, period_year, period_month)
     * constraint is the idempotency fence: a month that already has a posted
     * row is reloaded, never posted twice.
     */
    public function catchUpThrough(Asset $asset, CarbonImmutable $throughMonth, User $by): void
    {
        $cursor = CarbonImmutable::parse($asset->acquisition_date->toDateString())->startOfMonth();
        $target = $throughMonth->startOfMonth();

        while ($cursor->lte($target)) {
            $this->postMissingMonthForAsset($asset, $cursor, $by);
            $cursor = $cursor->addMonthNoOverflow();
        }
    }

    /**
     * @return Collection<int, Asset>
     */
    private function assetsInServiceFor(CarbonImmutable $periodEnd): Collection
    {
        return Asset::query()
            ->whereDate('acquisition_date', '<=', $periodEnd->toDateString())
            ->where(function ($query) use ($periodEnd): void {
                $query
                    ->where('status', '!=', AssetStatus::Disposed->value)
                    ->orWhere(function ($disposed) use ($periodEnd): void {
                        // Disposal-month depreciation is posted by dispose()'s
                        // catch-up, so the monthly run skips assets disposed on
                        // or before the period end. Assets disposed after the
                        // period were in service for all of it (backfill).
                        $disposed
                            ->where('status', AssetStatus::Disposed->value)
                            ->whereDate('disposed_date', '>', $periodEnd->toDateString());
                    });
            })
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

    private function postMissingMonthForAsset(Asset $asset, CarbonImmutable $periodStart, User $by): void
    {
        $year = $periodStart->year;
        $month = $periodStart->month;

        $row = $this->calculateRow($asset);

        $existing = AssetDepreciation::query()
            ->where('asset_id', $asset->getKey())
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->journal_entry_id === null && $row !== null) {
                throw new BusinessRuleException(sprintf(
                    'Depreciation period %04d-%02d for asset %s has incomplete journal identity; repair it before disposing.',
                    $year,
                    $month,
                    $asset->asset_code,
                ));
            }
            if ($row !== null && Money::cmp((string) $asset->accumulated_depreciation, (string) $existing->accumulated_after) !== 0) {
                $asset->forceFill(['accumulated_depreciation' => $existing->accumulated_after])->save();
            }

            return;
        }

        if ($row === null) {
            return;
        }

        // Claim the ledger row before the journal: if a concurrent writer
        // already owns the month, the UNIQUE fence rejects the claim and the
        // winner's row is reloaded instead — no second journal is posted.
        $inserted = AssetDepreciation::query()->insertOrIgnore([
            'asset_id' => $asset->getKey(),
            'period_year' => $year,
            'period_month' => $month,
            'depreciation_amount' => $row['amount'],
            'accumulated_after' => $row['accumulated_after'],
            'journal_entry_id' => null,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            $winner = AssetDepreciation::query()
                ->where('asset_id', $asset->getKey())
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->firstOrFail();
            if ($winner->journal_entry_id === null) {
                throw new BusinessRuleException(sprintf(
                    'Depreciation period %04d-%02d for asset %s has incomplete journal identity; repair it before disposing.',
                    $year,
                    $month,
                    $asset->asset_code,
                ));
            }
            $asset->forceFill(['accumulated_depreciation' => $winner->accumulated_after])->save();

            return;
        }

        $depExp = Account::where('code', $this->settings->requiredString('accounting.accounts.depreciation_expense_code'))->firstOrFail();
        $accDep = Account::where('code', $this->settings->requiredString('accounting.accounts.asset_accumulated_depreciation_code'))->firstOrFail();
        $periodLabel = sprintf('%04d-%02d', $year, $month);
        $je = $this->journals->create([
            'date' => $periodStart->endOfMonth()->toDateString(),
            'description' => 'Asset depreciation — '.$periodLabel.' (disposal catch-up)',
            'reference_type' => 'asset_depreciation',
            'reference_id' => null,
            'lines' => [
                ['account_id' => $depExp->id, 'debit' => $row['amount'], 'credit' => Money::zero(), 'description' => 'Monthly depreciation'],
                ['account_id' => $accDep->id, 'debit' => Money::zero(), 'credit' => $row['amount'], 'description' => 'Monthly depreciation'],
            ],
        ], $by);
        $this->journals->post($je, $by);

        AssetDepreciation::query()
            ->where('asset_id', $asset->getKey())
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->update(['journal_entry_id' => $je->id]);

        $asset->forceFill(['accumulated_depreciation' => $row['accumulated_after']])->save();
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
     * The run's own journal is the reconciliation anchor: rows posted by a
     * disposal catch-up carry a different journal and are legitimate
     * neighbours of the run's rows for the same period, not corruption.
     *
     * @param Collection<int, AssetDepreciation> $rows
     */
    private function assertRunRowsMatch(Collection $rows, AssetDepreciationRun $run): void
    {
        $posted = $rows->filter(fn (AssetDepreciation $row): bool => (int) $row->journal_entry_id === (int) $run->journal_entry_id);

        if ($posted->isEmpty()) {
            throw new BusinessRuleException('Depreciation run identity has no matching asset rows.');
        }

        $total = Money::zero();
        foreach ($posted as $row) {
            $total = Money::add($total, (string) $row->depreciation_amount);
        }
        if ($posted->count() !== (int) $run->posted_count
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
