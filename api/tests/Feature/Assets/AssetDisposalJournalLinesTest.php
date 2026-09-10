<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ApprovalService;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Assets\Services\DepreciationService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * M031-F13 — the disposal journal must not contain a zero-amount line.
 *
 * `AssetService::dispose()` emitted the proceeds line and the
 * accumulated-depreciation reversal unconditionally, and
 * `JournalEntryService` rejects any line whose debit and credit are both zero
 * ("Each line must have exactly one of debit or credit greater than zero").
 * That made two ordinary disposals impossible, with the whole transaction
 * rolled back behind a generic ledger error:
 *
 *   1. a zero-proceeds scrapping — a worn-out mold or a written-off machine —
 *      even though `DisposeAssetRequest` explicitly allows `disposal_amount`
 *      `min:0`;
 *   2. the disposal of an asset that has not been depreciated yet, where the
 *      reversal line is zero.
 *
 * The single existing disposal test used non-zero cost, accumulated
 * depreciation and proceeds together, so all three lines happened to be
 * non-zero and the defect stayed invisible.
 *
 * AS-03 update: disposal is two-phase now (request → full chain approval →
 * execute), so the disposals here run through the approval chain; the JE
 * assertions pin that execution still journals exactly what it did before.
 */
class AssetDisposalJournalLinesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ChartOfAccountsSeeder::class,
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            WorkflowSeeder::class,
        ]);
        Carbon::setTestNow('2026-06-20 10:00:00');
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

    /**
     * Dispose via the two-phase flow: request by a finance officer, then the
     * seeded finance_officer → system_admin chain approves to execution.
     *
     * @param array<string, mixed> $data
     */
    private function disposeViaApproval(Asset $asset, array $data): void
    {
        $svc = app(AssetService::class);
        $svc->requestDisposal($asset, $data, User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]));
        $svc->approveDisposal($asset, User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]));
        $svc->approveDisposal($asset, $this->user());
    }

    /** @param array<string, mixed> $overrides */
    private function asset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'asset_code' => 'AST-DJ-'.substr(uniqid(), -6),
            'name' => 'Disposal journal asset',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-15',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'accumulated_depreciation' => '0.00',
            'status' => AssetStatus::Active->value,
        ], $overrides));
    }

    private function disposalEntry(Asset $asset): JournalEntry
    {
        return JournalEntry::with('lines.account')
            ->where('reference_type', Asset::class)
            ->where('reference_id', $asset->getKey())
            ->sole();
    }

    private function assertJournalIsWellFormed(JournalEntry $je): void
    {
        $this->assertGreaterThanOrEqual(2, $je->lines->count(), 'A journal entry needs at least two lines.');

        foreach ($je->lines as $line) {
            $debit = (string) $line->debit;
            $credit = (string) $line->credit;
            $this->assertNotSame(
                Money::gt($debit, '0'),
                Money::gt($credit, '0'),
                "Line {$line->id} must carry exactly one of debit or credit.",
            );
        }

        $debits = Money::zero();
        $credits = Money::zero();
        foreach ($je->lines as $line) {
            $debits = Money::add($debits, (string) $line->debit);
            $credits = Money::add($credits, (string) $line->credit);
        }
        $this->assertSame(0, Money::cmp($debits, $credits), 'The disposal journal must balance.');
        $this->assertSame(0, Money::cmp($debits, (string) $je->total_debit));
    }

    private function accountCode(string $settingKey): string
    {
        return app(SettingsService::class)->requiredString($settingKey);
    }

    /** @return array<string, string> signed net per account code */
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

    public function test_zero_proceeds_scrapping_posts_a_balanced_journal(): void
    {
        $by = $this->user();
        $asset = $this->asset();

        // Jan–May posted through the monthly run (5 × 200 = 1,000); dispose()
        // catches June up itself before derecognising (AS-01).
        app(DepreciationService::class)->runBackfillTo(2026, 5, $by);
        $this->assertSame('1000.00', (string) $asset->fresh()->accumulated_depreciation);

        $this->disposeViaApproval($asset, [
            'disposal_amount' => '0.00',
            'disposed_date' => '2026-06-15',
            'remarks' => 'Scrapped — beyond economical repair',
        ]);

        $je = $this->disposalEntry($asset);
        $this->assertJournalIsWellFormed($je);

        // No proceeds line at all, and the loss absorbs the whole book value.
        $net = $this->netByCode($je);
        $this->assertArrayNotHasKey($this->accountCode('accounting.accounts.asset_cash_code'), $net);
        $this->assertSame('1200.00', $net[$this->accountCode('accounting.accounts.asset_accumulated_depreciation_code')]);
        $this->assertSame('10800.00', $net[$this->accountCode('accounting.accounts.asset_disposal_loss_code')]);
        $this->assertSame('-12000.00', $net[$this->accountCode('accounting.accounts.asset_cost_code')]);

        $this->assertSame(AssetStatus::Disposed, $asset->fresh()->status);
        $this->assertSame('Scrapped — beyond economical repair', $asset->fresh()->disposal_reason);
    }

    public function test_disposing_a_never_depreciated_asset_omits_the_reversal_line(): void
    {
        // Since AS-01, dispose() depreciates through the disposal month, so a
        // positive depreciable base always arrives caught up. A zero-base
        // asset (salvage == cost) is the remaining never-depreciated case.
        $asset = $this->asset(['salvage_value' => '12000.00', 'accumulated_depreciation' => '0.00']);

        $this->disposeViaApproval($asset, [
            'disposal_amount' => '0.00',
            'disposed_date' => '2026-06-15',
            'remarks' => 'Written off before commissioning',
        ]);

        $je = $this->disposalEntry($asset);
        $this->assertJournalIsWellFormed($je);

        $net = $this->netByCode($je);
        $this->assertArrayNotHasKey($this->accountCode('accounting.accounts.asset_accumulated_depreciation_code'), $net);
        $this->assertSame('12000.00', $net[$this->accountCode('accounting.accounts.asset_disposal_loss_code')]);
        $this->assertSame('-12000.00', $net[$this->accountCode('accounting.accounts.asset_cost_code')]);
    }

    public function test_fully_depreciated_asset_sold_for_cash_books_a_gain(): void
    {
        $asset = $this->asset(['accumulated_depreciation' => '12000.00']);

        $this->disposeViaApproval($asset, [
            'disposal_amount' => '500.00',
            'disposed_date' => '2026-06-15',
            'remarks' => 'Sold for scrap value',
        ]);

        $je = $this->disposalEntry($asset);
        $this->assertJournalIsWellFormed($je);

        // Book value is zero, so the whole proceeds are a gain and no loss line
        // may appear.
        $net = $this->netByCode($je);
        $this->assertArrayNotHasKey($this->accountCode('accounting.accounts.asset_disposal_loss_code'), $net);
        $this->assertSame('500.00', $net[$this->accountCode('accounting.accounts.asset_cash_code')]);
        $this->assertSame('12000.00', $net[$this->accountCode('accounting.accounts.asset_accumulated_depreciation_code')]);
        $this->assertSame('-12000.00', $net[$this->accountCode('accounting.accounts.asset_cost_code')]);
        $this->assertSame('-500.00', $net[$this->accountCode('accounting.accounts.asset_disposal_gain_code')]);
    }

    public function test_a_disposal_with_nothing_to_journalise_is_refused_explicitly(): void
    {
        // StoreAssetRequest allows acquisition_cost 0, so this row is reachable.
        // With no cost, no proceeds and no accumulated depreciation there are no
        // lines to post; the refusal must name the cause instead of surfacing a
        // generic ledger error or silently disposing without an entry.
        $asset = $this->asset(['acquisition_cost' => '0.00', 'accumulated_depreciation' => '0.00']);

        $svc = app(AssetService::class);
        $svc->requestDisposal($asset, [
            'disposal_amount' => '0.00',
            'disposed_date' => '2026-06-15',
            'remarks' => 'No monetary effect',
        ], User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]));
        $svc->approveDisposal($asset, User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]));

        try {
            $svc->approveDisposal($asset, $this->user());
            $this->fail('A disposal with no monetary effect must be refused.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('no cost, proceeds or accumulated depreciation', $e->getMessage());
        }

        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
        $this->assertSame(
            0,
            JournalEntry::query()->where('reference_type', Asset::class)->where('reference_id', $asset->getKey())->count(),
            'The refused disposal must not leave a journal entry behind.',
        );
        // The refused execution rolled its approval step back, so the request
        // stays pending and resolvable (reject or cancel) instead of closing
        // a chain whose asset was never disposed.
        $this->assertNotNull(app(ApprovalService::class)->nextStep($asset->fresh()));
    }
}
