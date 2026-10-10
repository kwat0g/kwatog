<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\MachineDowntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MaintenanceRepeatBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_distinct_breakdown_incidents_notify_once_per_threshold_crossing(): void
    {
        Event::fake();
        $this->travelTo(now()->startOfMinute());
        $role = Role::firstOrCreate(['slug' => 'maintenance_supervisor'], ['name' => 'Maintenance Supervisor']);
        $actor = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $machine = Machine::factory()->create();
        $order = MaintenanceWorkOrder::create(['mwo_number' => 'REPEAT-1', 'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id, 'type' => 'corrective', 'priority' => 'medium',
            'description' => 'Repeated incident test', 'status' => 'open', 'created_by' => $actor->id]);
        $incident = fn ($at, $linked = true) => MachineDowntime::create(['machine_id' => $machine->id,
            'maintenance_order_id' => $linked ? $order->id : null, 'category' => 'breakdown', 'start_time' => $at]);
        $incident(now()->subDays(10));
        $incident(now()->subDays(5));
        $incident(now()->subDay(), false);
        $this->artisan('maintenance:check-repeat-breakdowns')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications')->where('type', 'maintenance.repeat_breakdown')->count());
        $incident(now()->subDays(5));
        $this->artisan('maintenance:check-repeat-breakdowns')->assertSuccessful();
        $this->artisan('maintenance:check-repeat-breakdowns')->assertSuccessful();
        $incident(now()->addMinute());
        $this->travel(2)->minutes();
        $this->artisan('maintenance:check-repeat-breakdowns')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications')->where('type', 'maintenance.repeat_breakdown')->count());
        $this->travel(31)->days();
        foreach ([2, 1, 0] as $days) {
            $incident(now()->subDays($days));
        }
        $this->artisan('maintenance:check-repeat-breakdowns')->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->where('type', 'maintenance.repeat_breakdown')->count());
    }
}
