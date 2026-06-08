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
 * Ensures the `api` named RateLimiter (60/min) is actually applied to the
 * API middleware group. Without the ThrottleRequests:api middleware appended
 * in bootstrap/app.php, every request under /api/v1 is unthrottled.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_api_group_throttles_after_sixty_requests_per_minute(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email'   => 'rl+'.uniqid().'@t.test',
        ]);

        // Clear any prior counter for this key just in case.
        RateLimiter::clear('api:'.$user->id);
        RateLimiter::clear((string) $user->id);

        $last = null;
        $statuses = [];
        for ($i = 0; $i < 65; $i++) {
            $last = $this->actingAs($user)->getJson('/api/v1/alerts/unread-count');
            $statuses[] = $last->getStatusCode();
            if ($last->getStatusCode() === 429) {
                break;
            }
        }

        $this->assertSame(
            429,
            $last->getStatusCode(),
            'Expected a 429 within 65 requests but never got one. Statuses observed: '
                .implode(',', $statuses)
        );
    }
}
