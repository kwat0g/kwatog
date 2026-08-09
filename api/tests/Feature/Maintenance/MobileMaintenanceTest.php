<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MachineConditionReading;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\PredictiveMaintenanceService;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\MachineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 9 — Maintenance Mobile view backend tests.
 * Tests the API endpoints used by the mobile maintenance tech PWA (work-order
 * flow) plus the service-level condition-reading automation (kept code — the
 * condition-reading HTTP surface was hidden 2026-08-08, see SCOPE-CUT-AUDIT).
 */
class MobileMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(MachineSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $this->machine = Machine::query()->first();
    }

    // ─── MWO List filters ────────────────────────────────────────────

    public function test_mobile_mwo_list_returns_open_work_orders(): void
    {
        // Create open, assigned, and completed MWOs
        $openWo = $this->createMwo(MaintenanceWorkOrderStatus::Open);
        $assignedWo = $this->createMwo(MaintenanceWorkOrderStatus::Assigned);
        $completedWo = $this->createMwo(MaintenanceWorkOrderStatus::Completed);

        // Comma-separated status filter (supported by the service layer)
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/maintenance/work-orders?status=open,assigned,in_progress&per_page=50');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('mwo_number')->toArray();

        $this->assertContains($openWo->mwo_number, $ids);
        $this->assertContains($assignedWo->mwo_number, $ids);
        $this->assertNotContains($completedWo->mwo_number, $ids);
    }

    public function test_mobile_mwo_list_filters_by_assigned_tech(): void
    {
        $tech = Employee::factory()->create();

        $assignedWo = $this->createMwo(MaintenanceWorkOrderStatus::Assigned);
        $assignedWo->forceFill(['assigned_to' => $tech->id])->save();

        $unassignedWo = $this->createMwo(MaintenanceWorkOrderStatus::Open);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/maintenance/work-orders?assigned_to={$tech->id}&per_page=50");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('mwo_number')->toArray();

        $this->assertContains($assignedWo->mwo_number, $ids);
        $this->assertNotContains($unassignedWo->mwo_number, $ids);
    }

    // ─── MWO Completion with parts ───────────────────────────────────

    public function test_mobile_mwo_completion_records_parts_used(): void
    {
        $wo = $this->createMwo(MaintenanceWorkOrderStatus::InProgress);

        // Seed a spare part usage record directly (bypassing stock movement)
        // to test that the completion response includes parts.
        $cat = ItemCategory::firstOrCreate(
            ['name' => 'Spare Parts'],
            ['parent_id' => null]
        );

        $item = Item::create([
            'code'            => 'SP-T-' . substr(uniqid(), -5),
            'name'            => 'Test Bearing',
            'item_type'       => ItemType::SparePart->value,
            'unit_of_measure' => 'pcs',
            'category_id'     => $cat->id,
            'is_active'       => true,
        ]);

        \App\Modules\Maintenance\Models\SparePartUsage::create([
            'work_order_id'     => $wo->id,
            'item_id'           => $item->id,
            'quantity'          => '2',
            'unit_cost'         => '250.00',
            'total_cost'        => '500.00',
            'stock_movement_id' => null,
            'created_at'        => now(),
        ]);

        // Complete the work order
        $completeResponse = $this->actingAs($this->admin)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo->hash_id}/complete", [
                'remarks'          => 'Replaced worn bearing',
                'downtime_minutes' => 45,
            ]);

        $completeResponse->assertOk();
        $completeResponse->assertJsonPath('data.status', 'completed');
        $completeResponse->assertJsonPath('data.downtime_minutes', 45);

        // Verify spare parts are included in the detail response
        $this->assertNotEmpty($completeResponse->json('data.spare_parts'));
        $this->assertEquals('2.00', $completeResponse->json('data.spare_parts.0.quantity'));
    }

    public function test_mobile_mwo_start_transitions_to_in_progress(): void
    {
        $wo = $this->createMwo(MaintenanceWorkOrderStatus::Open);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo->hash_id}/start");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'in_progress');
    }

    // ─── Condition readings (service-level — routes hidden 2026-08-08) ─
    //
    // The condition-reading HTTP surface (desktop page, mobile entry, backend
    // routes) is hidden per scope cut (no IoT/edge connection to machines).
    // PredictiveMaintenanceService is kept code, so its breach → auto-corrective
    // WO automation keeps service-level coverage here.

    public function test_condition_reading_records_normal_value(): void
    {
        $result = app(PredictiveMaintenanceService::class)->recordAndEvaluate([
            'machine_id' => $this->machine->id,
            'metric'     => 'temperature',
            'value'      => 55.0,
            'source'     => 'manual',
        ], $this->admin);

        $this->assertFalse($result['triggered']);
        $this->assertDatabaseHas('machine_condition_readings', [
            'machine_id' => $this->machine->id,
            'metric'     => 'temperature',
            'value'      => 55.0,
        ]);
    }

    public function test_condition_reading_triggers_alert_on_breach(): void
    {
        // Seed 3 consecutive breach readings (BREACH_WINDOW = 3)
        foreach (range(1, 2) as $i) {
            MachineConditionReading::create([
                'machine_id'  => $this->machine->id,
                'metric'      => 'temperature',
                'value'       => 90.0,
                'unit'        => 'celsius',
                'recorded_at' => now()->subMinutes(10 - $i),
                'source'      => 'manual',
                'recorded_by' => $this->admin->id,
            ]);
        }

        // Third breach reading should trigger a corrective WO
        $result = app(PredictiveMaintenanceService::class)->recordAndEvaluate([
            'machine_id' => $this->machine->id,
            'metric'     => 'temperature',
            'value'      => 92.0,
            'source'     => 'manual',
        ], $this->admin);

        $this->assertTrue($result['triggered']);
        $this->assertNotNull($result['work_order']);
        $this->assertNotNull($result['work_order']->mwo_number);
    }

    public function test_health_snapshot_returns_all_metrics(): void
    {
        // Record one reading so snapshot has data
        MachineConditionReading::create([
            'machine_id'  => $this->machine->id,
            'metric'      => 'vibration',
            'value'       => 3.5,
            'unit'        => 'mm/s',
            'recorded_at' => now(),
            'source'      => 'manual',
            'recorded_by' => $this->admin->id,
        ]);

        $metrics = collect(app(PredictiveMaintenanceService::class)
            ->machineHealthSnapshot($this->machine->id))
            ->pluck('metric')
            ->toArray();
        $this->assertContains('temperature', $metrics);
        $this->assertContains('vibration', $metrics);
        $this->assertContains('pressure', $metrics);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function createMwo(MaintenanceWorkOrderStatus $status): MaintenanceWorkOrder
    {
        $seq = 'MWO-T-' . substr(uniqid(), -5);

        return MaintenanceWorkOrder::create([
            'mwo_number'        => $seq,
            'maintainable_type' => 'machine',
            'maintainable_id'   => $this->machine->id,
            'type'              => MaintenanceWorkOrderType::Corrective->value,
            'priority'          => MaintenancePriority::Medium->value,
            'description'       => 'Test MWO',
            'status'            => $status->value,
            'created_by'        => $this->admin->id,
        ]);
    }
}
