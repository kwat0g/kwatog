<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 2 Task 15 (C-4) — Supplier Portal auth hardening.
 *
 * Exercises B2bAuthService via the real /api/v1/b2b/supplier/login endpoint:
 * lockout counter, 5-strikes-then-15min lock, throttle:auth ceiling, HashID
 * envelope on the response, and audit_logs mirror rows.
 */
class SupplierPortalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Don't let throttle counters leak between tests.
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    private function makeUser(string $password = 'SupplierPass-1!', array $overrides = []): SupplierPortalUser
    {
        $vendor = Vendor::factory()->create();

        return SupplierPortalUser::create(array_merge([
            'vendor_id'             => $vendor->id,
            'name'                  => 'Test Supplier',
            'email'                 => 'supplier+'.uniqid().'@t.test',
            'password'              => Hash::make($password),
            'is_active'             => true,
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ], $overrides));
    }

    private function clearAuthThrottle(string $email): void
    {
        RateLimiter::clear(md5('auth127.0.0.1|'.$email));
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    /**
     * Sanctum stateful request headers — login must run through the stateful
     * middleware stack (StartSession) so the session guard can persist the
     * authenticated principal, exactly like the customer portal login.
     */
    private function withPortalHeaders(): self
    {
        $csrf = 'supplier-portal-test-csrf';

        return $this->withSession(['_token' => $csrf])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => $csrf,
            ]);
    }

    private function postLogin(string $email, string $password)
    {
        return $this->withPortalHeaders()->postJson('/api/v1/b2b/supplier/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_login_succeeds_returns_hashids_and_no_token(): void
    {
        $password = 'SupplierPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        $response = $this->postLogin($user->email, $password);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'vendor_id', 'must_change_password']]])
            ->assertJsonMissingPath('data.token');

        $payload = $response->json('data');

        // The HTTP-only session cookie replaces the bearer credential.
        $response->assertCookieNotExpired(config('session.cookie'));
        $this->assertSame(0, $user->tokens()->count());

        // user.id must be a HashID (alphanumeric string, not the raw int).
        $this->assertIsString($payload['user']['id']);
        $this->assertNotSame((string) $user->id, $payload['user']['id']);
        $this->assertFalse(ctype_digit($payload['user']['id']));

        // vendor_id likewise HashID-encoded.
        $this->assertIsString($payload['user']['vendor_id']);
        $this->assertNotSame((string) $user->vendor_id, $payload['user']['vendor_id']);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $password = 'SupplierPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);
        $this->postLogin($user->email, $password)->assertOk();

        $this->withPortalHeaders()
            ->getJson('/api/v1/b2b/supplier/me')
            ->assertOk();

        $this->withPortalHeaders()
            ->postJson('/api/v1/b2b/supplier/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->withPortalHeaders()
            ->getJson('/api/v1/b2b/supplier/me')
            ->assertStatus(401);
    }

    public function test_wrong_password_increments_counter(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $this->postJson('/api/v1/b2b/supplier/login', [
            'email'    => $user->email,
            'password' => 'wrong-one',
        ])->assertStatus(422);

        $fresh = $user->fresh();
        $this->assertSame(1, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_five_wrong_attempts_locks_account(): void
    {
        $password = 'SupplierPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/b2b/supplier/login', [
                'email'    => $user->email,
                'password' => 'wrong-'.$i,
            ])->assertStatus(422);
        }

        $fresh = $user->fresh();
        $this->assertSame(5, (int) $fresh->failed_login_attempts);
        $this->assertNotNull($fresh->locked_until);
        $this->assertTrue($fresh->locked_until->isFuture());

        // Reset throttle so we can prove the lockout gate (not the rate limiter)
        // returns 423 even with the CORRECT password.
        $this->clearAuthThrottle($user->email);

        $this->postJson('/api/v1/b2b/supplier/login', [
            'email'    => $user->email,
            'password' => $password,
        ])->assertStatus(423);
    }

    public function test_throttle_returns_429_after_five_attempts_per_minute(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->postJson('/api/v1/b2b/supplier/login', [
                'email'    => $user->email,
                'password' => 'wrong-'.$i,
            ])->getStatusCode();
        }

        $this->assertCount(5, array_filter($statuses, fn ($s) => $s === 422),
            'First 5 attempts should hit B2bAuthService and 422. Got: '.implode(',', $statuses));
        $this->assertSame(429, $statuses[5],
            'Sixth attempt should be throttled (429). Got: '.implode(',', $statuses));
    }

    public function test_successful_login_resets_failed_counter(): void
    {
        $password = 'SupplierPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/b2b/supplier/login', [
                'email'    => $user->email,
                'password' => 'wrong-'.$i,
            ])->assertStatus(422);
        }
        $this->assertSame(2, (int) $user->fresh()->failed_login_attempts);

        $this->postLogin($user->email, $password)->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_expired_lock_restores_login_and_resets_the_strike_window(): void
    {
        $password = 'SupplierPass-1!';
        $user = $this->makeUser($password, [
            'failed_login_attempts' => 5,
            'locked_until' => now()->subMinute(),
        ]);
        $this->clearAuthThrottle($user->email);

        // Waiting out the lock must actually restore access, not merely stop
        // returning 423 while the strike counter stays at the threshold.
        $this->postLogin($user->email, $password)->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_first_failure_after_an_expired_lock_does_not_re_lock_the_account(): void
    {
        $user = $this->makeUser('SupplierPass-1!', [
            'failed_login_attempts' => 5,
            'locked_until' => now()->subMinute(),
        ]);
        $this->clearAuthThrottle($user->email);

        // A lock is a strike WINDOW. If the expired window left the counter at
        // 5, this single typo would immediately re-lock the supplier for
        // another 15 minutes — an accidental permanent lockout.
        $this->postJson('/api/v1/b2b/supplier/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $fresh = $user->fresh();
        $this->assertSame(1, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_audit_row_written_on_success(): void
    {
        $password = 'SupplierPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        $this->postLogin($user->email, $password)->assertOk();

        $row = AuditLog::where('action', 'supplier.login.success')
            ->where('model_type', SupplierPortalUser::class)
            ->where('model_id', $user->id)
            ->first();

        $this->assertNotNull($row, 'supplier.login.success row should be persisted');
        $this->assertNull($row->user_id, 'Portal user is not an internal user; user_id stays null');
        $this->assertNotEmpty($row->ip_address);
        $this->assertSame($user->email, $row->new_values['email'] ?? null);
    }

    public function test_audit_row_written_on_failure(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $this->postJson('/api/v1/b2b/supplier/login', [
            'email'    => $user->email,
            'password' => 'wrong-once',
        ])->assertStatus(422);

        $row = AuditLog::where('action', 'supplier.login.failed')
            ->where('model_type', SupplierPortalUser::class)
            ->where('model_id', $user->id)
            ->first();

        $this->assertNotNull($row, 'supplier.login.failed row should be persisted');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $password = 'SupplierPass-1!';
        $user = $this->makeUser($password, ['is_active' => false]);
        $this->clearAuthThrottle($user->email);

        $this->postJson('/api/v1/b2b/supplier/login', [
            'email'    => $user->email,
            'password' => $password,
        ])->assertStatus(422);
    }

    public function test_supplier_public_auth_routes_are_feature_gated(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.b2b_portals', false, 'modules');

        $routes = [
            ['login', ['email' => 'supplier-feature-disabled+'.uniqid().'@example.test', 'password' => 'SupplierPass-1!']],
            ['forgot-password', ['email' => 'unknown-supplier-feature-disabled+'.uniqid().'@example.test']],
            ['reset-password', [
                'token' => 'invalid-feature-disabled-token',
                'password' => 'SupplierPass-1!',
                'password_confirmation' => 'SupplierPass-1!',
            ]],
        ];

        try {
            foreach ($routes as [$route, $payload]) {
                $this->clearAuthThrottle($payload['email'] ?? '');

                $this->postJson('/api/v1/b2b/supplier/'.$route, $payload)
                    ->assertStatus(403)
                    ->assertJsonPath('code', 'feature_disabled');
            }

            // Logout is an authenticated route now (session invalidation, same
            // as the customer portal), so the auth middleware answers 401 for
            // a guest before the feature gate is consulted.
            $this->clearAuthThrottle('');
            $this->postJson('/api/v1/b2b/supplier/logout')
                ->assertStatus(401);
        } finally {
            $settings->set('modules.b2b_portals', true, 'modules');
        }
    }

    public function test_supplier_public_auth_routes_remain_reachable_when_feature_is_enabled(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.b2b_portals', true, 'modules');
        $user = $this->makeUser('SupplierPass-1!');

        try {
            $this->clearAuthThrottle($user->email);
            $this->postLogin($user->email, 'SupplierPass-1!')->assertOk();

            $this->clearAuthThrottle('');
            $this->withPortalHeaders()
                ->postJson('/api/v1/b2b/supplier/logout')
                ->assertOk()
                ->assertJsonPath('message', 'Logged out successfully.');

            // Logout invalidated the session (and its CSRF token); a real
            // client fetches a fresh csrf-cookie before the next POST.
            $this->withPortalHeaders();

            $unknownEmail = 'unknown-supplier-feature-enabled+'.uniqid().'@example.test';
            $this->clearAuthThrottle($unknownEmail);
            $this->postJson('/api/v1/b2b/supplier/forgot-password', [
                'email' => $unknownEmail,
            ])->assertOk();

            $this->clearAuthThrottle('');
            $this->postJson('/api/v1/b2b/supplier/reset-password', [
                'token' => 'invalid-feature-enabled-token',
                'password' => 'SupplierPass-1!',
                'password_confirmation' => 'SupplierPass-1!',
            ])->assertStatus(422)
                ->assertJsonValidationErrorFor('token');
        } finally {
            $settings->set('modules.b2b_portals', true, 'modules');
        }
    }
}
