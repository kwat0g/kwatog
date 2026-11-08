<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Models\AuditLog;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Admin\Events\AdminAuditLogsChanged;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_audit_log_appends_coalesce_into_a_content_free_refresh_signal(): void
    {
        AuditLog::create([
            'action' => 'created',
            'model_type' => 'App\\Modules\\CRM\\Models\\SalesOrder',
            'model_id' => 1001,
            'new_values' => ['customer' => 'Confidential customer'],
            'created_at' => now(),
        ]);
        AuditLog::create([
            'action' => 'updated',
            'model_type' => 'App\\Modules\\CRM\\Models\\SalesOrder',
            'model_id' => 1002,
            'old_values' => ['price' => 100],
            'created_at' => now(),
        ]);

        $message = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Admin\\Events\\AdminAuditLogsChanged')
            ->sole();

        $payload = (string) $message->payload;
        $this->assertStringNotContainsString('1001', $payload);
        $this->assertStringNotContainsString('Confidential customer', $payload);
        $this->assertStringNotContainsString('price', $payload);
        $stored = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $stored);

        $this->assertInstanceOf(AdminAuditLogsChanged::class, $event);
        $broadcastPayload = $event->broadcastWith();
        $this->assertTrue($broadcastPayload['refresh']);
        $this->assertSame(['refresh', 'event_id', 'timestamp'], array_keys($broadcastPayload));
        $this->assertStringNotContainsString('1002', json_encode($broadcastPayload, JSON_THROW_ON_ERROR));
    }

    public function test_audit_log_channel_requires_the_same_permission_as_the_api(): void
    {
        $reader = $this->userWithPermissions('admin.audit_logs.view');
        $activityReader = $this->userWithPermissions('admin.activity.view');

        $this->assertTrue($reader->hasPermission('admin.audit_logs.view'));
        $this->assertFalse($activityReader->hasPermission('admin.audit_logs.view'));
        $this->authorizeAuditLogChannel($reader)->assertOk();
        $this->authorizeAuditLogChannel($activityReader)->assertForbidden();

        $reader->forceFill(['is_active' => false])->save();
        $this->authorizeAuditLogChannel($reader)->assertUnauthorized();
    }

    private function userWithPermissions(string $permission): User
    {
        $role = Role::create([
            'name' => 'Audit realtime '.$permission,
            'slug' => 'audit_realtime_'.str_replace('.', '_', $permission),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->where('slug', $permission)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeAuditLogChannel(User $user)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-admin.audit-logs',
        ]);
    }
}
