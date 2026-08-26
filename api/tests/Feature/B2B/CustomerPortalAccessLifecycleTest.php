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
}
