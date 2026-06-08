<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 2 Task 14 — auth events mirror to the `audit_logs` table so the
 * Admin Audit Log UI can surface logins, lockouts, logouts, and password
 * changes. The Log::channel('auth') file sink is independent and is not
 * exercised here.
 */
class AuthEventsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Same hygiene as AuthSecurityTest: don't let throttle counters
        // leak between cases.
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    private function makeUser(string $password = 'CorrectHorse-1!', array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id'               => Role::where('slug', 'system_admin')->value('id'),
            'email'                 => 'audit+'.uniqid().'@t.test',
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
        RateLimiter::clear(md5('auth127.0.0.1|'.$email));
        RateLimiter::clear(md5('api127.0.0.1'));
    }

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

    public function test_login_success_writes_audit_row(): void
    {
        $password = 'CorrectHorse-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        $this->postLogin($user->email, $password)->assertOk();

        $row = AuditLog::where('model_type', 'auth.event')
            ->where('action', 'login.success')
            ->where('model_id', $user->id)
            ->first();

        $this->assertNotNull($row, 'login.success row should be persisted');
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertNotEmpty($row->ip_address);
        $this->assertNotEmpty($row->user_agent);
        $this->assertSame($user->email, $row->new_values['email'] ?? null);
    }

    public function test_login_failed_writes_audit_row(): void
    {
        $user = $this->makeUser('CorrectHorse-1!');
        $this->clearAuthThrottle($user->email);

        $this->postLogin($user->email, 'wrong-password-x')->assertStatus(422);

        $row = AuditLog::where('model_type', 'auth.event')
            ->where('action', 'login.failed')
            ->where('model_id', $user->id)
            ->first();

        $this->assertNotNull($row, 'login.failed row should be persisted');
        $this->assertNotEmpty($row->ip_address);
        $this->assertNotEmpty($row->user_agent);
    }

    public function test_lockout_threshold_writes_both_failed_and_lockout_rows(): void
    {
        $user = $this->makeUser('CorrectHorse-1!');
        $this->clearAuthThrottle($user->email);

        for ($i = 0; $i < 5; $i++) {
            $this->postLogin($user->email, 'wrong-pwd-'.$i)->assertStatus(422);
        }

        $failed = AuditLog::where('model_type', 'auth.event')
            ->where('action', 'login.failed')
            ->where('model_id', $user->id)
            ->count();
        $this->assertSame(5, $failed, 'Each failed attempt writes a login.failed row');

        // The 5th attempt also stamps the threshold-crossed event. action
        // column was widened in 0176 so the full event name is preserved.
        $lockout = AuditLog::where('model_type', 'auth.event')
            ->where('action', 'login.locked_threshold')
            ->where('model_id', $user->id)
            ->count();
        $this->assertSame(1, $lockout, '5th failure writes one login.locked_threshold row');
    }

    public function test_logout_writes_audit_row(): void
    {
        $password = 'CorrectHorse-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        $this->postLogin($user->email, $password)->assertOk();

        $token = 'test-csrf-token';
        $this->withSession(['_token' => $token])
            ->withHeaders([
                'Origin'       => 'http://localhost',
                'X-CSRF-TOKEN' => $token,
            ])
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $row = AuditLog::where('model_type', 'auth.event')
            ->where('action', 'logout')
            ->where('model_id', $user->id)
            ->first();

        $this->assertNotNull($row, 'logout row should be persisted');
        $this->assertNotEmpty($row->ip_address);
    }

    public function test_password_changed_writes_audit_row(): void
    {
        $original = 'Original-1!';
        $user = $this->makeUser($original);

        $this->actingAs($user)
            ->postJson('/api/v1/auth/change-password', [
                'current_password'           => $original,
                'new_password'               => 'NewPass-9!',
                'new_password_confirmation'  => 'NewPass-9!',
            ])->assertOk();

        $row = AuditLog::where('model_type', 'auth.event')
            ->where('action', 'password.changed')
            ->where('model_id', $user->id)
            ->first();

        $this->assertNotNull($row, 'password.changed row should be persisted');
        $this->assertSame($user->id, (int) $row->user_id);
    }

    public function test_unknown_email_does_not_write_audit_row(): void
    {
        $email = 'nobody-'.uniqid().'@t.test';
        $this->clearAuthThrottle($email);

        $this->postLogin($email, 'AnyPassword-1!')->assertStatus(422);

        $count = AuditLog::where('model_type', 'auth.event')->count();
        $this->assertSame(0, $count,
            'Unknown-email attempts must not create audit_logs rows; they are tracked via LoginHistory only.');
    }
}
