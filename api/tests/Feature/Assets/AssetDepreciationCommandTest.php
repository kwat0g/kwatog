<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Services\SystemActorService;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetDepreciationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ChartOfAccountsSeeder::class, RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_backfill_requires_a_complete_period_pair(): void
    {
        $this->artisan('assets:run-monthly-depreciation', ['--year' => 2026])
            ->expectsOutput('Both --year and --month must be provided together.')
            ->assertExitCode(1);
    }

    public function test_backfill_reports_missing_automation_actor_instead_of_succeeding(): void
    {
        // Establish the premise instead of assuming an empty `users` table.
        //
        // RbacConcurrencyTest declares `protected array $connectionsToTransact = []`
        // (tests/Feature/Admin/RbacConcurrencyTest.php:31), which turns
        // RefreshDatabase's per-test transaction OFF for that class because its
        // forked children need to see the fixtures on a second connection. Its
        // cleanup does not delete the ACTIVE system_admin rows it committed, and
        // it does not reset RefreshDatabaseState, so those users survive for the
        // rest of the PHPUnit process. `tests/Feature/Admin` sorts before
        // `tests/Feature/Assets`, so in a full-suite run
        // SystemActorService::resolve() found a leaked actor here: the guard under
        // test was never reached and the command correctly returned SUCCESS for a
        // run that genuinely had an actor. Reproduced with
        // `--filter='RbacConcurrencyTest|AssetDepreciationCommandTest'`.
        //
        // "No automation actor exists" is this test's whole premise, so it is owned
        // here rather than inherited from suite ordering. The write runs inside
        // this test's own transaction and is rolled back.
        User::query()->update(['is_active' => false]);

        $this->assertNull(
            app(SystemActorService::class)->resolve(),
            'Precondition: no eligible automation actor may exist.',
        );

        $this->artisan('assets:run-monthly-depreciation', ['--year' => 2026, '--month' => 1])
            ->expectsOutput('Asset depreciation cannot run without an automation actor.')
            ->assertExitCode(1);
    }

    public function test_explicit_missed_month_backfill_is_idempotent_on_rerun(): void
    {
        User::factory()->withRole('system_admin')->create();
        $asset = Asset::create([
            'asset_code' => 'AST-BACKFILL-001',
            'name' => 'Backfill test asset',
            'category' => 'equipment',
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'status' => AssetStatus::Active->value,
        ]);

        $this->artisan('assets:run-monthly-depreciation', ['--year' => 2025, '--month' => 12, '--backfill' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('asset_depreciations', 12);
        $this->assertDatabaseHas('asset_depreciations', [
            'asset_id' => $asset->id,
            'period_year' => 2025,
            'period_month' => 12,
            'depreciation_amount' => '200.00',
        ]);
        $this->assertSame('2400.00', $asset->fresh()->accumulated_depreciation);

        $this->artisan('assets:run-monthly-depreciation', ['--year' => 2025, '--month' => 12, '--backfill' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('asset_depreciations', 12);
        $this->assertDatabaseCount('journal_entries', 12);
        $this->assertSame('2400.00', $asset->fresh()->accumulated_depreciation);
    }
}
