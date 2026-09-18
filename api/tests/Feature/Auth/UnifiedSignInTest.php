<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class UnifiedSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.b2b_portals', true);
        RateLimiter::clear(md5('api127.0.0.1'));
    }

    private function postSignIn(string $email, string $password)
    {
        $csrf = 'unified-sign-in-csrf';

        return $this->withSession(['_token' => $csrf])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => $csrf,
            ])
            ->postJson('/api/v1/auth/sign-in', compact('email', 'password'));
    }

    public function test_internal_credentials_return_the_internal_realm_and_session_user(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'unified-internal+'.uniqid().'@t.test',
            'password' => Hash::make('InternalPass-1!'),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);

        $this->postSignIn($user->email, 'InternalPass-1')
            ->assertStatus(422);

        $this->postSignIn($user->email, 'InternalPass-1!')
            ->assertOk()
            ->assertJsonPath('data.realm', 'internal')
            ->assertJsonPath('data.user.email', $user->email);

        $this->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_customer_credentials_return_the_customer_realm_and_session(): void
    {
        $customer = Customer::factory()->create();
        $user = CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Unified Customer',
            'email' => 'unified-customer+'.uniqid().'@t.test',
            'password' => Hash::make('CustomerPass-1!'),
            'is_active' => true,
        ]);

        $this->postSignIn($user->email, 'CustomerPass-1!')
            ->assertOk()
            ->assertJsonPath('data.realm', 'customer')
            ->assertJsonPath('data.user', null);

        $this->getJson('/api/v1/b2b/customer/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_supplier_credentials_return_the_supplier_realm_and_session(): void
    {
        $vendor = Vendor::factory()->create();
        $user = SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'Unified Supplier',
            'email' => 'unified-supplier+'.uniqid().'@t.test',
            'password' => Hash::make('SupplierPass-1!'),
            'is_active' => true,
        ]);

        $this->postSignIn($user->email, 'SupplierPass-1!')
            ->assertOk()
            ->assertJsonPath('data.realm', 'supplier')
            ->assertJsonPath('data.user', null);

        $this->getJson('/api/v1/b2b/supplier/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_unified_password_reset_finds_a_customer_portal_account(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create();
        $user = CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Reset Customer',
            'email' => 'unified-reset+'.uniqid().'@t.test',
            'password' => Hash::make('CustomerPass-1!'),
            'is_active' => true,
        ]);

        $this->withSession(['_token' => 'unified-reset-csrf'])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => 'unified-reset-csrf',
            ])
            ->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk();

        $this->assertDatabaseHas('portal_password_reset_tokens', [
            'portal_type' => 'customer',
            'email' => $user->email,
        ]);
    }
}
