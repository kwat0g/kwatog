<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\DepreciationMethod;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
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
 * AS-02 — a backdated acquisition must not brick depreciation.
 *
 * `StoreAssetRequest` legitimately allows historical acquisition dates, but a
 * January-acquired asset entered after January was already posted could never
 * catch up: the plain run refused on the completeness guard forever, and
 * `runBackfillTo()` walked the posted months while each one early-returned on
 * the run's journal — reporting success having inserted nothing. One retro row
 * therefore trapped the monthly cron (which never backfills) for ALL assets,
 * with manual DB repair as the only exit.
 *
 * The repair arm posts one supplemental journal per posted month covering only
 * the missing asset-months, so the original consolidated entry is never edited
 * and later plain runs go through for every asset.
 */
class AssetDepreciationBackfillRepairTest extends TestCase
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
            'asset_code' => 'AST-BF-'.substr(uniqid(), -6),
            'name' => 'Backfill repair fixture',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-05',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'accumulated_depreciation' => '0.00',
            'status' => AssetStatus::Active->value,
        ], $overrides));
    }

    private function service(): DepreciationService
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

    public function test_backfill_repairs_a_backdated_acquisition_in_already_posted_months(): void
    {
        $by = $this->user();
        $original = $this->asset(['name' => 'Original January asset']);

        Carbon::setTestNow('2026-03-05 09:00:00');
        $this->service()->runForMonth(2026, 1, $by);
        $this->service()->runForMonth(2026, 2, $by);
        $originalJanJournal = AssetDepreciation::query()
            ->where('asset_id', $original->getKey())
            ->where('period_month', 1)
            ->firstOrFail()->journal_entry_id;

        // The backdated asset lands in March with a January acquisition date.
        $retro = $this->asset(['name' => 'Backdated asset', 'acquisition_date' => '2026-01-20']);

        Carbon::setTestNow('2026-04-05 09:00:00');
        try {
            $this->service()->runForMonth(2026, 3, $by);
            $this->fail('The March plain run must refuse while the retro asset owes January.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('2026-01', $e->getMessage());
            $this->assertStringContainsString('backfill', $e->getMessage());
        }

        $result = $this->service()->runBackfillTo(2026, 3, $by);

        $this->assertSame(3, $result['processed_periods']);

        // January and February were repaired with supplemental journals; March
        // itself was unposted and takes one consolidated run.
        $retroRows = $this->rowsFor($retro);
        $this->assertCount(3, $retroRows);
        $this->assertSame('200.00', (string) $retroRows[0]->depreciation_amount);
        $this->assertSame('200.00', (string) $retroRows[1]->depreciation_amount);
        $this->assertSame('200.00', (string) $retroRows[2]->depreciation_amount);
        $this->assertSame('600.00', (string) $retro->fresh()->accumulated_depreciation);

        $this->assertNotSame($originalJanJournal, $retroRows[0]->journal_entry_id);
        $this->assertSame($originalJanJournal, AssetDepreciation::query()
            ->where('asset_id', $original->getKey())
            ->where('period_month', 1)
            ->firstOrFail()->journal_entry_id, 'The original January journal must be untouched.');
        $this->assertSame(5, JournalEntry::query()->count(), 'Two original, two supplemental, one March run.');
        $this->assertSame(2, JournalEntry::query()
            ->where('description', 'like', '%(supplemental backfill)%')->count());

        // The trapping state is gone: April's plain run succeeds for ALL assets.
        Carbon::setTestNow('2026-05-03 09:00:00');
        $april = $this->service()->runForMonth(2026, 4, $by);

        $this->assertSame(2, $april['posted_count']);
        $this->assertSame('400.00', $april['total_amount']);
        $this->assertSame('800.00', (string) $original->fresh()->accumulated_depreciation);
        $this->assertSame('800.00', (string) $retro->fresh()->accumulated_depreciation);
    }

    public function test_backfill_on_a_healthy_timeline_is_a_no_op(): void
    {
        Carbon::setTestNow('2026-04-05 09:00:00');
        $by = $this->user();
        $asset = $this->asset();

        $this->service()->runBackfillTo(2026, 3, $by);
        $this->assertDatabaseCount('asset_depreciations', 3);
        $this->assertDatabaseCount('journal_entries', 3);

        $this->service()->runBackfillTo(2026, 3, $by);
        $this->assertDatabaseCount('asset_depreciations', 3);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertSame('600.00', (string) $asset->fresh()->accumulated_depreciation);

        // A plain rerun of an already-posted month stays idempotent.
        $rerun = $this->service()->runForMonth(2026, 1, $by);
        $this->assertSame(1, $rerun['posted_count']);
        $this->assertSame('200.00', $rerun['total_amount']);
        $this->assertDatabaseCount('asset_depreciations', 3);
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_declining_balance_tail_rounding_to_zero_no_longer_bricks_later_runs(): void
    {
        Carbon::setTestNow('2026-04-05 09:00:00');
        $by = $this->user();
        $healthy = $this->asset();

        // 200% declining balance on a 12,000 base: monthly = book × 2/5/12.
        // With 11,999.90 already booked the charge rounds to 0.00 while 0.10
        // of depreciable base remains — calculateRow can never produce a row,
        // but the guard still demands one every month.
        $tail = $this->asset([
            'depreciation_method' => DepreciationMethod::DecliningBalance->value,
            'accumulated_depreciation' => '11999.90',
        ]);

        $january = $this->service()->runForMonth(2026, 1, $by);
        $this->assertSame(2, $january['posted_count']);
        $this->assertSame('200.00', $january['total_amount']);

        $tailRow = AssetDepreciation::query()
            ->where('asset_id', $tail->getKey())
            ->where('period_month', 1)
            ->sole();
        $this->assertSame('0.00', (string) $tailRow->depreciation_amount);
        $this->assertSame('11999.90', (string) $tailRow->accumulated_after);
        $this->assertNotNull($tailRow->journal_entry_id, 'A zero row rides the period journal when one exists.');

        $february = $this->service()->runForMonth(2026, 2, $by);
        $this->assertSame(2, $february['posted_count']);
        $this->assertSame('11999.90', (string) $tail->fresh()->accumulated_depreciation);
        $this->assertCount(2, $this->rowsFor($tail));
    }

    public function test_zero_rounding_asset_alone_posts_a_journalless_zero_row(): void
    {
        Carbon::setTestNow('2026-04-05 09:00:00');
        $by = $this->user();
        $tail = $this->asset([
            'depreciation_method' => DepreciationMethod::DecliningBalance->value,
            'accumulated_depreciation' => '11999.90',
        ]);

        $january = $this->service()->runForMonth(2026, 1, $by);
        $this->assertSame(1, $january['posted_count']);
        $this->assertSame('0.00', $january['total_amount']);
        $this->assertNull($january['journal_entry_id']);

        $row = AssetDepreciation::query()
            ->where('asset_id', $tail->getKey())
            ->where('period_month', 1)
            ->sole();
        $this->assertSame('0.00', (string) $row->depreciation_amount);
        $this->assertNull($row->journal_entry_id, 'A zero-value period keeps no journal identity.');

        $february = $this->service()->runForMonth(2026, 2, $by);
        $this->assertSame(1, $february['posted_count']);
        $this->assertSame('11999.90', (string) $tail->fresh()->accumulated_depreciation);
    }

    public function test_closed_accounting_period_still_refuses_the_supplemental_loudly(): void
    {
        Carbon::setTestNow('2026-04-05 09:00:00');
        $by = $this->user();
        $original = $this->asset();

        $this->service()->runForMonth(2026, 1, $by);
        app(AccountingPeriodService::class)->close(2026, 1, $by);

        $retro = $this->asset(['name' => 'Backdated after close', 'acquisition_date' => '2026-01-20']);

        try {
            $this->service()->runBackfillTo(2026, 1, $by);
            $this->fail('Repairing a closed period must refuse instead of backfilling silently.');
        } catch (ClosedPeriodException $e) {
            $this->assertStringContainsString('closed', $e->getMessage());
        }

        $this->assertSame(0, AssetDepreciation::query()->where('asset_id', $retro->getKey())->count());
        $this->assertSame('0.00', (string) $retro->fresh()->accumulated_depreciation);
    }

    public function test_run_month_endpoint_routes_the_backfill_flag(): void
    {
        Carbon::setTestNow('2026-04-05 09:00:00');
        $by = $this->user();
        $asset = $this->asset(['acquisition_date' => '2026-01-20']);

        $response = $this->actingAs($by)->postJson('/api/v1/asset-depreciations/run', [
            'year' => 2026,
            'month' => 3,
            'backfill' => true,
        ]);

        $response->assertOk();
        $this->assertSame(3, $response->json('data.processed_periods'));
        $this->assertCount(3, $this->rowsFor($asset));
        $this->assertSame('600.00', (string) $asset->fresh()->accumulated_depreciation);
    }
}
