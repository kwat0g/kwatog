<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P01-01 shape on the assets ledger: AssetService::dispose guarded the *passed*
 * model outside the transaction with no locked re-read inside. Two concurrent
 * disposals both observe `active` and each posts its own disposal journal entry
 * — the disposal JE is double-booked (cash credited twice, PPE removed twice).
 */
class AssetDisposeDoublePostingRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ChartOfAccountsSeeder::class, RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function asset(): Asset
    {
        return Asset::create([
            'asset_code'               => 'AST-RACE-'.substr(uniqid(), -6),
            'name'                     => 'Race CNC Machine',
            'category'                 => AssetCategory::Equipment->value,
            'acquisition_date'         => '2026-01-15',
            'acquisition_cost'         => 100000,
            'useful_life_years'        => 5,
            'salvage_value'            => 0,
            'accumulated_depreciation' => 20000,
            'status'                   => AssetStatus::Active->value,
        ]);
    }

    public function test_stale_second_dispose_is_blocked_and_posts_single_je(): void
    {
        $by = $this->user();
        $asset = $this->asset();

        // Both "concurrent" disposers fetched the row while it was active.
        $disposerA = Asset::find($asset->id);
        $disposerB = Asset::find($asset->id);

        app(AssetService::class)->dispose($disposerA, [
            'disposal_amount' => 90000,
            'disposed_date'   => '2026-08-13',
        ], $by);

        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('reference_type', Asset::class)
                ->where('reference_id', $asset->id)
                ->count(),
            'Exactly one disposal JE must be posted for the asset.'
        );

        try {
            app(AssetService::class)->dispose($disposerB, [
                'disposal_amount' => 90000,
                'disposed_date'   => '2026-08-13',
            ], $by);
            $this->fail('A stale second dispose must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already disposed', strtolower($e->getMessage()));
        }

        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('reference_type', Asset::class)
                ->where('reference_id', $asset->id)
                ->count(),
            'The stale dispose must not post a second journal entry.'
        );
    }
}
