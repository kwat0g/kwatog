<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Exceptions\BusinessRuleException;
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
 * P01-01 shape on the "disposed assets are immutable" rule: AssetService::update
 * evaluates the guard on the *passed* model inside the transaction without a
 * locked re-read. A concurrent dispose commits first; the stale update then
 * mutates an asset that is already disposed — the immutability rule is bypassed.
 */
class AssetUpdateVsDisposeRaceTest extends TestCase
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
            'asset_code'               => 'AST-UPD-'.substr(uniqid(), -6),
            'name'                     => 'Forklift B',
            'category'                 => AssetCategory::Equipment->value,
            'acquisition_date'         => '2026-02-01',
            'acquisition_cost'         => 50000,
            'useful_life_years'        => 5,
            'salvage_value'            => 0,
            'accumulated_depreciation' => 10000,
            'status'                   => AssetStatus::Active->value,
        ]);
    }

    public function test_stale_update_cannot_mutate_just_disposed_asset(): void
    {
        $by = $this->user();
        $asset = $this->asset();

        // Disposer and updater each fetched the row while it was active.
        $disposer = Asset::find($asset->id);
        $updater = Asset::find($asset->id);

        // Disposer commits first — asset is now Disposed in the DB.
        app(AssetService::class)->dispose($disposer, ['disposal_amount' => 40000], $by);

        // Concurrent stale updater still sees `active` in memory.
        try {
            app(AssetService::class)->update($updater, ['name' => 'Should Not Land']);
            $this->fail('A stale update must not mutate a disposed asset.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        $this->assertSame('Forklift B', $asset->refresh()->name);
        $this->assertSame(AssetStatus::Disposed, $asset->refresh()->status);
    }
}
