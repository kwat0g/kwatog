<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Services\MachineService;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MachineRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_machine_change_refreshes_assigned_work_order_even_without_active_crew(): void
    {
        $machine = Machine::factory()->create();
        $workOrder = WorkOrder::factory()->create(['machine_id' => $machine->id]);
        DB::table('event_outbox')->delete();

        $machine->update(['status' => 'maintenance']);

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\AssignedWorkOrderChanged')
            ->get();

        $this->assertCount(1, $messages, 'An equipment change affects work-order readiness without requiring a crew assignment.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
    }

    public function test_machine_create_and_update_coalesce_one_refresh_signal(): void
    {
        DB::transaction(function (): void {
            $machine = Machine::factory()->create();
            $machine->update(['name' => 'Updated realtime machine']);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\MachineChanged')
            ->get();

        $this->assertCount(1, $messages, 'Machine writes must stage one final refresh per record and transaction.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('updated', $payload['action']);
        $this->assertNotEmpty($payload['machineHashId']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);
    }

    public function test_machine_archive_and_restore_each_stage_one_refresh_signal(): void
    {
        $machine = Machine::factory()->create();
        DB::table('event_outbox')->delete();

        app(MachineService::class)->delete($machine);

        $deletedMessages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\MachineChanged')
            ->get();
        $this->assertCount(1, $deletedMessages);
        $this->assertSame('deleted', json_decode((string) $deletedMessages->first()->payload, true, flags: JSON_THROW_ON_ERROR)['action']);

        DB::table('event_outbox')->delete();
        app(MachineService::class)->restore($machine);

        $restoredMessages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\MachineChanged')
            ->get();
        $this->assertCount(1, $restoredMessages, 'Restore fires both updated and restored model callbacks.');
        $this->assertSame('restored', json_decode((string) $restoredMessages->first()->payload, true, flags: JSON_THROW_ON_ERROR)['action']);
    }
}
