<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MaintenanceQueueFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    public function test_progress_verification_and_overdue_queues_filter_before_pagination(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle']);
        $working = $this->order($machine, 'in_progress');
        $pending = $this->order($machine, 'in_progress', true);
        $pending->update(['repair_submitted_at' => now()]);
        $schedule = MaintenanceSchedule::create([
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'description' => 'Overdue inspection', 'interval_type' => 'days', 'interval_value' => 7,
            'next_due_at' => now()->subMinute(), 'is_active' => true,
        ]);
        $overdue = $this->order($machine, 'open', false, 'preventive', $schedule->id);
        $this->order($machine, 'completed', false, 'preventive', $schedule->id);

        foreach (['in_progress' => $working, 'awaiting_verification' => $pending,
            'overdue_preventive' => $overdue] as $queue => $expected) {
            $page = app(MaintenanceWorkOrderService::class)->list(['queue' => $queue, 'per_page' => 1]);
            $this->assertSame(1, $page->total(), $queue);
            $this->assertSame($expected->id, $page->items()[0]->id, $queue);
        }
    }

    public function test_production_blocker_queue_includes_current_owner_and_reservation_only(): void
    {
        $running = Machine::factory()->create(['status' => 'running']);
        $owned = $this->order($running);
        $reservedMachine = Machine::factory()->create(['status' => 'idle']);
        $reserved = $this->order($reservedMachine);
        $production = WorkOrder::factory()->create();
        ProductionSchedule::create([
            'work_order_id' => $production->id, 'machine_id' => $reservedMachine->id,
            'mold_id' => $this->mold()->id, 'scheduled_start' => now()->subMinute(),
            'scheduled_end' => now()->addHour(), 'status' => 'executed',
            'reserves_capacity' => true, 'priority_order' => 1,
        ]);
        $inUseMold = $this->mold();
        $inUseMold->update(['status' => 'in_use', 'current_work_order_id' => $production->id]);
        $moldOrder = $this->order($inUseMold);
        $this->order(Machine::factory()->create(['status' => 'idle']));
        $this->order($running, 'completed');

        $page = app(MaintenanceWorkOrderService::class)->list(['queue' => 'blocked_by_production', 'per_page' => 2]);
        $this->assertSame(3, $page->total());
        $ids = collect($page->items())->pluck('id')->all();
        $this->assertContains($moldOrder->id, $ids);
        $this->assertContains($reserved->id, $ids);
        $this->assertNotContains($owned->id, $ids);
    }

    public function test_production_blocker_queue_uses_active_production_resource_links_when_owner_fields_are_stale(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => null]);
        $mold = $this->mold();
        $production = WorkOrder::factory()->create([
            'status' => 'in_progress', 'machine_id' => $machine->id, 'mold_id' => $mold->id,
        ]);
        $machineOrder = $this->order($machine);
        $moldOrder = $this->order($mold);

        $page = app(MaintenanceWorkOrderService::class)->list(['queue' => 'blocked_by_production', 'per_page' => 10]);
        $ids = collect($page->items())->pluck('id')->all();
        $this->assertSame(2, $page->total());
        $this->assertContains($machineOrder->id, $ids);
        $this->assertContains($moldOrder->id, $ids);
        $this->assertNull($machine->fresh()->current_work_order_id);
        $this->assertNull($mold->fresh()->current_work_order_id);
        $this->assertSame('in_progress', $production->fresh()->status->value);
    }

    private function order(Machine|Mold $target, string $status = 'open', bool $verification = false,
        string $type = 'corrective', ?int $scheduleId = null): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'mwo_number' => 'QUEUE-'.uniqid(),
            'maintainable_type' => $target instanceof Mold ? 'mold' : 'machine',
            'maintainable_id' => $target->id, 'schedule_id' => $scheduleId,
            'type' => $type, 'priority' => 'medium', 'description' => 'Queue filter test',
            'status' => $status, 'verification_required' => $verification,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function mold(): Mold
    {
        return Mold::create([
            'product_id' => Product::factory()->create()->id, 'mold_code' => 'QUEUE-'.uniqid(),
            'name' => 'Queue mold', 'cavity_count' => 1, 'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100, 'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000, 'status' => 'available',
        ]);
    }
}
