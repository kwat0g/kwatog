<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\PasswordResetRequest;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\PasswordResetLinkNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Clear rate-limiter buckets so throttle:auth (5/min/ip) doesn't bleed
        // across tests within the same process.
        RateLimiter::clear(md5('auth127.0.0.1|unknown@t.test'));
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id'   => Role::where('slug', 'system_admin')->value('id'),
            'email'     => 'reset+' . uniqid() . '@t.test',
            'is_active' => true,
        ], $overrides));
    }

    private function clearAuthThrottle(string $email): void
    {
        RateLimiter::clear(md5('auth127.0.0.1|' . $email));
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    // ─── forgot-password ─────────────────────────────────────────────────────

    public function test_forgot_password_returns_generic_message_for_unknown_email(): void
    {
        Notification::fake();

        $this->clearAuthThrottle('nobody@nowhere.test');

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nobody@nowhere.test',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('password_reset_requests', 0);
        Notification::assertNothingSent();
    }

    public function test_forgot_password_creates_token_and_sends_notification_for_real_user(): void
    {
        Notification::fake();

        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('password_reset_requests', ['user_id' => $user->id]);
        $this->assertSame(1, PasswordResetRequest::where('user_id', $user->id)->count());
        Notification::assertSentTo($user, PasswordResetLinkNotification::class);
    }

    // ─── reset-password ───────────────────────────────────────────────────────

    public function test_reset_password_succeeds_with_valid_token(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $raw = 'valid-raw-token-abcdefghijklmnopqrstuvwxyz0123456789ab';
        PasswordResetRequest::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes(60),
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $raw,
            'password'              => 'NewStr0ng!Pass',
            'password_confirmation' => 'NewStr0ng!Pass',
        ]);

        $response->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NewStr0ng!Pass', $fresh->password),
            'User password was not updated to the new value.');

        $row = PasswordResetRequest::where('user_id', $user->id)->first();
        $this->assertNotNull($row->used_at, 'Token used_at should be stamped after successful reset.');
    }

    public function test_reset_password_rejects_weak_password(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $raw = 'valid-raw-token-abcdefghijklmnopqrstuvwxyz0123456789cd';
        PasswordResetRequest::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes(60),
            'ip_address' => '127.0.0.1',
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $raw,
            'password'              => 'weak',
            'password_confirmation' => 'weak',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('password');
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => 'this-token-does-not-exist-in-the-database-at-all',
            'password'              => 'NewStr0ng!Pass',
            'password_confirmation' => 'NewStr0ng!Pass',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('token');
    }

    public function test_reset_password_rejects_expired_token(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $raw = 'expired-raw-token-abcdefghijklmnopqrstuvwxyz012345678';
        PasswordResetRequest::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->subMinutes(5),
            'ip_address' => '127.0.0.1',
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $raw,
            'password'              => 'NewStr0ng!Pass',
            'password_confirmation' => 'NewStr0ng!Pass',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('token');
    }

    public function test_reset_password_rejects_reused_token(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        $raw = 'reuse-raw-token-abcdefghijklmnopqrstuvwxyz0123456789ef';
        PasswordResetRequest::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes(60),
            'ip_address' => '127.0.0.1',
        ]);

        // First use — must succeed.
        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $raw,
            'password'              => 'NewStr0ng!Pass1',
            'password_confirmation' => 'NewStr0ng!Pass1',
        ])->assertOk();

        $this->clearAuthThrottle($user->email);

        // Second use of the same token — must be rejected.
        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $raw,
            'password'              => 'NewStr0ng!Pass2',
            'password_confirmation' => 'NewStr0ng!Pass2',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('token');
    }

    public function test_reset_password_rejects_password_in_history(): void
    {
        $user = $this->makeUser();
        $this->clearAuthThrottle($user->email);

        // Pre-populate history with a known password.
        PasswordHistory::create([
            'user_id'       => $user->id,
            'password_hash' => Hash::make('OldStr0ng!Pass'),
            'created_at'    => now()->subHour(),
        ]);

        $raw = 'history-raw-token-abcdefghijklmnopqrstuvwxyz012345678';
        PasswordResetRequest::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes(60),
            'ip_address' => '127.0.0.1',
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $raw,
            'password'              => 'OldStr0ng!Pass',
            'password_confirmation' => 'OldStr0ng!Pass',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('password');
    }
}
