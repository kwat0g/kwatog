<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Regression guards for the Auth module's three security mechanisms:
 * lockout (5 strikes / 15 min), password history (depth 3), and the
 * 90-day expiry middleware. Exercised through the real HTTP endpoints
 * — no AuthService mocking — so the assertions cover routing,
 * middleware, controllers, and persistence in one shot.
 */
class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Make sure throttle counters from prior tests can't leak in. The
        // `auth` limiter keys on IP|email; clear the umbrella `api` bucket
        // too — it keys by ip when there's no authenticated user.
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    private function makeUser(string $password = 'CorrectHorse-1!', array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id'               => Role::where('slug', 'system_admin')->value('id'),
            'email'                 => 'auth+'.uniqid().'@t.test',
            'password'              => Hash::make($password),
            'password_changed_at'   => now(),
            'is_active'             => true,
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'must_change_password'  => false,
        ], $overrides));
    }

    private function clearAuthThrottle(string $email): void
    {
        // Laravel's ThrottleRequests middleware stores named-limiter buckets
        // under md5($limiterName . $limit->key). Our 'auth' limiter keys by
        // ip().'|'.email (see bootstrap/app.php), so the real cache key is
        // md5('auth' . '127.0.0.1|' . $email). Clear both 'auth' and the
        // umbrella 'api' bucket (which keys by ip in unauth'd tests).
        RateLimiter::clear(md5('auth127.0.0.1|'.$email));
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    /**
     * Login through the real HTTP stack with session + CSRF wiring already
     * primed. Sanctum's stateful middleware needs an Origin header before it
     * mounts StartSession/VerifyCsrfToken; once mounted, VerifyCsrfToken
     * compares X-CSRF-TOKEN to the session `_token`. We seed both to a known
     * value so the request gets past CSRF and exercises AuthService end-to-end.
     */
    private function postLogin(string $email, string $password)
    {
        $token = 'test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->withHeaders([
                'Origin'       => 'http://localhost',
                'X-CSRF-TOKEN' => $token,
            ])
            ->postJson('/api/v1/auth/login', [
                'email'    => $email,
                'password' => $password,
            ]);
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $password = 'CorrectHorse-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        $response = $this->postLogin($user->email, $password);

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_login_fails_with_invalid_password_increments_counter(): void
    {
        $user = $this->makeUser('CorrectHorse-1!');
        $this->clearAuthThrottle($user->email);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email'    => $user->email,
                'password' => 'wrong-password-'.$i,
            ])->assertStatus(422);
        }

        $fresh = $user->fresh();
        $this->assertSame(3, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_lockout_triggers_after_five_failed_attempts(): void
    {
        $password = 'CorrectHorse-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        // Five wrong attempts — each must fail with 422 and the fifth must
        // also stamp `locked_until`. Throttle ceiling is 5/min for the same
        // IP+email pair, so this fits inside the budget exactly.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email'    => $user->email,
                'password' => 'wrong-password-'.$i,
            ])->assertStatus(422);
        }

        $fresh = $user->fresh();
        $this->assertSame(5, (int) $fresh->failed_login_attempts);
        $this->assertNotNull($fresh->locked_until);
        $this->assertTrue($fresh->locked_until->isFuture());

        // The 6th attempt would normally hit `throttle:auth` (also 5/min).
        // Reset the throttle bucket so we can isolate the lockout gate —
        // the user's `locked_until` is what we want to assert here, not
        // the rate limiter, which is covered separately below.
        $this->clearAuthThrottle($user->email);

        // Even with the CORRECT password, the lockout gate must fire.
        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => $password,
        ])->assertStatus(423);
    }

    public function test_successful_login_resets_failed_counter(): void
    {
        $password = 'CorrectHorse-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email'    => $user->email,
                'password' => 'wrong-pwd-'.$i,
            ])->assertStatus(422);
        }
        $this->assertSame(3, (int) $user->fresh()->failed_login_attempts);

        $this->postLogin($user->email, $password)->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_change_password_rejects_password_used_in_history(): void
    {
        $original = 'Original-1!';
        $user = $this->makeUser($original);

        // P1 -> P2 (history now: [P1])
        $this->actingAs($user)
            ->postJson('/api/v1/auth/change-password', [
                'current_password'           => $original,
                'new_password'               => 'Second-2!',
                'new_password_confirmation'  => 'Second-2!',
            ])->assertOk();

        // P2 -> P3 (history now: [P2, P1])
        $this->actingAs($user->fresh())
            ->postJson('/api/v1/auth/change-password', [
                'current_password'           => 'Second-2!',
                'new_password'               => 'Third-3!',
                'new_password_confirmation'  => 'Third-3!',
            ])->assertOk();

        // Try to flip back to the original — still inside depth 3 (P1 is row 2 of 2).
        $this->actingAs($user->fresh())
            ->postJson('/api/v1/auth/change-password', [
                'current_password'           => 'Third-3!',
                'new_password'               => $original,
                'new_password_confirmation'  => $original,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('new_password');
    }

    public function test_change_password_history_depth_is_three(): void
    {
        // Walking the full window: P1 -> P2 -> P3 -> P4 -> P5. After the 4
        // changes, history holds the 3 most recent OLD hashes (P4, P3, P2).
        // P1 has fallen off the depth-3 window, so reusing it must succeed.
        $user = $this->makeUser('P1ssword-1!');

        $cycle = [
            ['P1ssword-1!', 'P2ssword-2!'],
            ['P2ssword-2!', 'P3ssword-3!'],
            ['P3ssword-3!', 'P4ssword-4!'],
            ['P4ssword-4!', 'P5ssword-5!'],
        ];
        foreach ($cycle as [$current, $next]) {
            $this->actingAs($user->fresh())
                ->postJson('/api/v1/auth/change-password', [
                    'current_password'           => $current,
                    'new_password'               => $next,
                    'new_password_confirmation'  => $next,
                ])->assertOk();
        }

        // History should be trimmed to exactly 3 rows.
        $this->assertSame(3, PasswordHistory::where('user_id', $user->id)->count());

        // Now reuse the original — it's no longer in the trimmed history.
        $this->actingAs($user->fresh())
            ->postJson('/api/v1/auth/change-password', [
                'current_password'           => 'P5ssword-5!',
                'new_password'               => 'P1ssword-1!',
                'new_password_confirmation'  => 'P1ssword-1!',
            ])->assertOk();
    }

    public function test_password_expiry_blocks_protected_routes_after_ninety_days(): void
    {
        $user = $this->makeUser('CorrectHorse-1!', [
            'password_changed_at' => now()->subDays(91),
        ]);

        // /auth/user is intentionally exempted — must still answer.
        $this->actingAs($user->fresh())
            ->getJson('/api/v1/auth/user')
            ->assertOk();

        // /notifications goes through `password.expired`. system_admin
        // satisfies the permission check, so a 403 here is the expiry
        // middleware speaking, not authorization.
        $this->actingAs($user->fresh())
            ->getJson('/api/v1/notifications')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_expired');
    }

    public function test_password_expiry_does_not_block_within_ninety_days(): void
    {
        $user = $this->makeUser('CorrectHorse-1!', [
            'password_changed_at' => now()->subDays(89),
        ]);

        $this->actingAs($user->fresh())
            ->getJson('/api/v1/notifications')
            ->assertOk();
    }

    public function test_login_throttle_blocks_after_five_attempts_per_minute_per_email(): void
    {
        $user = $this->makeUser('CorrectHorse-1!');
        $this->clearAuthThrottle($user->email);

        // First 5 hits go through (and each lands on AuthService::login,
        // which itself rejects with 422). The 6th must short-circuit at
        // `throttle:auth` with 429 — that's the contract under test.
        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->postJson('/api/v1/auth/login', [
                'email'    => $user->email,
                'password' => 'wrong-pwd-'.$i,
            ])->getStatusCode();
        }

        $this->assertCount(5, array_filter($statuses, fn ($s) => $s === 422),
            'First 5 attempts should reach the controller (422). Got: '.implode(',', $statuses));
        $this->assertSame(429, $statuses[5],
            'Sixth attempt should be throttled (429). Got: '.implode(',', $statuses));
    }
}
