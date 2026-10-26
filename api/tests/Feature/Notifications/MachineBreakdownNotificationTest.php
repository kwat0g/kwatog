<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Listeners\NotifyOnMachineBreakdown;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MachineBreakdownNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_listener_sends_to_maintenance_tech_and_production_manager(): void
    {
        $tech = $this->userWithRole('maintenance_tech');
        $pm = $this->userWithRole('production_manager');
        $revokedTech = $this->userWithRole('maintenance_tech');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revokedTech->id,
            'permission_id' => Permission::query()->where('slug', 'mrp.machines.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $pm->id,
            'reason' => 'Machine visibility removed for notification audience test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $machine = Machine::factory()->create();
        $listener = app(NotifyOnMachineBreakdown::class);
        $listener->handle(new MachineBreakdownDetected($machine, null, [], 'Hydraulic failure'));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $tech->id,
            'type' => 'maintenance.breakdown',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pm->id,
            'type' => 'maintenance.breakdown',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revokedTech->id,
            'type' => 'maintenance.breakdown',
        ]);
        $notice = $tech->notifications()->where('type', 'maintenance.breakdown')->firstOrFail();
        $this->assertSame(Machine::class, $notice->data['subject_type']);
        $this->assertSame((string) $machine->id, $notice->data['subject_id']);
        $this->actingAs($pm)->getJson('/api/v1/mrp/machines/'.$machine->hash_id)->assertOk();
        $this->actingAs($revokedTech)->getJson('/api/v1/mrp/machines/'.$machine->hash_id)->assertForbidden();

        app(SettingsService::class)->set('modules.mrp', false, 'modules');
        app(NotificationService::class)->sendForSubject($machine, $pm, 'test.machine_module_disabled', [
            'title' => 'Machine down',
            'link_to' => "/mrp/machines/{$machine->hash_id}",
        ], emailEnabled: false);
        $this->assertSame(0, $pm->notifications()->where('type', 'test.machine_module_disabled')->count());
    }

    public function test_listener_includes_reason_and_machine_info_in_message(): void
    {
        $this->userWithRole('maintenance_tech');

        $machine = Machine::factory()->create(['machine_code' => 'MC-001', 'name' => 'Test Molder']);
        $listener = app(NotifyOnMachineBreakdown::class);
        $listener->handle(new MachineBreakdownDetected($machine, null, [], 'Oil leak'));

        $row = DB::table('notifications')
            ->where('type', 'maintenance.breakdown')
            ->first();

        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertStringContainsString('MC-001', $data['title']);
        $this->assertStringContainsString('Test Molder', $data['message']);
        $this->assertStringContainsString('Oil leak', $data['message']);
        $this->assertSame("/mrp/machines/{$machine->hash_id}", $data['link_to']);
    }

    public function test_listener_class_exists(): void
    {
        $this->assertTrue(
            class_exists(NotifyOnMachineBreakdown::class),
            'Listener class NotifyOnMachineBreakdown should exist',
        );
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
