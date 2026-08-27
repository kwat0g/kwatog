<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Services\DepreciationService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M031-F08 — the depreciation history filter speaks HashIDs.
 *
 * `AssetDepreciationController::index()` cast `asset_id` with `(int)`, while
 * `spa/src/api/assets.ts:32` types the parameter `string` because every
 * identifier this API publishes is a HashID. A hash therefore cast to `0` and
 * the endpoint answered HTTP 200 with an empty page — a filter silently
 * answering a different question than the one asked.
 */
class AssetDepreciationListFilterTest extends TestCase
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

    private function asset(string $code, string $cost): Asset
    {
        return Asset::create([
            'asset_code' => $code,
            'name' => 'Filter '.$code,
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-06-01',
            'acquisition_cost' => $cost,
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'status' => AssetStatus::Active->value,
        ]);
    }

    public function test_history_can_be_filtered_by_the_published_asset_hash_id(): void
    {
        $actor = $this->user();
        $kept = $this->asset('AST-F-A', '12000.00');   // 200.00 / month
        $other = $this->asset('AST-F-B', '60000.00');  // 1000.00 / month

        app(DepreciationService::class)->runForMonth(2026, 6, $actor);

        $response = $this->actingAs($actor)
            ->getJson('/api/v1/asset-depreciations?asset_id='.$kept->hash_id)
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'), 'The filter must return the asset it names.');
        $this->assertSame($kept->hash_id, $response->json('data.0.asset.id'));
        $this->assertSame('200.00', $response->json('data.0.depreciation_amount'));

        // Sanity: the unfiltered list holds both, so the filter narrowed rather
        // than the fixture being thin.
        $this->assertSame(
            2,
            $this->actingAs($actor)->getJson('/api/v1/asset-depreciations')->assertOk()->json('meta.total'),
        );
        $this->assertNotSame($kept->hash_id, $other->hash_id);
    }

    public function test_an_undecodable_asset_filter_is_refused_rather_than_answered_empty(): void
    {
        $actor = $this->user();
        $this->asset('AST-F-C', '12000.00');
        app(DepreciationService::class)->runForMonth(2026, 6, $actor);

        $this->actingAs($actor)
            ->getJson('/api/v1/asset-depreciations?asset_id=not-a-hash')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_id']);

        // A raw integer is not the published contract either, and must not work
        // by accident.
        $this->actingAs($actor)
            ->getJson('/api/v1/asset-depreciations?asset_id=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_id']);
    }
}
