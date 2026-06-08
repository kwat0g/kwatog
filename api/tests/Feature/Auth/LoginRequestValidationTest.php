<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 2 Task 12 — pure validation guard for /api/v1/auth/login.
 * Independent of AuthSecurityTest (which exercises lockout/throttle); this
 * file pins the FormRequest contract: email RFC-valid, password min 8.
 */
class LoginRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Avoid cross-test throttle pollution.
        RateLimiter::clear(md5('api127.0.0.1'));
        RateLimiter::clear(md5('auth127.0.0.1|user@t.test'));
    }

    public function test_rejects_password_shorter_than_eight_characters(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'user@t.test',
            'password' => '1234567',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('password');
    }

    public function test_rejects_missing_password(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@t.test',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('password');
    }

    public function test_accepts_eight_character_password_at_validation_layer(): void
    {
        // We only care that the request passes the FormRequest gate. With no
        // matching user the controller will reject with 422 (invalid creds),
        // but the `password` field itself must not be flagged.
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'user@t.test',
            'password' => '12345678',
        ]);

        // Whatever the outcome (422 invalid creds, 423 locked, etc.), the
        // contract is: password is NOT a validation error here.
        if ($response->status() === 422) {
            $errors = $response->json('errors') ?? [];
            $this->assertArrayNotHasKey('password', $errors,
                'min:8 should accept exactly 8 chars; password should not appear in validation errors.');
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_real_login_succeeds_with_valid_eight_plus_password(): void
    {
        // Sanity: with a real user, an 8+ char password validates and reaches
        // AuthService cleanly. Confirms min:8 didn't accidentally bump beyond 8.
        $user = User::factory()->create([
            'role_id'              => Role::where('slug', 'system_admin')->value('id'),
            'email'                => 'login-min8@t.test',
            'password'             => Hash::make('Pass-1!ab'),
            'password_changed_at'  => now(),
            'is_active'            => true,
            'must_change_password' => false,
        ]);

        RateLimiter::clear(md5('auth127.0.0.1|'.$user->email));

        // Stateful Sanctum requires Origin + matching CSRF token in the
        // session. Mirror AuthSecurityTest::postLogin's setup so we exercise
        // the full middleware stack, not a 419-shaped failure.
        $token = 'test-csrf-token';
        $this->withSession(['_token' => $token])
            ->withHeaders([
                'Origin'       => 'http://localhost',
                'X-CSRF-TOKEN' => $token,
            ])
            ->postJson('/api/v1/auth/login', [
                'email'    => $user->email,
                'password' => 'Pass-1!ab',
            ])
            ->assertOk();
    }
}
