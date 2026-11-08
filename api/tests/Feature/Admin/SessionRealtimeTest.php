<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Events\AdminSessionsChanged;
use App\Modules\Admin\Events\UserAccessChanged;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost', 'localhost:5173']]);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_terminating_a_session_stages_a_private_refresh_without_the_session_id(): void
    {
        Queue::fake();
        $admin = $this->sessionAdministrator();
        $target = User::factory()->create();
        $sessionId = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $target->id,
            'ip_address' => '192.0.2.5',
            'user_agent' => 'Session realtime contract',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->withSession(['_token' => 'session-realtime-test-token'])
            ->withHeaders([
                'Origin' => 'http://localhost:5173',
                'Referer' => 'http://localhost:5173/',
                'X-CSRF-TOKEN' => 'session-realtime-test-token',
            ])
            ->actingAs($admin)
            ->deleteJson('/api/v1/admin/sessions/'.$sessionId)
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $outbox = DB::table('event_outbox')
            ->where('event_type', AdminSessionsChanged::class)
            ->sole();

        $this->assertStringNotContainsString($sessionId, (string) $outbox->payload);
        $this->assertSame('deleted', json_decode((string) $outbox->payload, true, flags: JSON_THROW_ON_ERROR)['action']);

        $accessRefresh = DB::table('event_outbox')
            ->where('event_type', UserAccessChanged::class)
            ->sole();
        $this->assertStringNotContainsString($sessionId, (string) $accessRefresh->payload);
        $this->assertSame(
            [$target->hash_id],
            json_decode((string) $accessRefresh->payload, true, flags: JSON_THROW_ON_ERROR)['userHashIds'],
        );
    }

    private function sessionAdministrator(): User
    {
        $role = Role::create([
            'name' => 'Session realtime administrator',
            'slug' => 'session_realtime_'.substr(uniqid(), -6),
            'description' => 'Test role',
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()
            ->where('slug', 'admin.settings.manage')
            ->pluck('id')
            ->all());

        return User::factory()->create(['role_id' => $role->id]);
    }
}
