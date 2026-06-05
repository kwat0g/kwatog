<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Listeners\NotifyOnWorkOrderCompleted;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_listener_sends_to_ppc_and_production_manager(): void
    {
        $ppc = $this->userWithRole('ppc_head');
        $pm  = $this->userWithRole('production_manager');
        $wo  = WorkOrder::factory()->create();

        $listener = app(NotifyOnWorkOrderCompleted::class);
        $listener->handle(new WorkOrderCompleted($wo));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $ppc->id,
            'type'          => 'production.wo_completed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pm->id,
            'type'          => 'production.wo_completed',
        ]);
    }

    public function test_listener_class_exists(): void
    {
        $this->assertTrue(
            class_exists(NotifyOnWorkOrderCompleted::class),
            'Listener class NotifyOnWorkOrderCompleted should exist',
        );
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
