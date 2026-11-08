<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BadgePrivateChannelAuthorizationTest extends TestCase
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
    }

    public function test_badge_refresh_channel_is_private_to_its_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->authorizeChannel($owner, "private-badge-user.{$owner->hash_id}")->assertOk();
        $this->authorizeChannel($other, "private-badge-user.{$owner->hash_id}")->assertForbidden();
    }

    private function authorizeChannel(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }
}
