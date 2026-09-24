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
}
