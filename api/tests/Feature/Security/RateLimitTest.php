<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Ensures the named API limiter is applied and that authenticated SPA traffic
 * gets a larger, independently configurable bucket than anonymous traffic.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_authenticated_api_limit_uses_configured_user_bucket(): void
    {
        config()->set('rate_limits.api_authenticated_per_minute', 5);

        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email'   => 'rl+'.uniqid().'@t.test',
        ]);

        // Clear any prior counter for this key just in case.
        RateLimiter::clear('user:'.$user->id);

        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->actingAs($user)
                ->getJson('/api/v1/alerts/unread-count')
                ->getStatusCode();
        }

        $this->assertSame([200, 200, 200, 200, 200, 429], $statuses);
    }

    public function test_guest_api_limit_remains_conservative(): void
    {
        config()->set('rate_limits.api_guest_per_minute', 3);
        RateLimiter::clear('ip:127.0.0.1');

        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->getJson('/api/v1/health')->getStatusCode();
        }

        $this->assertSame([200, 200, 200, 429], $statuses);
    }
}
