<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\MachineDowntime;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MaintenanceRepeatBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

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
        $notice = $actor->notifications()->where('type', 'maintenance.repeat_breakdown')->firstOrFail();
        $this->assertSame(Machine::class, $notice->data['subject_type']);
        $this->assertSame((string) $machine->id, $notice->data['subject_id']);
        $this->assertSame('/maintenance/downtime?search='.rawurlencode($machine->machine_code), $notice->data['link_to']);
        $this->actingAs($actor)
            ->getJson('/api/v1/maintenance/downtime-analytics/summary?search='.rawurlencode($machine->machine_code))
            ->assertOk()
            ->assertJsonPath('data.breakdown_count', 3);
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

    public function test_repeat_breakdown_notice_skips_supervisor_without_view_permission(): void
    {
        $this->travelTo(now()->startOfMinute());
        $role = Role::where('slug', 'maintenance_supervisor')->firstOrFail();
        $supervisor = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $supervisor->id,
            'permission_id' => DB::table('permissions')->where('slug', 'maintenance.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $admin->id,
            'reason' => 'Audience regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supervisor->flushPermissionsCache();

        $machine = Machine::factory()->create();
        $order = MaintenanceWorkOrder::create([
            'mwo_number' => 'REPEAT-REVOKED',
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => 'corrective',
            'priority' => 'medium',
            'description' => 'Repeated incident test',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);
        foreach ([now()->subDays(5), now()->subDay()] as $at) {
            MachineDowntime::create([
                'machine_id' => $machine->id,
                'maintenance_order_id' => $order->id,
                'category' => 'breakdown',
                'start_time' => $at,
            ]);
        }

        $this->artisan('maintenance:check-repeat-breakdowns')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $supervisor->id,
            'type' => 'maintenance.repeat_breakdown',
        ]);
    }

    public function test_repeat_breakdown_notice_is_suppressed_when_maintenance_is_disabled(): void
    {
        $this->travelTo(now()->startOfMinute());
        $role = Role::where('slug', 'maintenance_supervisor')->firstOrFail();
        $supervisor = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $machine = Machine::factory()->create();
        $order = MaintenanceWorkOrder::create([
            'mwo_number' => 'REPEAT-DISABLED',
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => 'corrective',
            'priority' => 'medium',
            'description' => 'Repeated incident test',
            'status' => 'open',
            'created_by' => $supervisor->id,
        ]);
        foreach ([now()->subDays(5), now()->subDay()] as $at) {
            MachineDowntime::create([
                'machine_id' => $machine->id,
                'maintenance_order_id' => $order->id,
                'category' => 'breakdown',
                'start_time' => $at,
            ]);
        }
        app(SettingsService::class)->set('modules.maintenance', false, 'modules');

        $this->artisan('maintenance:check-repeat-breakdowns')->assertSuccessful();

        $this->assertSame(0, DB::table('notifications')->where('type', 'maintenance.repeat_breakdown')->count());
    }
}
