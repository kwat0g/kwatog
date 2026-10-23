<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Listeners\NotifyOnWorkOrderPaused;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderPausedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_pause_notifies_ppc_head_and_production_manager(): void
    {
        $ppc = $this->userWithRole('ppc_head');
        $pm = $this->userWithRole('production_manager');
        $revokedPpc = $this->userWithRole('ppc_head');
        $this->revoke($revokedPpc, 'production.work_orders.view', $pm);
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused->value]);

        app(NotifyOnWorkOrderPaused::class)->handle(
            new WorkOrderStatusChanged($wo, 'in_progress', 'paused', 'Material shortage'),
        );

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $ppc->id, 'type' => 'production.wo_paused']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $pm->id, 'type' => 'production.wo_paused']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $revokedPpc->id, 'type' => 'production.wo_paused']);
        $this->actingAs($pm)->getJson('/api/v1/production/work-orders/'.$wo->hash_id)->assertOk();
        $this->actingAs($revokedPpc)->getJson('/api/v1/production/work-orders/'.$wo->hash_id)->assertForbidden();
    }

    public function test_message_names_the_work_order_and_reason(): void
    {
        $this->userWithRole('ppc_head');
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused->value]);

        app(NotifyOnWorkOrderPaused::class)->handle(
            new WorkOrderStatusChanged($wo, 'in_progress', 'paused', 'Material shortage'),
        );

        $row = DB::table('notifications')->where('type', 'production.wo_paused')->first();
        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertStringContainsString($wo->wo_number, $data['title']);
        $this->assertStringContainsString('Material shortage', $data['message']);
    }

    public function test_non_pause_transitions_and_breakdown_pauses_do_not_notify(): void
    {
        $pm = $this->userWithRole('production_manager');
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused->value]);
        MachineDowntime::create([
            'machine_id' => Machine::factory()->create()->id,
            'work_order_id' => $wo->id,
            'start_time' => now(),
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Breakdown',
        ]);

        $listener = app(NotifyOnWorkOrderPaused::class);
        $listener->handle(new WorkOrderStatusChanged($wo, 'paused', 'in_progress', null));
        $listener->handle(new WorkOrderStatusChanged($wo, 'in_progress', 'paused', 'Machine breakdown'));

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $pm->id)->count());
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function revoke(User $user, string $permission, User $grantedBy): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => Permission::query()->where('slug', $permission)->value('id'),
            'type' => 'revoke',
            'granted_by' => $grantedBy->id,
            'reason' => 'Work-order visibility removed for notification audience test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
