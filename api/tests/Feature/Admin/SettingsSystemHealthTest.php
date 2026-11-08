<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsSystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_system_health_is_returned_only_to_settings_administrators(): void
    {
        $response = $this->actingAs($this->systemAdmin())
            ->getJson('/api/v1/admin/system-health')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'reverb_tcp_status',
                    'realtime' => [
                        'delivery_status',
                        'broadcast_queue_pending',
                        'outbox_pending',
                        'outbox_failed',
                        'oldest_pending_seconds',
                    ],
                    'redis_cache' => ['status', 'used_memory_bytes', 'max_memory_bytes'],
                    'redis_persistent' => ['status', 'used_memory_bytes', 'max_memory_bytes'],
                    'cache_audit' => [
                        'status',
                        'completed_at',
                        'entries',
                        'without_ttl',
                        'oversized',
                        'unknown_size',
                    ],
                ],
            ]);
        $cacheControl = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        $this->assertContains('private', $cacheControl);
        $this->assertContains('no-store', $cacheControl);
        $response->assertHeader('Vary', 'Cookie, Authorization');

        $role = Role::create([
            'name' => 'No settings health access',
            'slug' => 'no_settings_health_'.substr(uniqid(), -6),
            'description' => 'Test role',
            'is_system' => false,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'health+'.uniqid().'@test.local',
        ]);

        $this->actingAs($user)->getJson('/api/v1/admin/system-health')->assertForbidden();
    }

    private function systemAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'health-admin+'.uniqid().'@test.local',
        ]);
    }
}
