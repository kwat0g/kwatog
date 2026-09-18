<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\CRM\Models\Product;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenanceScheduleInterval;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Database\Seeders\MachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P01-01 shape on P73 (preventive maintenance generation): recomputeNextDueAt is
 * an unlocked read-modify-write of last_performed_at / next_due_at called from
 * the (already hardened) WO complete/cancel transactions. Two completions for
 * the same schedule read the same stale row; the older completion's write can
 * regress last_performed_at after the newer one already committed, re-scheduling
 * the next PM too early.
 */
class MaintenanceScheduleRecomputeRaceTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(): MaintenanceSchedule
    {
        return MaintenanceSchedule::create([
            'maintainable_type' => MaintainableType::Machine->value,
            'maintainable_id' => 1,
            'schedule_type' => 'preventive',
            'description' => 'Monthly PM',
            'interval_type' => MaintenanceScheduleInterval::Days->value,
            'interval_value' => 30,
            'is_active' => true,
        ]);
    }

    public function test_older_completion_cannot_regress_last_performed_at(): void
    {
        $schedule = $this->schedule();
        $svc = app(MaintenanceScheduleService::class);

        // Both "concurrent" completions fetched the schedule before either ran.
        $completionA = MaintenanceSchedule::find($schedule->id);
        $completionB = MaintenanceSchedule::find($schedule->id);

        // Newer completion commits first.
        $svc->recomputeNextDueAt($completionA, Carbon::parse('2026-08-13 10:00:00'));

        // Older completion lands afterwards with the stale snapshot.
        $svc->recomputeNextDueAt($completionB, Carbon::parse('2026-08-12 10:00:00'));

        $fresh = $schedule->refresh();

        $this->assertTrue(
            $fresh->last_performed_at->eq(Carbon::parse('2026-08-13 10:00:00')),
            'last_performed_at must reflect the newer completion, not regress.'
        );
        $this->assertSame('2026-09-12', $fresh->next_due_at->toDateString());
    }

    public function test_machine_hour_schedule_uses_runtime_since_persisted_baseline(): void
    {
        $this->seed(MachineSeeder::class);
        $machine = Machine::query()->firstOrFail();
        $machine->forceFill(['running_hours_total' => '100.00'])->save();

        $svc = app(MaintenanceScheduleService::class);
        $schedule = $svc->create([
            'maintainable_type' => MaintainableType::Machine->value,
            'maintainable_id' => $machine->id,
            'description' => 'Runtime PM',
            'interval_type' => MaintenanceScheduleInterval::Hours->value,
            'interval_value' => 10,
            'is_active' => true,
        ]);

        $this->assertSame('100.00', (string) $schedule->refresh()->running_hours_baseline);
        $this->assertNull($schedule->next_due_at);

        $machine->forceFill(['running_hours_total' => '109.99'])->save();
        $this->assertCount(0, $svc->machineHourSchedulesAtOrAboveThreshold());

        $machine->forceFill(['running_hours_total' => '110.00'])->save();
        $this->assertCount(1, $svc->machineHourSchedulesAtOrAboveThreshold());

        $svc->recomputeNextDueAt($schedule, Carbon::parse('2026-08-25 10:00:00'));
        $fresh = $schedule->refresh();
        $this->assertSame('110.00', (string) $fresh->running_hours_baseline);
        $this->assertNull($fresh->next_due_at);

        $machine->forceFill(['running_hours_total' => '119.99'])->save();
        $this->assertCount(0, $svc->machineHourSchedulesAtOrAboveThreshold());
    }

    public function test_null_machine_hour_baseline_is_initialized_to_current_runtime(): void
    {
        $this->seed(MachineSeeder::class);
        $machine = Machine::query()->firstOrFail();
        $machine->forceFill(['running_hours_total' => '77.50'])->save();
        $schedule = MaintenanceSchedule::create([
            'maintainable_type' => MaintainableType::Machine->value,
            'maintainable_id' => $machine->id,
            'schedule_type' => 'preventive',
            'description' => 'Legacy runtime PM',
            'interval_type' => MaintenanceScheduleInterval::Hours->value,
            'interval_value' => 10,
            'running_hours_baseline' => null,
            'is_active' => true,
        ]);

        $this->assertCount(0, app(MaintenanceScheduleService::class)->machineHourSchedulesAtOrAboveThreshold());
        $this->assertSame('77.50', (string) $schedule->refresh()->running_hours_baseline);
    }

    public function test_mold_hour_schedule_is_rejected_without_mold_runtime_semantics(): void
    {
        $mold = Mold::create([
            'mold_code' => 'M-'.substr(uniqid(), -6),
            'name' => 'Runtime test mold',
            'product_id' => Product::factory()->create()->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 10,
            'output_rate_per_hour' => 300,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);

        $this->expectException(ValidationException::class);
        app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => MaintainableType::Mold->value,
            'maintainable_id' => $mold->id,
            'description' => 'Invalid mold hour PM',
            'interval_type' => MaintenanceScheduleInterval::Hours->value,
            'interval_value' => 10,
            'is_active' => true,
        ]);
    }
}
