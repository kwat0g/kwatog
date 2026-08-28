<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetRestoreRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_soft_deleted_asset_can_be_restored_by_hash_and_requires_delete_permission(): void
    {
        $asset = Asset::create([
            'asset_code' => 'AST-RESTORE-001',
            'name' => 'Archived restore fixture',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => '1000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'status' => AssetStatus::Active->value,
        ]);
        $asset->delete();

        $this->actingAs(User::factory()->withRole('employee')->create())
            ->patchJson("/api/v1/assets/{$asset->hash_id}/restore")
            ->assertForbidden();

        $this->assertSoftDeleted('assets', ['id' => $asset->id]);

        $this->actingAs(User::factory()->withRole('finance_officer')->create())
            ->patchJson("/api/v1/assets/{$asset->hash_id}/restore")
            ->assertOk()
            ->assertJson(['message' => 'Asset restored.']);

        $this->assertNotSoftDeleted('assets', ['id' => $asset->id]);
    }
}
