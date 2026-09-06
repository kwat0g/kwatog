<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Services\OeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * MT-02 + MT-03 — MWO machine transitions must flow through the authoritative
 * MachineStatusChanged emitter, and machine-linked MWOs must open/close a
 * planned_maintenance row in the machine_downtimes ledger so maintenance time
 * reaches OEE availability and the restoration listener.
 */
class MaintenanceWorkOrderMachineDowntimeTest extends TestCase
{
    use RefreshDatabase;

    private MaintenanceWorkOrderService $svc;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(MaintenanceWorkOrderService::class);
        $this->tech = User::factory()->create(['is_active' => true]);
    }

    // ─── MT-03: ledger opens at start, closes at complete/cancel ─────

    public function test_start_opens_planned_maintenance_downtime_row(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $wo = $this->machineMwo(MaintenanceWorkOrderStatus::Open, $machine);

        $this->svc->start($wo, $this->tech);

        $this->assertSame(MachineStatus::Maintenance, $machine->fresh()->status);
        $row = MachineDowntime::query()
            ->where('machine_id', $machine->id)
            ->where('maintenance_order_id', $wo->id)
            ->firstOrFail();
        $this->assertSame(MachineDowntimeCategory::PlannedMaintenance, $row->category);
        $this->assertNull($row->end_time);
        $this->assertNull($row->duration_minutes);
    }

    public function test_complete_closes_downtime_row_with_ledger_duration(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $wo = $this->machineMwo(MaintenanceWorkOrderStatus::Open, $machine);
        $this->svc->start($wo, $this->tech);

        $row = MachineDowntime::query()->where('maintenance_order_id', $wo->id)->firstOrFail();
        $row->update(['start_time' => now()->subMinutes(40)]);

        $this->svc->complete($wo, ['downtime_minutes' => 45, 'remarks' => 'done'], $this->tech);

        $this->assertSame(MachineStatus::Idle, $machine->fresh()->status);
        $row = MachineDowntime::query()->where('maintenance_order_id', $wo->id)->firstOrFail();
        $this->assertNotNull($row->end_time);
        // Ledger-derived duration wins for the row; the tech-entered value
        // stays on the MWO.
        $this->assertSame(40, $row->duration_minutes);
        $this->assertSame(45, $wo->fresh()->downtime_minutes);
    }

    public function test_cancel_closes_downtime_row(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $wo = $this->machineMwo(MaintenanceWorkOrderStatus::Open, $machine);
        $this->svc->start($wo, $this->tech);

        $this->svc->cancel($wo, 'wrong machine', $this->tech);

        $this->assertSame(MachineStatus::Idle, $machine->fresh()->status);
        $row = MachineDowntime::query()->where('maintenance_order_id', $wo->id)->firstOrFail();
        $this->assertNotNull($row->end_time);
        $this->assertNotNull($row->duration_minutes);
    }

    public function test_cancel_before_start_never_opens_a_downtime_row(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $wo = $this->machineMwo(MaintenanceWorkOrderStatus::Open, $machine);

        $this->svc->cancel($wo, null, $this->tech);

        $this->assertSame(MachineStatus::Idle, $machine->fresh()->status);
        $this->assertSame(0, MachineDowntime::query()->where('machine_id', $machine->id)->count());
    }

    public function test_mold_mwo_does_not_touch_the_machine_downtime_ledger(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $mold = Mold::create([
            'mold_code' => 'M-'.substr(uniqid(), -6),
            'name' => 'Test mold',
            'product_id' => Product::factory()->create()->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 10,
            'output_rate_per_hour' => 300,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'current_shot_count' => 5000,
            'status' => 'in_use',
        ]);
        $wo = MaintenanceWorkOrder::create([
            'mwo_number' => 'MWO-'.substr(uniqid(), -8),
            'maintainable_type' => 'mold',
            'maintainable_id' => $mold->id,
            'type' => 'preventive',
            'description' => 'Mold PM',
            'status' => MaintenanceWorkOrderStatus::InProgress->value,
            'created_by' => $this->tech->id,
        ]);

        $this->svc->complete($wo, [], $this->tech);

        $this->assertSame(MachineStatus::Idle, $machine->fresh()->status);
        $this->assertSame(0, MachineDowntime::query()->where('machine_id', $machine->id)->count());
    }

    // ─── MT-02: transitions emit MachineStatusChanged ────────────────

    public function test_machine_status_changed_is_dispatched_for_start_complete_and_cancel(): void
    {
        Event::fake([MachineStatusChanged::class]);

        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $wo = $this->machineMwo(MaintenanceWorkOrderStatus::Open, $machine);
        $this->svc->start($wo, $this->tech);
        $this->svc->complete($wo, [], $this->tech);

        $machine2 = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $wo2 = $this->machineMwo(MaintenanceWorkOrderStatus::Open, $machine2);
        $this->svc->start($wo2, $this->tech);
        $this->svc->cancel($wo2, null, $this->tech);

        Event::assertDispatched(MachineStatusChanged::class, fn (MachineStatusChanged $e): bool => $e->machine->id === $machine->id && $e->from === 'idle' && $e->to === 'maintenance');
        Event::assertDispatched(MachineStatusChanged::class, fn (MachineStatusChanged $e): bool => $e->machine->id === $machine->id && $e->from === 'maintenance' && $e->to === 'idle');
        Event::assertDispatched(MachineStatusChanged::class, fn (MachineStatusChanged $e): bool => $e->machine->id === $machine2->id && $e->from === 'idle' && $e->to === 'maintenance');
        Event::assertDispatched(MachineStatusChanged::class, fn (MachineStatusChanged $e): bool => $e->machine->id === $machine2->id && $e->from === 'maintenance' && $e->to === 'idle');
    }

    public function test_breakdown_downtime_row_is_closed_by_restoration_listener_after_corrective_mwo_completes(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Breakdown->value]);
        $breakdown = MachineDowntime::create([
            'machine_id' => $machine->id,
            'start_time' => now()->subMinutes(90),
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Hydraulic failure',
        ]);

        $wo = $this->machineMwo(MaintenanceWorkOrderStatus::Open, $machine);
        $this->svc->start($wo, $this->tech);
        $this->assertSame(MachineStatus::Maintenance, $machine->fresh()->status);

        $this->svc->complete($wo, ['downtime_minutes' => 30], $this->tech);

        $this->assertSame(MachineStatus::Idle, $machine->fresh()->status);

        $closedBreakdown = $breakdown->fresh();
        $this->assertNotNull($closedBreakdown->end_time);
        $this->assertGreaterThanOrEqual(90, $closedBreakdown->duration_minutes);

        $maintenanceRow = MachineDowntime::query()
            ->where('maintenance_order_id', $wo->id)
            ->firstOrFail();
        $this->assertSame(MachineDowntimeCategory::PlannedMaintenance, $maintenanceRow->category);
        $this->assertNotNull($maintenanceRow->end_time);
    }

    // ─── OEE consumes the new planned_maintenance rows ───────────────

    public function test_completed_maintenance_downtime_feeds_oee_planned_downtime(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $wo = $this->machineMwo(MaintenanceWorkOrderStatus::Open, $machine);
        $this->svc->start($wo, $this->tech);

        $row = MachineDowntime::query()->where('maintenance_order_id', $wo->id)->firstOrFail();
        $row->update(['start_time' => now()->subMinutes(30)]);

        $this->svc->complete($wo, [], $this->tech);

        $oee = app(OeeService::class)->calculate(
            $machine->fresh(),
            now()->subHours(2),
            now()->endOfDay(),
        );

        $this->assertSame(30, $oee['diagnostics']['planned_downtime']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function machineMwo(MaintenanceWorkOrderStatus $status, Machine $machine): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'mwo_number' => 'MWO-'.substr(uniqid(), -8),
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => 'corrective',
            'description' => 'Hydraulic leak',
            'status' => $status->value,
            'created_by' => $this->tech->id,
        ]);
    }
}
