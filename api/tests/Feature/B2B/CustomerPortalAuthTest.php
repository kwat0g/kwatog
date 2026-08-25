<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\B2B\Models\CustomerPortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 2 Task 15 (C-4) — Customer Portal auth hardening. Mirrors the supplier
 * portal test (the underlying B2bAuthService is the same) — covers the
 * audience-specific event names and the customer_id HashID envelope.
 */
class CustomerPortalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    private function makeUser(string $password = 'CustomerPass-1!', array $overrides = []): CustomerPortalUser
    {
        $customer = Customer::factory()->create();

        return CustomerPortalUser::create(array_merge([
            'customer_id'           => $customer->id,
            'name'                  => 'Test Customer',
            'email'                 => 'customer+'.uniqid().'@t.test',
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

    private function withPortalHeaders(): self
    {
        $csrf = 'customer-portal-test-csrf';

        return $this->withSession(['_token' => $csrf])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => $csrf,
            ]);
    }

    private function postLogin(string $email, string $password)
    {
        return $this->withPortalHeaders()->postJson('/api/v1/b2b/customer/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_login_succeeds_and_returns_hashids(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        $response = $this->postLogin($user->email, $password);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'customer_id', 'must_change_password']]])
            ->assertJsonMissingPath('data.token');

        $payload = $response->json('data');

        $this->assertIsString($payload['user']['id']);
        $this->assertNotSame((string) $user->id, $payload['user']['id']);
        $this->assertFalse(ctype_digit($payload['user']['id']));

        $this->assertIsString($payload['user']['customer_id']);
        $this->assertNotSame((string) $user->customer_id, $payload['user']['customer_id']);
    }

    public function test_first_login_account_is_marked_for_password_change(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password, ['must_change_password' => true]);
        $this->clearAuthThrottle($user->email);

        $this->postLogin($user->email, $password)
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', true);

        $this->withPortalHeaders()
            ->getJson('/api/v1/b2b/customer/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_first_login_user_can_change_password_and_force_flag_is_cleared(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password, ['must_change_password' => true]);
        $this->clearAuthThrottle($user->email);
        $this->postLogin($user->email, $password)->assertOk();

        $this->withPortalHeaders()
            ->postJson('/api/v1/b2b/customer/change-password', [
                'current_password' => $password,
                'new_password' => 'Replacement-2!',
                'new_password_confirmation' => 'Replacement-2!',
            ])->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('Replacement-2!', $fresh->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => CustomerPortalUser::class,
            'tokenable_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'portal.password.changed',
            'model_type' => CustomerPortalUser::class,
            'model_id' => $user->id,
        ]);
    }

    public function test_five_wrong_attempts_locks_account(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/b2b/customer/login', [
                'email'    => $user->email,
                'password' => 'wrong-'.$i,
            ])->assertStatus(422);
        }

        $fresh = $user->fresh();
        $this->assertSame(5, (int) $fresh->failed_login_attempts);
        $this->assertNotNull($fresh->locked_until);
        $this->assertTrue($fresh->locked_until->isFuture());

        $this->clearAuthThrottle($user->email);

        $this->postJson('/api/v1/b2b/customer/login', [
            'email'    => $user->email,
            'password' => $password,
        ])->assertStatus(423);
    }

    public function test_audit_row_written_on_success(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        $this->postLogin($user->email, $password)->assertOk();

        $row = AuditLog::where('action', 'customer.login.success')
            ->where('model_type', CustomerPortalUser::class)
            ->where('model_id', $user->id)
            ->first();

        $this->assertNotNull($row, 'customer.login.success row should be persisted');
        $this->assertNull($row->user_id);
        $this->assertSame($user->email, $row->new_values['email'] ?? null);
    }

    public function test_login_normalizes_email_case(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password, ['email' => 'Mixed.Case+'.uniqid().'@t.test']);
        $this->clearAuthThrottle($user->email);

        $this->postLogin(strtoupper($user->email), $password)
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_expired_customer_password_is_gated_until_changed(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password, ['password_changed_at' => now()->subDays(91)]);
        $this->clearAuthThrottle($user->email);

        $this->postLogin($user->email, $password)->assertOk();

        $this->withPortalHeaders()
            ->getJson('/api/v1/b2b/customer/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_expired');

        $this->withPortalHeaders()
            ->postJson('/api/v1/b2b/customer/change-password', [
                'current_password' => $password,
                'new_password' => 'Replacement-2!',
                'new_password_confirmation' => 'Replacement-2!',
            ])
            ->assertOk();
    }

    public function test_customer_password_history_blocks_recent_reuse(): void
    {
        $oldPassword = 'CustomerPass-1!';
        $newPassword = 'Replacement-2!';
        $user = $this->makeUser($oldPassword);
        $this->clearAuthThrottle($user->email);

        $this->postLogin($user->email, $oldPassword)->assertOk();
        $this->withPortalHeaders()
            ->postJson('/api/v1/b2b/customer/change-password', [
                'current_password' => $oldPassword,
                'new_password' => $newPassword,
                'new_password_confirmation' => $newPassword,
            ])
            ->assertOk();

        $this->clearAuthThrottle($user->email);
        $this->postLogin($user->email, $newPassword)->assertOk();
        $this->withPortalHeaders()
            ->postJson('/api/v1/b2b/customer/change-password', [
                'current_password' => $newPassword,
                'new_password' => $oldPassword,
                'new_password_confirmation' => $oldPassword,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('new_password');
    }

    public function test_customer_public_auth_routes_are_feature_gated(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.b2b_portals', false, 'modules');
        $email = 'customer-feature-disabled+'.uniqid().'@example.test';
        $this->clearAuthThrottle($email);

        try {
            $this->postJson('/api/v1/b2b/customer/login', [
                'email' => $email,
                'password' => 'CustomerPass-1!',
            ])
                ->assertStatus(403)
                ->assertJsonPath('code', 'feature_disabled');
        } finally {
            $settings->set('modules.b2b_portals', true, 'modules');
        }
    }
}
