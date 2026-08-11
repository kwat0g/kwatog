<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

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

        $this->artisan('assets:run-monthly-depreciation', ['--year' => 2025, '--month' => 12])
            ->assertExitCode(0);

        $this->assertDatabaseCount('asset_depreciations', 1);
        $this->assertDatabaseHas('asset_depreciations', [
            'asset_id' => $asset->id,
            'period_year' => 2025,
            'period_month' => 12,
            'depreciation_amount' => '200.00',
        ]);
        $this->assertSame('200.00', $asset->fresh()->accumulated_depreciation);

        $this->artisan('assets:run-monthly-depreciation', ['--year' => 2025, '--month' => 12])
            ->assertExitCode(0);

        $this->assertDatabaseCount('asset_depreciations', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame('200.00', $asset->fresh()->accumulated_depreciation);
    }
}
