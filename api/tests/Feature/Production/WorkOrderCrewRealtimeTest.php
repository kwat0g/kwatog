<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderCrewRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_crew_and_member_writes_stage_one_refresh_in_the_business_transaction(): void
    {
        $actor = User::factory()->create();
        $recorder = Employee::factory()->create();
        $member = Employee::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        DB::transaction(function () use ($actor, $recorder, $member, $workOrder): void {
            $crew = WorkOrderCrew::query()->create([
                'work_order_id' => $workOrder->id,
                'recorder_employee_id' => $recorder->id,
                'assigned_by' => $actor->id,
                'reason' => 'Initial crew assignment',
                'assigned_at' => now(),
            ]);

            $crew->members()->create([
                'employee_id' => $recorder->id,
                'employee_snapshot' => ['name' => 'Recorder'],
            ]);
            $crew->members()->create([
                'employee_id' => $member->id,
                'employee_snapshot' => ['name' => 'Member'],
            ]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\WorkOrderAssignmentChanged')
            ->get();

        $this->assertCount(1, $messages, 'Crew and member writes in one assignment must coalesce to one refresh ping.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);
    }
}
