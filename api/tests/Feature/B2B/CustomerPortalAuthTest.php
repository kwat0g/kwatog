<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
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

    public function test_login_succeeds_and_returns_hashids(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password);
        $this->clearAuthThrottle($user->email);

        $response = $this->postJson('/api/v1/b2b/customer/login', [
            'email'    => $user->email,
            'password' => $password,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'customer_id', 'must_change_password']]]);

        $payload = $response->json('data');
        $this->assertNotEmpty($payload['token']);

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

        $login = $this->postJson('/api/v1/b2b/customer/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->assertJsonPath('data.user.must_change_password', true);

        $this->withToken($login->json('data.token'))
            ->getJson('/api/v1/b2b/customer/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_first_login_user_can_change_password_and_force_flag_is_cleared(): void
    {
        $password = 'CustomerPass-1!';
        $user = $this->makeUser($password, ['must_change_password' => true]);
        $this->clearAuthThrottle($user->email);
        $login = $this->postJson('/api/v1/b2b/customer/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();

        $this->withToken($login->json('data.token'))
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

        $this->postJson('/api/v1/b2b/customer/login', [
            'email'    => $user->email,
            'password' => $password,
        ])->assertOk();

        $row = AuditLog::where('action', 'customer.login.success')
            ->where('model_type', CustomerPortalUser::class)
            ->where('model_id', $user->id)
            ->first();

        $this->assertNotNull($row, 'customer.login.success row should be persisted');
        $this->assertNull($row->user_id);
        $this->assertSame($user->email, $row->new_values['email'] ?? null);
    }
}
