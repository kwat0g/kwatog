<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WoOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WoOperationScheduleProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_operations_use_slot_and_started_operations_keep_history(): void
    {
        $wo = WorkOrder::factory()->create(['planned_start' => now(), 'planned_end' => now()->addDays(10)]);
        $routing = ProductRouting::create(['product_id' => $wo->product_id, 'version' => 1, 'is_active' => true]);
        foreach ([1, 2] as $sequence) {
            RoutingOperation::create(['routing_id' => $routing->id, 'sequence' => $sequence,
                'operation_name' => 'Step '.$sequence, 'setup_time_minutes' => 10, 'cycle_time_minutes' => 10]);
        }
        $wo->update(['machine_id' => Machine::factory()->create()->id, 'mold_id' => Mold::create(['mold_code' => 'PROJECTION-MOLD', 'name' => 'Projection mold', 'product_id' => $wo->product_id, 'status' => 'available', 'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'max_shots_before_maintenance' => 10000, 'lifetime_max_shots' => 100000])->id]);
        $start = now()->addDay()->startOfHour();
        $slot = ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $wo->machine_id,
            'mold_id' => $wo->mold_id, 'scheduled_start' => $start, 'scheduled_end' => $start->copy()->addHours(2),
            'priority_order' => 1, 'status' => 'pending', 'is_confirmed' => false]);
        $service = app(WoOperationService::class);
        $service->generateFromRouting($wo);
        [$first, $second] = $wo->operations()->orderBy('sequence')->get()->all();
        $this->assertTrue($first->planned_start->eq($start));
        $this->assertTrue($second->planned_end->eq($start->copy()->addHours(2)));
        $first->update(['status' => WoOperationStatus::InProgress, 'actual_start' => $start]);
        $original = $first->fresh()->getAttributes();
        $newStart = $start->copy()->addDay();
        $slot->update(['scheduled_start' => $newStart, 'scheduled_end' => $newStart->copy()->addHours(2)]);
        $service->refreshPendingSchedule($wo);
        $this->assertSame($original, $first->fresh()->getAttributes());
        $this->assertTrue($second->fresh()->planned_end->eq($newStart->copy()->addHours(2)));
        $this->assertSame(2, $wo->operations()->count());
    }
}
