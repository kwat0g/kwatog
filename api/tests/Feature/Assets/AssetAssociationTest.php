<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Dashboard\Services\Analytics\AssetWidgetAnalytics;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\SupplyChain\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetAssociationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->finance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
    }

    public function test_authorized_asset_endpoint_associates_each_supported_operational_record_with_hash_ids(): void
    {
        $machineAsset = $this->asset(AssetCategory::Machine, 'AST-LINK-M');
        $machine = Machine::factory()->create();
        $moldAsset = $this->asset(AssetCategory::Mold, 'AST-LINK-O');
        $mold = Mold::create([
            'mold_code' => 'M-'.substr(uniqid(), -6),
            'name' => 'Association mold',
            'product_id' => Product::factory()->create()->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 10,
            'output_rate_per_hour' => 360,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots' => 10000,
            'status' => 'available',
        ]);
        $vehicleAsset = $this->asset(AssetCategory::Vehicle, 'AST-LINK-V');
        $vehicle = Vehicle::create([
            'plate_number' => 'ABC-'.substr(uniqid(), -4),
            'name' => 'Association van',
            'vehicle_type' => 'van',
            'status' => 'available',
        ]);

        foreach ([
            [$machineAsset, 'machine', $machine],
            [$moldAsset, 'mold', $mold],
            [$vehicleAsset, 'vehicle', $vehicle],
        ] as [$asset, $type, $target]) {
            $response = $this->actingAs($this->finance)->patchJson(
                "/api/v1/assets/{$asset->hash_id}/association",
                ['target_type' => $type, 'target_id' => $target->hash_id],
            );

            $response->assertOk()
                ->assertJsonPath('data.id', $asset->hash_id)
                ->assertJsonPath('data.association.type', $type)
                ->assertJsonPath('data.association.id', $target->hash_id);
            $this->assertDatabaseHas($target->getTable(), [
                'id' => $target->id,
                'asset_id' => $asset->id,
            ]);
        }
    }

    public function test_association_requires_the_asset_update_permission_and_can_be_cleared(): void
    {
        $asset = $this->asset(AssetCategory::Machine, 'AST-LINK-A');
        $machine = Machine::factory()->create(['asset_id' => $asset->id]);
        $employee = User::factory()->withRole('employee')->create();

        $this->actingAs($employee)
            ->patchJson("/api/v1/assets/{$asset->hash_id}/association", [
                'target_type' => 'machine',
                'target_id' => $machine->hash_id,
            ])
            ->assertForbidden();

        $this->actingAs($this->finance)
            ->patchJson("/api/v1/assets/{$asset->hash_id}/association", [
                'target_type' => 'machine',
                'target_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.association', null);
        $this->assertDatabaseHas('machines', ['id' => $machine->id, 'asset_id' => null]);
    }

    public function test_association_rejects_a_category_mismatch_and_a_second_asset(): void
    {
        $asset = $this->asset(AssetCategory::Machine, 'AST-LINK-B');
        $otherAsset = $this->asset(AssetCategory::Machine, 'AST-LINK-C');
        $machine = Machine::factory()->create(['asset_id' => $otherAsset->id]);

        $this->actingAs($this->finance)
            ->patchJson("/api/v1/assets/{$asset->hash_id}/association", [
                'target_type' => 'mold',
                'target_id' => $machine->hash_id,
            ])
            ->assertUnprocessable();

        $this->actingAs($this->finance)
            ->patchJson("/api/v1/assets/{$asset->hash_id}/association", [
                'target_type' => 'machine',
                'target_id' => $machine->hash_id,
            ])
            ->assertUnprocessable();
    }

    public function test_asset_editors_get_only_unassociated_targets_for_supported_categories(): void
    {
        foreach (AssetCategory::cases() as $category) {
            if (! in_array($category, [AssetCategory::Machine, AssetCategory::Mold, AssetCategory::Vehicle], true)) {
                continue;
            }

            $asset = $this->asset($category, 'AST-OPTIONS-'.$category->value);
            $otherAsset = $this->asset($category, 'AST-OTHER-'.$category->value);
            $this->operationalTarget($category, $otherAsset->id);
            $available = $this->operationalTarget($category);
            $availableCode = match ($category) {
                AssetCategory::Machine => $available->machine_code,
                AssetCategory::Mold => $available->mold_code,
                AssetCategory::Vehicle => $available->plate_number,
                default => throw new \LogicException('Unsupported asset association category.'),
            };

            $this->actingAs($this->finance)
                ->getJson("/api/v1/assets/{$asset->hash_id}/association-options")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $available->hash_id)
                ->assertJsonPath('data.0.code', $availableCode)
                ->assertJsonPath('data.0.name', $available->name);
        }
    }

    public function test_association_options_require_asset_update_permission(): void
    {
        $asset = $this->asset(AssetCategory::Vehicle, 'AST-OPTIONS-AUTH');
        $employee = User::factory()->withRole('employee')->create();

        $this->actingAs($employee)
            ->getJson("/api/v1/assets/{$asset->hash_id}/association-options")
            ->assertForbidden();
    }

    public function test_association_options_search_and_paginate_large_target_sets(): void
    {
        $asset = $this->asset(AssetCategory::Machine, 'AST-OPTIONS-PAGE');
        Machine::factory()->create(['machine_code' => 'PRESS-001', 'name' => 'Press One']);
        $second = Machine::factory()->create(['machine_code' => 'PRESS-002', 'name' => 'Press Two']);
        Machine::factory()->create(['machine_code' => 'MILL-001', 'name' => 'Mill One']);

        $this->actingAs($this->finance)
            ->getJson("/api/v1/assets/{$asset->hash_id}/association-options?search=Press&per_page=1&page=2")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->hash_id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_linked_operational_records_must_be_disassociated_before_archiving(): void
    {
        $machineAsset = $this->asset(AssetCategory::Machine, 'AST-ARCHIVE-M');
        $machine = Machine::factory()->create(['asset_id' => $machineAsset->id]);
        $moldAsset = $this->asset(AssetCategory::Mold, 'AST-ARCHIVE-O');
        $mold = $this->operationalTarget(AssetCategory::Mold, $moldAsset->id);
        $vehicleAsset = $this->asset(AssetCategory::Vehicle, 'AST-ARCHIVE-V');
        $vehicle = $this->operationalTarget(AssetCategory::Vehicle, $vehicleAsset->id);
        $production = User::factory()->withRole('production_manager')->create();
        $impex = User::factory()->withRole('impex_officer')->create();

        foreach ([
            ['/api/v1/mrp/machines/'.$machine->hash_id, $machineAsset, 'machine', $machine, $production],
            ['/api/v1/mrp/molds/'.$mold->hash_id, $moldAsset, 'mold', $mold, $production],
            ['/api/v1/supply-chain/vehicles/'.$vehicle->hash_id, $vehicleAsset, 'vehicle', $vehicle, $impex],
        ] as [$url, $asset, $type, $target, $actor]) {
            $this->actingAs($actor)->deleteJson($url)->assertUnprocessable();
            $this->assertDatabaseHas($target->getTable(), ['id' => $target->id, 'asset_id' => $asset->id, 'deleted_at' => null]);

            $this->actingAs($this->finance)
                ->patchJson('/api/v1/assets/'.$asset->hash_id.'/association', [
                    'target_type' => $type,
                    'target_id' => null,
                ])
                ->assertOk()
                ->assertJsonPath('data.association', null);

            $this->actingAs($actor)->deleteJson($url)->assertNoContent();
            $this->assertSoftDeleted($target);
        }
    }

    public function test_associated_operational_records_block_asset_deletion(): void
    {
        $machineAsset = $this->asset(AssetCategory::Machine, 'AST-DELETE-M');
        $machine = Machine::factory()->create(['asset_id' => $machineAsset->id]);
        $moldAsset = $this->asset(AssetCategory::Mold, 'AST-DELETE-O');
        $mold = $this->operationalTarget(AssetCategory::Mold, $moldAsset->id);
        $vehicleAsset = $this->asset(AssetCategory::Vehicle, 'AST-DELETE-V');
        $vehicle = $this->operationalTarget(AssetCategory::Vehicle, $vehicleAsset->id);

        foreach ([
            [$machineAsset, $machine],
            [$moldAsset, $mold],
            [$vehicleAsset, $vehicle],
        ] as [$asset, $target]) {
            $this->actingAs($this->finance)
                ->deleteJson('/api/v1/assets/'.$asset->hash_id)
                ->assertUnprocessable();

            $this->assertDatabaseHas('assets', ['id' => $asset->id, 'deleted_at' => null]);
            $this->assertDatabaseHas($target->getTable(), ['id' => $target->id, 'asset_id' => $asset->id]);
        }
    }

    public function test_legacy_archived_association_remains_visible_and_can_be_cleared(): void
    {
        $asset = $this->asset(AssetCategory::Vehicle, 'AST-LEGACY-ARCHIVE');
        $vehicle = $this->operationalTarget(AssetCategory::Vehicle, $asset->id);
        $vehicle->delete();

        $this->actingAs($this->finance)
            ->deleteJson('/api/v1/assets/'.$asset->hash_id)
            ->assertUnprocessable();
        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'deleted_at' => null]);

        $this->actingAs($this->finance)
            ->getJson('/api/v1/assets/'.$asset->hash_id)
            ->assertOk()
            ->assertJsonPath('data.association.type', 'vehicle')
            ->assertJsonPath('data.association.id', $vehicle->hash_id)
            ->assertJsonPath('data.association.archived', true);

        $this->patchJson('/api/v1/assets/'.$asset->hash_id.'/association', [
            'target_type' => 'vehicle',
            'target_id' => null,
        ])->assertOk()->assertJsonPath('data.association', null);

        $this->assertSoftDeleted($vehicle);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'asset_id' => null]);
    }

    public function test_legacy_archived_association_blocks_replacement(): void
    {
        $asset = $this->asset(AssetCategory::Vehicle, 'AST-LEGACY-REPLACE');
        $archived = $this->operationalTarget(AssetCategory::Vehicle, $asset->id);
        $archived->delete();
        $replacement = $this->operationalTarget(AssetCategory::Vehicle);

        $this->actingAs($this->finance)
            ->patchJson('/api/v1/assets/'.$asset->hash_id.'/association', [
                'target_type' => 'vehicle',
                'target_id' => $replacement->hash_id,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('vehicles', ['id' => $archived->id, 'asset_id' => $asset->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $replacement->id, 'asset_id' => null, 'deleted_at' => null]);
    }

    public function test_existing_maintenance_work_order_truthfully_drives_asset_status_and_dashboard_count(): void
    {
        $asset = $this->asset(AssetCategory::Machine, 'AST-MAINT-1');
        $machine = Machine::factory()->create(['asset_id' => $asset->id]);
        $wo = MaintenanceWorkOrder::create([
            'mwo_number' => 'MWO-'.substr(uniqid(), -8),
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => MaintenanceWorkOrderType::Corrective->value,
            'priority' => MaintenancePriority::Medium->value,
            'description' => 'Linked asset maintenance',
            'status' => MaintenanceWorkOrderStatus::Open->value,
            'created_by' => $this->finance->id,
        ]);

        app(MaintenanceWorkOrderService::class)->start($wo, $this->finance);
        $this->assertSame(AssetStatus::UnderMaintenance, $asset->fresh()->status);
        $this->assertSame(1, app(AssetWidgetAnalytics::class)->payload('assets.under_maintenance', $this->finance)['total']);

        app(MaintenanceWorkOrderService::class)->complete($wo, [], $this->finance);
        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
        $this->assertSame(0, app(AssetWidgetAnalytics::class)->payload('assets.under_maintenance', $this->finance)['total']);
    }

    public function test_open_followup_order_does_not_keep_repaired_asset_under_maintenance(): void
    {
        $asset = $this->asset(AssetCategory::Machine, 'AST-MAINT-OPEN');
        $machine = Machine::factory()->create(['asset_id' => $asset->id, 'status' => 'idle']);
        $order = MaintenanceWorkOrder::create([
            'mwo_number' => 'MWO-'.substr(uniqid(), -8),
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'type' => 'corrective', 'priority' => 'medium',
            'description' => 'Active repair', 'status' => 'open', 'created_by' => $this->finance->id,
        ]);
        app(MaintenanceWorkOrderService::class)->start($order, $this->finance);
        MaintenanceWorkOrder::create([
            'mwo_number' => 'MWO-'.substr(uniqid(), -8),
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'type' => 'preventive', 'priority' => 'medium',
            'description' => 'Future inspection', 'status' => 'open', 'created_by' => $this->finance->id,
        ]);

        app(MaintenanceWorkOrderService::class)->complete($order, [], $this->finance);
        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
    }

    public function test_cancelled_breakdown_repair_clears_linked_asset_maintenance_status(): void
    {
        $asset = $this->asset(AssetCategory::Machine, 'AST-MAINT-CANCEL');
        $machine = Machine::factory()->create(['asset_id' => $asset->id, 'status' => 'breakdown']);
        $order = MaintenanceWorkOrder::create([
            'mwo_number' => 'MWO-'.substr(uniqid(), -8),
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'type' => 'corrective', 'priority' => 'high',
            'description' => 'Breakdown repair', 'status' => 'open', 'created_by' => $this->finance->id,
        ]);

        app(MaintenanceWorkOrderService::class)->start($order, $this->finance);
        $this->assertSame(AssetStatus::UnderMaintenance, $asset->fresh()->status);
        app(MaintenanceWorkOrderService::class)->cancel($order, 'Repair handed to vendor', $this->finance);
        $this->assertSame('breakdown', $machine->fresh()->status->value);
        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
    }

    private function asset(AssetCategory $category, string $code): Asset
    {
        return Asset::create([
            'asset_code' => $code,
            'name' => 'Linked '.$category->value,
            'category' => $category->value,
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'status' => AssetStatus::Active->value,
        ]);
    }

    private function operationalTarget(AssetCategory $category, ?int $assetId = null): Model
    {
        return match ($category) {
            AssetCategory::Machine => Machine::factory()->create(['asset_id' => $assetId]),
            AssetCategory::Mold => Mold::create([
                'mold_code' => 'M-'.substr(uniqid(), -6),
                'name' => 'Association option mold',
                'product_id' => Product::factory()->create()->id,
                'cavity_count' => 1,
                'cycle_time_seconds' => 10,
                'output_rate_per_hour' => 360,
                'max_shots_before_maintenance' => 1000,
                'lifetime_max_shots' => 10000,
                'status' => 'available',
                'asset_id' => $assetId,
            ]),
            AssetCategory::Vehicle => Vehicle::create([
                'plate_number' => 'AS-'.substr(uniqid(), -6),
                'name' => 'Association option vehicle',
                'vehicle_type' => 'van',
                'status' => 'available',
                'asset_id' => $assetId,
            ]),
            default => throw new \LogicException('Unsupported asset association category.'),
        };
    }
}
