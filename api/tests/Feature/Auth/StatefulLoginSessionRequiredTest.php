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

final class StatefulLoginSessionRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    public function test_stateless_login_routes_reject_valid_credentials_without_mutating_user(): void
    {
        $lastActivity = now()->subMinutes(10)->startOfSecond();
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'stateless-login+'.uniqid().'@t.test',
            'password' => Hash::make('CorrectHorse-1!'),
            'password_changed_at' => now(),
            'is_active' => true,
            'failed_login_attempts' => 3,
            'last_activity' => $lastActivity,
            'must_change_password' => false,
        ]);

        foreach (['login', 'sign-in'] as $endpoint) {
            $response = $this->postJson('/api/v1/auth/'.$endpoint, [
                'email' => $user->email,
                'password' => 'CorrectHorse-1!',
            ]);

            $response->assertStatus(419)
                ->assertJsonPath('message', 'Your sign-in session is missing or expired. Refresh the page and try again.');
        }

        $fresh = $user->fresh();
        $this->assertSame(3, (int) $fresh->failed_login_attempts);
        $this->assertSame($lastActivity->toDateTimeString(), $fresh->last_activity->toDateTimeString());
        $this->assertDatabaseMissing('audit_logs', [
            'model_type' => 'auth.event',
            'action' => 'login.success',
            'model_id' => $user->id,
        ]);
    }
}
