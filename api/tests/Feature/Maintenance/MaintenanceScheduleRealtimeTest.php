<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Common\Models\OutboxMessage;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\MRP\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceScheduleRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_create_update_archive_and_restore_coalesce_to_one_refresh(): void
    {
        $machine = Machine::factory()->create();

        DB::transaction(function () use ($machine): void {
            $schedule = MaintenanceSchedule::query()->create([
                'maintainable_type' => 'machine',
                'maintainable_id' => $machine->id,
                'schedule_type' => 'preventive',
                'description' => 'Realtime maintenance schedule',
                'interval_type' => 'days',
                'interval_value' => 30,
                'estimated_duration_minutes' => 60,
                'is_active' => true,
            ]);
            $schedule->update(['description' => 'Updated maintenance schedule']);
            $schedule->delete();
            $schedule->restore();
        });

        $messages = OutboxMessage::query()
            ->where('event_type', 'App\\Modules\\Maintenance\\Events\\MaintenanceScheduleChanged')
            ->get();

        $this->assertCount(1, $messages);
        $this->assertSame('pending', $messages->first()->status);
        $this->assertSame('updated', $messages->first()->payload['action']);
        $this->assertArrayHasKey('hashId', $messages->first()->payload);
    }
}
