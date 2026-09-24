<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Services\PortalInvitationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * M035 — customer portal invitation tenant boundary.
 *
 * `customer_portal_users.email` is globally UNIQUE while each row holds a single
 * `customer_id`, so the invitation path IS the tenant boundary: letting an
 * address move between customers re-points one customer's portal login at
 * another customer's orders, invoices, deliveries and complaints, and hands the
 * inviting customer a password of their own choosing for it. That is an account
 * takeover of the first customer plus a denial of their own access, so these
 * tests pin the refusal at both the HTTP and service layers.
 *
 * Mirrors Tests\Feature\B2B\SupplierPortalAccessLifecycleTest (M047-F005) for
 * the equivalent supplier boundary.
 */
class CustomerPortalAccessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Mail::fake();
    }

    private function operator(string $role = 'finance_officer'): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
        ]);
    }

    private function portalUser(Customer $customer, string $email, array $overrides = []): CustomerPortalUser
    {
        return CustomerPortalUser::create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'Customer Contact',
            'email' => $email,
            'password' => Hash::make('CustomerPass-1!'),
            'is_active' => true,
        ], $overrides));
    }

    public function test_invitation_refuses_to_move_an_email_to_a_different_customer(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $existing = $this->portalUser($customerA, 'shared-contact@example.test');
        $originalHash = $existing->password;

        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/customers/{$customerB->hash_id}/invite", [
                'name' => 'Same Contact',
                'email' => 'shared-contact@example.test',
            ])
            ->assertStatus(422);

        $fresh = $existing->fresh();
        // Ownership must not move...
        $this->assertSame($customerA->id, $fresh->customer_id);
        // ...and customer A's credential must not have been rotated to one that
        // customer B knows, which is the takeover half of the defect.
        $this->assertSame($originalHash, $fresh->password);
        $this->assertFalse((bool) $fresh->must_change_password);
        $this->assertSame(1, CustomerPortalUser::withTrashed()
            ->whereRaw('LOWER(email) = ?', ['shared-contact@example.test'])->count());
    }

    public function test_invitation_is_case_insensitive_when_detecting_a_cross_customer_conflict(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $existing = $this->portalUser($customerA, 'mixed-case@example.test');

        // Emails are normalised to lowercase on write, so the conflict check has
        // to normalise too or the boundary is bypassable by capitalisation.
        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/customers/{$customerB->hash_id}/invite", [
                'name' => 'Same Contact',
                'email' => 'Mixed-Case@Example.Test',
            ])
            ->assertStatus(422);

        $this->assertSame($customerA->id, $existing->fresh()->customer_id);
    }

    public function test_invitation_service_rejects_cross_customer_reassignment_directly(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $this->portalUser($customerA, 'service-level@example.test');

        // Belt and braces: the boundary lives in the service, not only in the
        // HTTP layer, so any future caller inherits it.
        $this->expectException(BusinessRuleException::class);
        app(PortalInvitationService::class)->inviteCustomer($customerB, 'Someone', 'service-level@example.test');
    }

    public function test_customer_invitation_requires_an_active_parent_and_is_audited(): void
    {
        $inactive = Customer::factory()->create(['is_active' => false]);

        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/customers/{$inactive->hash_id}/invite", [
                'name' => 'Inactive Contact',
                'email' => 'inactive-contact@example.test',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('customer_portal_users', ['email' => 'inactive-contact@example.test']);

        $active = Customer::factory()->create();
        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/customers/{$active->hash_id}/invite", [
                'name' => 'Active Contact',
                'email' => 'active-contact@example.test',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'portal_user.invited',
            'model_type' => CustomerPortalUser::class,
            'new_values->customer_id' => $active->id,
            'new_values->email' => 'active-contact@example.test',
        ]);
    }

    public function test_inactive_customer_parent_revokes_portal_access(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->portalUser($customer, 'inactive-parent@example.test');
        $customer->update(['is_active' => false]);

        $this->actingAs($user, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'portal_account_inactive');

        $this->actingAs($this->operator(), 'sanctum')
            ->getJson('/api/v1/b2b/portal-access/customers?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'inactive');

        $this->actingAs($this->operator(), 'sanctum')
            ->patchJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/reactivate")
            ->assertStatus(422);
    }

    public function test_customer_portal_token_revocation_is_audited_and_permission_gated(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->portalUser($customer, 'revoke-customer-token@example.test');
        $user->createToken('customer-portal');

        $this->actingAs($this->operator())
            ->deleteJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/tokens")
            ->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'portal_tokens.revoke',
            'model_type' => CustomerPortalUser::class,
            'model_id' => $user->id,
        ]);
    }

    public function test_portal_account_resources_do_not_expose_login_strike_details(): void
    {
        $customer = Customer::factory()->create();
        $this->portalUser($customer, 'private-lock-state@example.test', [
            'failed_login_attempts' => 4,
            'locked_until' => now()->addMinutes(10),
        ]);

        $row = $this->actingAs($this->operator())
            ->getJson('/api/v1/b2b/portal-access/customers')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('failed_login_attempts', $row);
        $this->assertArrayNotHasKey('locked_until', $row);
        $this->assertSame('locked', $row['status']);
    }

    public function test_same_customer_reinvitation_rotates_the_credential(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->portalUser($customer, 'rotate@example.test');
        $oldHash = $user->password;

        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/customers/{$customer->hash_id}/invite", [
                'name' => 'Rotated Contact',
                'email' => 'rotate@example.test',
            ])
            ->assertStatus(201);

        $fresh = $user->fresh();
        $this->assertSame($customer->id, $fresh->customer_id);
        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertSame('Rotated Contact', $fresh->name);
    }

    public function test_invitation_response_does_not_hand_the_temporary_password_to_the_client(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/customers/{$customer->hash_id}/invite", [
                'name' => 'New Contact',
                'email' => 'new-contact@example.test',
            ])
            ->assertStatus(201);

        $body = $response->json();
        $this->assertArrayNotHasKey('temporary_password', (array) ($body['data'] ?? []));
        $this->assertStringNotContainsString('temporary_password', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    /* ─── Operator lifecycle (mirrors the supplier list in M047-F006) ─── */

    public function test_customer_list_reports_every_lifecycle_state_and_filters_by_status(): void
    {
        $customer = Customer::factory()->create(['name' => 'Toyota Demo']);
        $active = $this->portalUser($customer, 'cust-active@example.test', ['must_change_password' => false]);
        $pending = $this->portalUser($customer, 'cust-pending@example.test', ['must_change_password' => true]);
        $locked = $this->portalUser($customer, 'cust-locked@example.test', [
            'must_change_password' => false,
            'locked_until' => now()->addMinutes(15),
        ]);
        $inactive = $this->portalUser($customer, 'cust-inactive@example.test', ['is_active' => false]);

        $operator = $this->operator();
        $row = $this->actingAs($operator)
            ->getJson('/api/v1/b2b/portal-access/customers')
            ->assertOk()
            ->json('data.0');
        // The list is what an admin screen renders: the linked customer org is
        // present, and the status field drives the lifecycle chip.
        $this->assertSame('Toyota Demo', $row['customer']['name']);

        $statuses = $this->actingAs($operator)
            ->getJson('/api/v1/b2b/portal-access/customers')
            ->assertOk()
            ->json('data.*.status');
        $this->assertEqualsCanonicalizing(['active', 'pending', 'locked', 'inactive'], $statuses);

        foreach ([
            'active' => $active,
            'pending' => $pending,
            'locked' => $locked,
            'inactive' => $inactive,
        ] as $status => $expected) {
            $ids = $this->actingAs($operator)
                ->getJson("/api/v1/b2b/portal-access/customers?status={$status}")
                ->assertOk()
                ->json('data.*.id');
            $this->assertSame([$expected->hash_id], $ids, "status={$status} must return only that account.");
        }
    }

    public function test_customer_deactivation_and_reactivation_roundtrip(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->portalUser($customer, 'cust-lifecycle@example.test', [
            'failed_login_attempts' => 3,
            'locked_until' => now()->addMinutes(15),
        ]);

        $this->actingAs($this->operator())
            ->patchJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->is_active);

        $this->actingAs($this->operator())
            ->patchJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_customer_resend_cannot_quietly_reactivate_a_deactivated_account(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->portalUser($customer, 'cust-no-backdoor@example.test', ['is_active' => false]);

        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/resend")
            ->assertStatus(422);

        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_customer_resend_rotates_the_credential_when_active(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->portalUser($customer, 'cust-rotate@example.test');
        $oldHash = $user->password;

        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/resend")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $fresh = $user->fresh();
        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue((bool) $fresh->must_change_password);
    }

    public function test_the_customer_list_never_returns_a_password_or_temporary_credential(): void
    {
        $customer = Customer::factory()->create();
        $this->portalUser($customer, 'cust-no-secrets@example.test');

        $row = $this->actingAs($this->operator())
            ->getJson('/api/v1/b2b/portal-access/customers')
            ->assertOk()
            ->json('data.0');

        foreach (['password', 'temporary_password', 'password_hash'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }

    public function test_customer_lifecycle_actions_require_the_access_permissions(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->portalUser($customer, 'cust-rbac@example.test');
        // warehouse_staff holds no b2b.portal_access.* permission.
        $outsider = $this->operator('warehouse_staff');

        $this->actingAs($outsider)->getJson('/api/v1/b2b/portal-access/customers')->assertStatus(403);
        $this->actingAs($outsider)
            ->patchJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/deactivate")->assertStatus(403);
        $this->actingAs($outsider)
            ->patchJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/reactivate")->assertStatus(403);
        $this->actingAs($outsider)
            ->deleteJson("/api/v1/b2b/portal-access/customers/{$user->hash_id}/tokens")->assertStatus(403);

        $this->assertTrue((bool) $user->fresh()->is_active);
    }
}
