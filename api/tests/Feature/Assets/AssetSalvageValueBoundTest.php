<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M031-F18 — salvage value is residual value and cannot exceed acquisition cost.
 *
 * Over the bound the depreciable base clamps to zero, so the asset is accepted
 * and then silently never depreciates: no monthly expense, no schedule rows,
 * nothing raised. These cases pin the rejection at every writer.
 */
class AssetSalvageValueBoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function financeOfficer(): User
    {
        return User::factory()->withRole('finance_officer')->create();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Salvage bound fixture',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'depreciation_method' => 'straight_line',
        ], $overrides);
    }

    private function asset(string $cost, string $salvage): Asset
    {
        return Asset::create([
            'asset_code' => 'AST-SALV-'.substr(uniqid(), -5),
            'name' => 'Salvage bound fixture',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => $cost,
            'useful_life_years' => 5,
            'salvage_value' => $salvage,
            'status' => AssetStatus::Active->value,
        ]);
    }

    public function test_create_rejects_salvage_above_acquisition_cost(): void
    {
        $this->actingAs($this->financeOfficer())
            ->postJson('/api/v1/assets', $this->payload(['salvage_value' => '20000.00']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salvage_value']);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_create_accepts_salvage_equal_to_and_below_acquisition_cost(): void
    {
        $actor = $this->financeOfficer();

        // Equal is legal: a fully-residual asset simply never depreciates, and
        // that is a stated schedule rather than a silent one.
        $equal = $this->actingAs($actor)
            ->postJson('/api/v1/assets', $this->payload(['salvage_value' => '12000.00']))
            ->assertCreated()
            ->json('data');
        $this->assertSame('12000.00', $equal['salvage_value']);
        $this->assertSame('0.00', $equal['monthly_depreciation']);

        $below = $this->actingAs($actor)
            ->postJson('/api/v1/assets', $this->payload(['salvage_value' => '2400.00']))
            ->assertCreated()
            ->json('data');
        $this->assertSame('160.00', $below['monthly_depreciation']);
    }

    public function test_create_without_salvage_value_still_defaults_to_zero(): void
    {
        $created = $this->actingAs($this->financeOfficer())
            ->postJson('/api/v1/assets', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->assertSame('0.00', $created['salvage_value']);
        $this->assertSame('200.00', $created['monthly_depreciation']);
    }

    public function test_update_rejects_raising_salvage_above_acquisition_cost(): void
    {
        $asset = $this->asset('12000.00', '1000.00');

        $this->actingAs($this->financeOfficer())
            ->putJson("/api/v1/assets/{$asset->hash_id}", [
                'name' => $asset->name,
                'useful_life_years' => 5,
                'salvage_value' => '12000.01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salvage_value']);

        $this->assertSame('1000.00', (string) $asset->fresh()->salvage_value);
    }

    public function test_update_leaves_an_existing_over_cost_row_editable(): void
    {
        // A row that already breaks the bound must not become un-editable:
        // acquisition cost is not updatable here, and once depreciation history
        // exists salvage is frozen, so a strict rule would lock the record.
        $asset = $this->asset('12000.00', '20000.00');
        AssetDepreciation::create([
            'asset_id' => $asset->id,
            'period_year' => 2026,
            'period_month' => 1,
            'depreciation_amount' => '0.00',
            'accumulated_after' => '0.00',
            'journal_entry_id' => null,
            'created_at' => now(),
        ]);

        $this->actingAs($this->financeOfficer())
            ->putJson("/api/v1/assets/{$asset->hash_id}", [
                'name' => 'Renamed while over the bound',
                'useful_life_years' => 5,
                'salvage_value' => '20000.00',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed while over the bound');
    }

    public function test_service_rejects_the_bound_for_non_http_callers(): void
    {
        $service = app(AssetService::class);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Salvage value 20000.00 cannot exceed the acquisition cost 12000.00.');

        $service->create([
            'name' => 'Direct service call',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '20000.00',
        ]);
    }

    public function test_service_rejects_an_update_that_raises_salvage_over_cost(): void
    {
        $asset = $this->asset('12000.00', '1000.00');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Salvage value 12500.00 cannot exceed the acquisition cost 12000.00.');

        app(AssetService::class)->update($asset, ['salvage_value' => '12500.00']);
    }
}
