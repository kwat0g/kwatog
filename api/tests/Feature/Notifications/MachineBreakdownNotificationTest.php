<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Listeners\NotifyOnMachineBreakdown;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineBreakdownNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_listener_sends_to_maintenance_tech_and_production_manager(): void
    {
        $tech = $this->userWithRole('maintenance_tech');
        $pm   = $this->userWithRole('production_manager');

        $machine  = Machine::factory()->create();
        $listener = app(NotifyOnMachineBreakdown::class);
        $listener->handle(new MachineBreakdownDetected($machine, null, [], 'Hydraulic failure'));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $tech->id,
            'type'          => 'maintenance.breakdown',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pm->id,
            'type'          => 'maintenance.breakdown',
        ]);
    }

    public function test_listener_includes_reason_and_machine_info_in_message(): void
    {
        $this->userWithRole('maintenance_tech');

        $machine  = Machine::factory()->create(['machine_code' => 'MC-001', 'name' => 'Test Molder']);
        $listener = app(NotifyOnMachineBreakdown::class);
        $listener->handle(new MachineBreakdownDetected($machine, null, [], 'Oil leak'));

        $row = \DB::table('notifications')
            ->where('type', 'maintenance.breakdown')
            ->first();

        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertStringContainsString('MC-001', $data['title']);
        $this->assertStringContainsString('Test Molder', $data['message']);
        $this->assertStringContainsString('Oil leak', $data['message']);
        $this->assertStringContainsString('/maintenance/machines/', $data['link_to']);
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
