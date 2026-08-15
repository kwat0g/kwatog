<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\SparePartUsageService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P01-01 shape on P61 (maintenance WO → machine/mold state + hours recompute):
 * SparePartUsageService::record bumps the WO running cost on the *passed* stale
 * model (`(float) $wo->cost + $totalCost`) while only the item row is locked.
 * Two concurrent spare-part issues against the same WO read the same stale cost
 * and one record's cost contribution is silently lost.
 */
class SparePartUsageLostCostRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function fixture(): array
    {
        $item = Item::factory()->create([
            'item_type'       => ItemType::SparePart->value,
            'unit_of_measure' => 'pcs',
        ]);
        $location = WarehouseLocation::factory()->create();
        StockLevel::create([
            'item_id'            => $item->id,
            'location_id'        => $location->id,
            'quantity'           => 500,
            'weighted_avg_cost'  => '25.0000',
        ]);

        $wo = MaintenanceWorkOrder::create([
            'mwo_number'        => 'MWO-RACE-'.substr(uniqid(), -6),
            'maintainable_type' => 'machine',
            'maintainable_id'   => 1,
            'type'              => MaintenanceWorkOrderType::Corrective->value,
            'priority'          => MaintenancePriority::Medium->value,
            'description'       => 'Race test WO',
            'status'            => MaintenanceWorkOrderStatus::InProgress->value,
            'cost'              => '0.0000',
            'created_by'        => $this->user()->id,
        ]);

        return [$item, $location, $wo];
    }

    public function test_concurrent_spare_part_records_accumulate_full_running_cost(): void
    {
        $by = $this->user();
        [$item, $location, $wo] = $this->fixture();

        // Both "concurrent" technicians fetched the WO while its cost was 0.
        $techA = MaintenanceWorkOrder::find($wo->id);
        $techB = MaintenanceWorkOrder::find($wo->id);

        $svc = app(SparePartUsageService::class);
        $svc->record($techA, ['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 2], $by);
        $svc->record($techB, ['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 2], $by);

        // 2 records x 2 pcs x 25.00 = 100.00 — a stale-model bump loses one.
        $this->assertSame(
            '100.00',
            $wo->refresh()->cost,
            'WO running cost must accumulate both spare-part issues.'
        );
    }
}
