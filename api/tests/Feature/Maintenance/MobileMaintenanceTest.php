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
use App\Modules\Maintenance\Controllers\MachineConditionReadingController;
use App\Modules\Maintenance\Services\PredictiveMaintenanceService;
use App\Modules\MRP\Models\Machine;
use App\Common\Services\SettingsService;
use Database\Seeders\MachineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Task 9 — Maintenance Mobile view backend tests.
 * Tests the API endpoints used by the mobile maintenance tech PWA (work-order
 * flow) plus the service-level condition-reading automation (kept code — the
     * condition-reading HTTP surface was intentionally hidden on 2026-08-08).
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
        $completeResponse->assertJsonPath('data.downtime_minutes', 0);

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

    public function test_mwo_list_eager_loads_machine_targets_instead_of_querying_per_row(): void
    {
        foreach (range(1, 3) as $_) {
            $this->createMwo(MaintenanceWorkOrderStatus::Open);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)
            ->getJson('/api/v1/maintenance/work-orders?per_page=20')
            ->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $machineLookups = array_filter($queries, static fn (array $query): bool =>
            str_contains(strtolower($query['query']), 'from "machines"')
            || str_contains(strtolower($query['query']), 'from machines'));
        $this->assertCount(1, $machineLookups);
    }

    public function test_database_rejects_unknown_polymorphic_maintenance_target_types(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The target check is a PostgreSQL database constraint.');
        }

        try {
            DB::table('maintenance_work_orders')->insert([
                'mwo_number' => 'MWO-INVALID-'.substr(uniqid(), -5),
                'maintainable_type' => 'asset',
                'maintainable_id' => 1,
                'type' => MaintenanceWorkOrderType::Corrective->value,
                'priority' => MaintenancePriority::Medium->value,
                'description' => 'Invalid target type regression',
                'status' => MaintenanceWorkOrderStatus::Open->value,
                'created_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database must reject unregistered maintenance target types.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Invalid maintenance target type', $exception->getMessage());
        }
    }

    public function test_database_rejects_missing_maintenance_targets(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Polymorphic target triggers are PostgreSQL database constraints.');
        }

        try {
            DB::table('maintenance_work_orders')->insert([
                'mwo_number' => 'MWO-MISSING-'.substr(uniqid(), -5),
                'maintainable_type' => 'machine',
                'maintainable_id' => 999999999,
                'type' => MaintenanceWorkOrderType::Corrective->value,
                'priority' => MaintenancePriority::Medium->value,
                'description' => 'Missing target regression',
                'status' => MaintenanceWorkOrderStatus::Open->value,
                'created_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database must reject a non-existent polymorphic target.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('does not exist or is deleted', $exception->getMessage());
        }

    }

    public function test_database_protects_hard_delete_of_a_maintenance_target(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Polymorphic target triggers are PostgreSQL database constraints.');
        }

        $this->createMwo(MaintenanceWorkOrderStatus::Open);
        try {
            DB::table('machines')->where('id', $this->machine->id)->delete();
            $this->fail('The database must preserve a machine referenced by maintenance history.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('referenced by maintenance history', $exception->getMessage());
        }
    }

    public function test_mobile_mwo_cannot_complete_before_start(): void
    {
        $wo = $this->createMwo(MaintenanceWorkOrderStatus::Open);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo->hash_id}/complete");

        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('maintenance_work_orders', [
            'id' => $wo->id,
            'status' => MaintenanceWorkOrderStatus::Open->value,
        ]);
    }

    public function test_mobile_mwo_log_cannot_mutate_a_completed_order(): void
    {
        $wo = $this->createMwo(MaintenanceWorkOrderStatus::InProgress);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo->hash_id}/complete")
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/maintenance/work-orders/{$wo->hash_id}/logs", [
                'description' => 'Late log entry',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
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

    public function test_stale_condition_readings_do_not_trigger_a_corrective_work_order(): void
    {
        app(SettingsService::class)->set('maintenance.predictive.max_reading_age_hours', 1, 'maintenance');

        foreach (range(1, 3) as $minute) {
            MachineConditionReading::create([
                'machine_id' => $this->machine->id,
                'metric' => 'temperature',
                'value' => 90.0,
                'unit' => 'celsius',
                'recorded_at' => now()->subHours(3)->addMinutes($minute),
                'source' => 'manual',
                'recorded_by' => $this->admin->id,
            ]);
        }

        $created = app(PredictiveMaintenanceService::class)->evaluateAllMachines($this->admin);

        $this->assertSame(0, $created);
        $this->assertSame(0, MaintenanceWorkOrder::query()
            ->where('maintainable_type', 'machine')
            ->where('maintainable_id', $this->machine->id)
            ->count());
    }

    public function test_predictive_recheck_finds_an_existing_work_order_case_insensitively(): void
    {
        foreach (range(1, 2) as $minute) {
            MachineConditionReading::create([
                'machine_id' => $this->machine->id,
                'metric' => 'temperature',
                'value' => 90.0,
                'unit' => 'celsius',
                'recorded_at' => now()->subMinutes(10 - $minute),
                'source' => 'manual',
                'recorded_by' => $this->admin->id,
            ]);
        }
        MaintenanceWorkOrder::create([
            'mwo_number' => 'MWO-PRED-'.substr(uniqid(), -5),
            'maintainable_type' => 'machine',
            'maintainable_id' => $this->machine->id,
            'type' => MaintenanceWorkOrderType::Corrective->value,
            'priority' => MaintenancePriority::High->value,
            'description' => '[pReDiCtIvE] Existing corrective order',
            'status' => MaintenanceWorkOrderStatus::Open->value,
            'created_by' => $this->admin->id,
        ]);

        $result = app(PredictiveMaintenanceService::class)->recordAndEvaluate([
            'machine_id' => $this->machine->id,
            'metric' => 'temperature',
            'value' => 92.0,
            'source' => 'manual',
        ], $this->admin);

        $this->assertFalse($result['triggered']);
        $this->assertSame(1, MaintenanceWorkOrder::query()
            ->where('maintainable_type', 'machine')
            ->where('maintainable_id', $this->machine->id)
            ->where('type', MaintenanceWorkOrderType::Corrective->value)
            ->whereIn('status', [MaintenanceWorkOrderStatus::Open->value, MaintenanceWorkOrderStatus::Assigned->value, MaintenanceWorkOrderStatus::InProgress->value])
            ->count());
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

    public function test_condition_trend_accepts_metrics_from_configuration(): void
    {
        app(SettingsService::class)->set('maintenance.predictive.metrics', [[
            'value' => 'custom_metric',
            'label' => 'Custom metric',
            'unit' => 'unit',
        ]], 'maintenance');
        MachineConditionReading::create([
            'machine_id' => $this->machine->id,
            'metric' => 'custom_metric',
            'value' => 2.5,
            'unit' => 'unit',
            'recorded_at' => now(),
            'source' => 'manual',
            'recorded_by' => $this->admin->id,
        ]);
        $request = Request::create('/api/v1/maintenance/condition-readings/trend', 'GET', [
            'machine_id' => $this->machine->hash_id,
            'metric' => 'custom_metric',
        ]);

        $response = app(MachineConditionReadingController::class)->trend($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2.5, $response->getData(true)['data'][0]['value']);
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
