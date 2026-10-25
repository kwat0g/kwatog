<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Listeners\NotifyOnWorkOrderCompleted;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $revokedPpc = $this->userWithRole('ppc_head');
        $revokedModule = $this->userWithRole('ppc_head');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revokedPpc->id,
            'permission_id' => Permission::query()->where('slug', 'production.work_orders.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $pm->id,
            'reason' => 'Work-order visibility removed for notification audience test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revokedModule->id,
            'permission_id' => Permission::query()->where('slug', 'production.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $pm->id,
            'reason' => 'Production module access removed for notification audience test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wo  = WorkOrder::factory()->create();

        $listener = app(NotifyOnWorkOrderCompleted::class);
        $listener->handle(new WorkOrderCompleted($wo));
        $listener->handle(new WorkOrderCompleted($wo));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $ppc->id,
            'type'          => 'production.wo_completed',
            'dedupe_key'    => 'production.wo_completed:'.$wo->hash_id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pm->id,
            'type'          => 'production.wo_completed',
            'dedupe_key'    => 'production.wo_completed:'.$wo->hash_id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revokedPpc->id,
            'type'          => 'production.wo_completed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revokedModule->id,
            'type'          => 'production.wo_completed',
        ]);
        $this->actingAs($revokedModule)->getJson('/api/v1/production/work-orders/'.$wo->hash_id)->assertForbidden();
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
