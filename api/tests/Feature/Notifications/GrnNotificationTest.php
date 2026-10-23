<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Listeners\NotifyOnGrnReceived;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrnNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_listener_sends_to_purchasing_officer(): void
    {
        $po = $this->userWithRole('purchasing_officer');

        $grn = GoodsReceiptNote::factory()->create();

        $listener = app(NotifyOnGrnReceived::class);
        $listener->handle(new GoodsReceiptNoteCreated($grn));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $po->id,
            'type' => 'inventory.grn_received',
        ]);
    }

    public function test_listener_does_not_notify_inactive_users(): void
    {
        $inactive = $this->userWithRole('purchasing_officer', is_active: false);

        $grn = GoodsReceiptNote::factory()->create();

        $listener = app(NotifyOnGrnReceived::class);
        $listener->handle(new GoodsReceiptNoteCreated($grn));

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $inactive->id,
            'type' => 'inventory.grn_received',
        ]);
    }

    public function test_listener_excludes_role_user_without_grn_view_permission(): void
    {
        $purchasing = $this->userWithRole('purchasing_officer');
        $admin = $this->userWithRole('system_admin');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $purchasing->id,
            'permission_id' => DB::table('permissions')->where('slug', 'inventory.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $admin->id,
            'reason' => 'Audience regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchasing->flushPermissionsCache();

        app(NotifyOnGrnReceived::class)->handle(new GoodsReceiptNoteCreated(GoodsReceiptNote::factory()->create()));

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $purchasing->id,
            'type' => 'inventory.grn_received',
        ]);
    }

    public function test_delayed_grn_notification_does_not_claim_qc_is_still_in_progress(): void
    {
        $purchasing = $this->userWithRole('purchasing_officer');
        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted']);

        app(NotifyOnGrnReceived::class)->handle(new GoodsReceiptNoteCreated($grn));

        $notification = DB::table('notifications')
            ->where('notifiable_id', $purchasing->id)
            ->where('type', 'inventory.grn_received')
            ->firstOrFail();
        $data = json_decode($notification->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Goods received against', $data['message']);
        $this->assertStringNotContainsString('in progress', strtolower($data['message']));
    }

    public function test_listener_class_exists(): void
    {
        $this->assertTrue(
            class_exists(NotifyOnGrnReceived::class),
            'Listener class NotifyOnGrnReceived should exist',
        );
    }

    private function userWithRole(string $slug, bool $is_active = true): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => $is_active]);
    }
}
