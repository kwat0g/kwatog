<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenanceScheduleInterval;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            'maintainable_id'   => 1,
            'schedule_type'     => 'preventive',
            'description'       => 'Monthly PM',
            'interval_type'     => MaintenanceScheduleInterval::Days->value,
            'interval_value'    => 30,
            'is_active'         => true,
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
}
