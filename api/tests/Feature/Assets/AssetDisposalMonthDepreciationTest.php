<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Assets\Services\DepreciationService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AS-01 — depreciation must run THROUGH the disposal month, posted by the
 * disposal itself.
 *
 * The two halves of the module used to encode contradictory accounting:
 * `AssetService::dispose()` derecognised the asset against the stored
 * accumulated balance with no catch-up, while the month-end run still treated
 * a mid-month-disposed asset as in service and posted one more full charge
 * AFTER the reversal. Expense was overstated by a monthly charge and the
 * accumulated-depreciation ledger carried a credit belonging to no asset.
 *
 * The fix depreciates through the disposal month inside dispose() (supplemental
 * journals per missing month, fenced by UNIQUE (asset_id, period_year,
 * period_month)) and excludes assets disposed on or before the period end from
 * the monthly run. The full-month convention is unchanged: an asset in service
 * any day of a month takes the full month.
 */
class AssetDisposalMonthDepreciationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ChartOfAccountsSeeder::class, RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function asset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'asset_code' => 'AST-DM-'.substr(uniqid(), -6),
            'name' => 'Disposal month fixture',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-15',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'accumulated_depreciation' => '0.00',
            'status' => AssetStatus::Active->value,
        ], $overrides));
    }

    private function depreciationService(): DepreciationService
    {
        return app(DepreciationService::class);
    }

    /** @return array<int, AssetDepreciation> */
    private function rowsFor(Asset $asset): array
    {
        return AssetDepreciation::query()
            ->where('asset_id', $asset->getKey())
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get()
            ->all();
    }

    private function disposalEntry(Asset $asset): JournalEntry
    {
        return JournalEntry::with('lines.account')
            ->where('reference_type', Asset::class)
            ->where('reference_id', $asset->getKey())
            ->sole();
    }

    /** @return array<string, string> signed net (debit - credit) per account code */
    private function netByCode(JournalEntry $je): array
    {
        $out = [];
        foreach ($je->lines as $line) {
            $code = (string) $line->account->code;
            $out[$code] = Money::add(
                $out[$code] ?? Money::zero(),
                Money::sub((string) $line->debit, (string) $line->credit),
            );
        }

        return $out;
    }

    private function accountCode(string $settingKey): string
    {
        return app(SettingsService::class)->requiredString($settingKey);
    }

    /** Signed net (debit - credit) across the whole ledger for one account. */
    private function ledgerNetFor(string $settingKey): string
    {
        $accountId = Account::where('code', $this->accountCode($settingKey))->firstOrFail()->getKey();
        $net = Money::zero();
        foreach (JournalEntryLine::query()->where('account_id', $accountId)->get() as $line) {
            $net = Money::add($net, Money::sub((string) $line->debit, (string) $line->credit));
        }

        return $net;
    }

    public function test_dispose_depreciates_through_the_disposal_month_before_derecognition(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        $by = $this->user();
        $asset = $this->asset(['acquisition_date' => '2025-06-01']);

        // Jun 2025 – Aug 2026 = 15 posted months at 200.00 = 3,000.00.
        $this->depreciationService()->runBackfillTo(2026, 8, $by);
        $this->assertSame('3000.00', $asset->fresh()->accumulated_depreciation);

        app(AssetService::class)->dispose($asset, [
            'disposal_amount' => '5000.00',
            'disposed_date' => '2026-09-15',
            'remarks' => 'Sold at auction',
        ], $by);

        // The catch-up posted exactly one September charge for this asset.
        $september = AssetDepreciation::query()
            ->where('asset_id', $asset->getKey())
            ->where('period_year', 2026)
            ->where('period_month', 9)
            ->get();
        $this->assertCount(1, $september);
        $this->assertSame('200.00', (string) $september->first()->depreciation_amount);
        $this->assertNotNull($september->first()->journal_entry_id);
        $this->assertStringContainsString(
            'catch-up',
            JournalEntry::find($september->first()->journal_entry_id)->description,
        );

        // The disposal JE reverses the caught-up 3,200, derecognises the
        // 12,000 cost and books the 3,800 loss against 5,000 of proceeds.
        $je = $this->disposalEntry($asset);
        $net = $this->netByCode($je);
        $this->assertSame('5000.00', $net[$this->accountCode('accounting.accounts.asset_cash_code')]);
        $this->assertSame('3200.00', $net[$this->accountCode('accounting.accounts.asset_accumulated_depreciation_code')]);
        $this->assertSame('3800.00', $net[$this->accountCode('accounting.accounts.asset_disposal_loss_code')]);
        $this->assertSame('-12000.00', $net[$this->accountCode('accounting.accounts.asset_cost_code')]);

        // Register and ledger agree: the asset's accumulated balance is the
        // reversed balance, and the accumulated account nets to zero.
        $fresh = $asset->fresh();
        $this->assertSame('3200.00', (string) $fresh->accumulated_depreciation);
        $this->assertSame(AssetStatus::Disposed, $fresh->status);
        $this->assertSame(Money::zero(), $this->ledgerNetFor('accounting.accounts.asset_accumulated_depreciation_code'));

        // The September run skips the disposed asset — the catch-up charge is
        // the only September depreciation for it — and October posts nothing.
        $septemberRun = $this->depreciationService()->runForMonth(2026, 9, $by);
        $this->assertSame(0, $septemberRun['posted_count']);
        $this->assertNull($septemberRun['journal_entry_id']);
        $this->assertCount(1, AssetDepreciation::query()
            ->where('asset_id', $asset->getKey())
            ->where('period_year', 2026)
            ->where('period_month', 9)
            ->get(), 'The September run must not add a second charge.');

        $octoberRun = $this->depreciationService()->runForMonth(2026, 10, $by);
        $this->assertSame(0, $octoberRun['posted_count']);
        $this->assertNull($octoberRun['journal_entry_id']);
        $this->assertCount(16, $this->rowsFor($asset), 'Fifteen run months plus the September catch-up, nothing more.');
    }

    public function test_dispose_catches_up_every_unrun_month_since_the_last_run(): void
    {
        Carbon::setTestNow('2026-07-05 09:00:00');
        $by = $this->user();
        $asset = $this->asset(['acquisition_date' => '2026-01-10']);

        $this->depreciationService()->runBackfillTo(2026, 3, $by);
        $this->assertSame('600.00', $asset->fresh()->accumulated_depreciation);

        app(AssetService::class)->dispose($asset, [
            'disposal_amount' => '0.00',
            'disposed_date' => '2026-06-10',
            'remarks' => 'Scrapped — beyond economical repair',
        ], $by);

        // April, May and June were caught up inside the disposal.
        $rows = $this->rowsFor($asset);
        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertSame('200.00', (string) $row->depreciation_amount);
            $this->assertNotNull($row->journal_entry_id);
        }
        $catchupJournals = JournalEntry::query()->where('description', 'like', '%(disposal catch-up)%')->count();
        $this->assertSame(3, $catchupJournals);
        $this->assertSame('1200.00', (string) $asset->fresh()->accumulated_depreciation);

        // The disposal JE reverses the caught-up balance, not the stale one.
        $net = $this->netByCode($this->disposalEntry($asset));
        $this->assertSame('1200.00', $net[$this->accountCode('accounting.accounts.asset_accumulated_depreciation_code')]);
        $this->assertSame('10800.00', $net[$this->accountCode('accounting.accounts.asset_disposal_loss_code')]);
        $this->assertSame('-12000.00', $net[$this->accountCode('accounting.accounts.asset_cost_code')]);

        // Later runs skip the asset entirely — no second charge for any month.
        $june = $this->depreciationService()->runForMonth(2026, 6, $by);
        $this->assertSame(0, $june['posted_count']);
        $april = $this->depreciationService()->runForMonth(2026, 4, $by);
        $this->assertSame(0, $april['posted_count']);
        $this->assertCount(6, $this->rowsFor($asset));
        $this->assertSame('1200.00', (string) $asset->fresh()->accumulated_depreciation);
    }

    public function test_dispose_after_an_already_posted_disposal_month_does_not_double_post(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $by = $this->user();
        $asset = $this->asset(['acquisition_date' => '2026-01-15']);

        // The September run is already posted when the asset is disposed on
        // the month's last day — the UNIQUE fence must reload, not repost.
        $this->depreciationService()->runBackfillTo(2026, 9, $by);
        $this->assertSame('1800.00', $asset->fresh()->accumulated_depreciation);
        $journalsBefore = JournalEntry::query()->count();

        app(AssetService::class)->dispose($asset, [
            'disposal_amount' => '7000.00',
            'disposed_date' => '2026-09-30',
            'remarks' => 'Sold to a second-hand dealer',
        ], $by);

        $this->assertCount(9, $this->rowsFor($asset));
        $this->assertSame(0, JournalEntry::query()->where('description', 'like', '%(disposal catch-up)%')->count());
        $this->assertSame($journalsBefore + 1, JournalEntry::query()->count(), 'Only the disposal JE may be added.');

        // The disposal JE uses the already-inclusive accumulated balance.
        $net = $this->netByCode($this->disposalEntry($asset));
        $this->assertSame('7000.00', $net[$this->accountCode('accounting.accounts.asset_cash_code')]);
        $this->assertSame('1800.00', $net[$this->accountCode('accounting.accounts.asset_accumulated_depreciation_code')]);
        $this->assertSame('3200.00', $net[$this->accountCode('accounting.accounts.asset_disposal_loss_code')]);
        $this->assertSame('-12000.00', $net[$this->accountCode('accounting.accounts.asset_cost_code')]);
        $this->assertSame('1800.00', (string) $asset->fresh()->accumulated_depreciation);
    }

    public function test_monthly_run_posts_for_remaining_assets_alongside_catchup_rows(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $by = $this->user();
        $disposed = $this->asset(['acquisition_date' => '2026-01-05']);
        $kept = $this->asset(['acquisition_date' => '2026-01-05', 'acquisition_cost' => '24000.00']);

        $this->depreciationService()->runBackfillTo(2026, 8, $by);
        $this->assertSame('1600.00', $disposed->fresh()->accumulated_depreciation);
        $this->assertSame('3200.00', $kept->fresh()->accumulated_depreciation);

        app(AssetService::class)->dispose($disposed, [
            'disposal_amount' => '0.00',
            'disposed_date' => '2026-09-15',
            'remarks' => 'Written off',
        ], $by);

        // The September run must post for the remaining asset while a
        // catch-up row for the disposed asset already occupies the month.
        $run = $this->depreciationService()->runForMonth(2026, 9, $by);
        $this->assertSame(1, $run['posted_count']);
        $this->assertSame('400.00', $run['total_amount']);
        $this->assertNotNull($run['journal_entry_id']);
        $this->assertSame('3600.00', (string) $kept->fresh()->accumulated_depreciation);

        $september = AssetDepreciation::query()->where('period_year', 2026)->where('period_month', 9)->get();
        $this->assertCount(2, $september);
        $this->assertNotSame(
            $september->firstWhere('asset_id', $disposed->getKey())->journal_entry_id,
            $september->firstWhere('asset_id', $kept->getKey())->journal_entry_id,
        );

        // An idempotent rerun reconciles against its own journal and leaves
        // the catch-up row untouched.
        $rerun = $this->depreciationService()->runForMonth(2026, 9, $by);
        $this->assertSame($run['journal_entry_id'], $rerun['journal_entry_id']);
        $this->assertSame(1, $rerun['posted_count']);
        $this->assertCount(2, AssetDepreciation::query()->where('period_year', 2026)->where('period_month', 9)->get());
        $this->assertSame('3600.00', (string) $kept->fresh()->accumulated_depreciation);
    }
}
