<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\Alert;
use App\Common\Services\AlertEngineService;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MachineService;
use App\Modules\MRP\Services\MoldService;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionResourceChangeTest extends TestCase
{
    use RefreshDatabase;

    private function equipment(): array
    {
        $wo = WorkOrder::factory()->create(['status' => 'confirmed', 'quantity_target' => 10, 'planned_end' => now()->addDays(3)]);
        $machine = Machine::factory()->create(['status' => 'running', 'current_work_order_id' => $wo->id]);
        $mold = Mold::create(['mold_code' => 'CHANGE-MOLD', 'name' => 'Change mold', 'product_id' => $wo->product_id,
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'current_shot_count' => 0,
            'max_shots_before_maintenance' => 1000, 'lifetime_max_shots' => 10000, 'status' => 'in_use', 'current_work_order_id' => $wo->id]);
        $mold->compatibleMachines()->sync([$machine->id]);

        return [$wo, $machine, $mold];
    }

    public function test_installed_equipment_cannot_be_reset_or_changed_through_master_forms(): void
    {
        [$wo, $machine, $mold] = $this->equipment();
        foreach ([
            fn () => app(MachineService::class)->transitionStatus($machine, MachineStatus::Idle),
            fn () => app(MoldService::class)->commission($mold),
            fn () => app(MoldService::class)->resetShotCount($mold),
            fn () => app(MoldService::class)->syncCompatibility($mold, []),
            fn () => app(MoldService::class)->update($mold, ['output_rate_per_hour' => 10]),
        ] as $action) {
            try {
                $action();
                $this->fail('Owned equipment must not be changed.');
            } catch (BusinessRuleException) {
                $this->assertSame($wo->id, (int) $mold->fresh()->current_work_order_id);
            }
        }
        $this->assertSame(MachineStatus::Running, $machine->fresh()->status);
    }

    public function test_expired_slot_notice_deduplicates_and_does_not_rewrite_schedule(): void
    {
        [$wo, $machine, $mold] = $this->equipment();
        $slot = ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now()->subHours(2), 'scheduled_end' => now()->subHour(), 'priority_order' => 0,
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
        $this->assertTrue($slot->reserves_capacity);
        $firstRun = app(AlertEngineService::class)->runAllChecks();
        $secondRun = app(AlertEngineService::class)->runAllChecks();
        $this->assertSame(1, Alert::where('type', 'mrp_schedule_conflict')->where('entity_id', $slot->id)->whereNull('resolved_at')->count(),
            json_encode(['first_run' => $firstRun, 'second_run' => $secondRun, 'schedule' => $slot->fresh()->getAttributes()], JSON_THROW_ON_ERROR));
        $this->assertTrue($slot->fresh()->reserves_capacity);
        $this->assertSame('confirmed', $slot->fresh()->status->value);
    }
}
