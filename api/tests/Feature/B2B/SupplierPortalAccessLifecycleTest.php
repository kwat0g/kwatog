<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Services\PortalInvitationService;
use App\Common\Exceptions\BusinessRuleException;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M047-F005/F006 — supplier portal credential lifecycle.
 *
 * The supplier portal user table makes `email` globally unique while storing a
 * single `vendor_id`, so the invitation path IS the tenant boundary: moving an
 * address between vendors silently re-points one supplier's credential at
 * another supplier's purchase orders. These tests pin that boundary and the
 * operator lifecycle (list / invite / resend / deactivate / reactivate /
 * revoke) that makes a compromised credential revocable in-product rather than
 * by hand in the database.
 */
class SupplierPortalAccessLifecycleTest extends TestCase
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

    private function portalUser(Vendor $vendor, string $email, array $overrides = []): SupplierPortalUser
    {
        return SupplierPortalUser::create(array_merge([
            'vendor_id' => $vendor->id,
            'name' => 'Supplier Contact',
            'email' => $email,
            'password' => Hash::make('SupplierPass-1!'),
            'is_active' => true,
        ], $overrides));
    }

    /* ─── Tenant boundary on invitation (M047-F005) ──────────────── */

    public function test_invitation_refuses_to_move_an_email_to_a_different_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $existing = $this->portalUser($vendorA, 'shared-contact@example.test');

        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$vendorB->hash_id}/invite", [
                'name' => 'Same Contact',
                'email' => 'shared-contact@example.test',
            ])
            ->assertStatus(422);

        // The account must still belong to vendor A, and there must be exactly
        // one — a second row would violate the unique email anyway, but a
        // reassignment would silently hand vendor A's history to vendor B.
        $this->assertSame($vendorA->id, $existing->fresh()->vendor_id);
        $this->assertSame(1, SupplierPortalUser::withTrashed()
            ->whereRaw('LOWER(email) = ?', ['shared-contact@example.test'])->count());
    }

    public function test_invitation_is_case_insensitive_when_detecting_a_cross_vendor_conflict(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $this->portalUser($vendorA, 'mixed-case@example.test');

        // Emails are normalised to lowercase on write, so the conflict check
        // has to normalise too or the boundary is bypassable by capitalisation.
        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$vendorB->hash_id}/invite", [
                'name' => 'Same Contact',
                'email' => 'Mixed-Case@Example.Test',
            ])
            ->assertStatus(422);
    }

    public function test_invitation_refuses_an_email_held_by_a_deactivated_account(): void
    {
        $vendor = Vendor::factory()->create();
        $this->portalUser($vendor, 'dormant@example.test', ['is_active' => false]);

        // Re-inviting must not be a back door around reactivation, which is the
        // audited decision point.
        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/invite", [
                'name' => 'Dormant Contact',
                'email' => 'dormant@example.test',
            ])
            ->assertStatus(422);

        $this->assertFalse((bool) SupplierPortalUser::query()
            ->whereRaw('LOWER(email) = ?', ['dormant@example.test'])->firstOrFail()->is_active);
    }

    public function test_same_vendor_reinvitation_rotates_the_credential_and_kills_old_tokens(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->portalUser($vendor, 'rotate@example.test');
        $oldHash = $user->password;
        $user->createToken('supplier_portal');
        $this->assertSame(1, $user->tokens()->count());

        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/invite", [
                'name' => 'Rotated Contact',
                'email' => 'rotate@example.test',
            ])
            ->assertStatus(201);

        $fresh = $user->fresh();
        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertSame(0, $fresh->tokens()->count(), 'A credential rotation must not leave the old bearer token live.');
    }

    public function test_invitation_service_rejects_cross_vendor_reassignment_directly(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $this->portalUser($vendorA, 'service-level@example.test');

        // Belt and braces: the boundary lives in the service, not only in the
        // HTTP layer, so any future caller inherits it.
        $this->expectException(BusinessRuleException::class);
        app(PortalInvitationService::class)->inviteSupplier($vendorB, 'Someone', 'service-level@example.test');
    }

    /* ─── Operator lifecycle (M047-F006) ─────────────────────────── */

    public function test_deactivation_revokes_live_tokens_and_the_portal_immediately_rejects_them(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->portalUser($vendor, 'revoke-me@example.test');
        $user->createToken('supplier_portal');

        $this->actingAs($this->operator())
            ->patchJson("/api/v1/b2b/portal-access/suppliers/{$user->hash_id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertSame(0, $fresh->tokens()->count());

        // And the guard refuses the principal even if a token were replayed.
        Sanctum::actingAs($fresh, ['*'], 'supplier_portal');
        $this->getJson('/api/v1/b2b/supplier/dashboard')->assertStatus(401);
    }

    public function test_resend_cannot_quietly_reactivate_a_deactivated_account(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->portalUser($vendor, 'no-backdoor@example.test', ['is_active' => false]);

        $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$user->hash_id}/resend")
            ->assertStatus(422);

        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_reactivation_restores_access_and_forces_a_new_password(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->portalUser($vendor, 'restore-me@example.test', [
            'is_active' => false,
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ]);

        $this->actingAs($this->operator())
            ->patchJson("/api/v1/b2b/portal-access/suppliers/{$user->hash_id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_token_revocation_leaves_the_account_active(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->portalUser($vendor, 'sessions-only@example.test');
        $user->createToken('supplier_portal');

        $this->actingAs($this->operator())
            ->deleteJson("/api/v1/b2b/portal-access/suppliers/{$user->hash_id}/tokens")
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertTrue((bool) $fresh->is_active, 'Revoking sessions is not the same decision as revoking access.');
    }

    public function test_list_reports_every_lifecycle_state_and_filters_by_status(): void
    {
        $vendor = Vendor::factory()->create();
        $active = $this->portalUser($vendor, 'state-active@example.test', ['must_change_password' => false]);
        $pending = $this->portalUser($vendor, 'state-pending@example.test', ['must_change_password' => true]);
        $locked = $this->portalUser($vendor, 'state-locked@example.test', [
            'must_change_password' => false,
            'locked_until' => now()->addMinutes(15),
        ]);
        $inactive = $this->portalUser($vendor, 'state-inactive@example.test', ['is_active' => false]);

        $operator = $this->operator();
        $statuses = $this->actingAs($operator)
            ->getJson('/api/v1/b2b/portal-access/suppliers')
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
                ->getJson("/api/v1/b2b/portal-access/suppliers?status={$status}")
                ->assertOk()
                ->json('data.*.id');
            $this->assertSame([$expected->hash_id], $ids, "status={$status} must return only that account.");
        }
    }

    public function test_the_list_never_returns_a_password_or_temporary_credential(): void
    {
        $vendor = Vendor::factory()->create();
        $this->portalUser($vendor, 'no-secrets@example.test');

        $row = $this->actingAs($this->operator())
            ->getJson('/api/v1/b2b/portal-access/suppliers')
            ->assertOk()
            ->json('data.0');

        foreach (['password', 'temporary_password', 'password_hash'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }

    public function test_invitation_response_does_not_hand_a_reusable_temporary_password_to_the_client(): void
    {
        $vendor = Vendor::factory()->create();

        $body = $this->actingAs($this->operator())
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/invite", [
                'name' => 'New Contact',
                'email' => 'new-contact@example.test',
            ])
            ->assertStatus(201)
            ->json('data');

        // The temporary password travels by the invitation mail, not the API
        // response — the response is logged, cached and rendered client-side.
        $this->assertArrayNotHasKey('temporary_password', $body);
        $this->assertArrayNotHasKey('password', $body);
    }

    /* ─── RBAC ───────────────────────────────────────────────────── */

    public function test_lifecycle_actions_require_the_manage_permission(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->portalUser($vendor, 'rbac@example.test');
        // warehouse_staff holds no b2b.portal_access.* permission.
        $outsider = $this->operator('warehouse_staff');

        $this->actingAs($outsider)->getJson('/api/v1/b2b/portal-access/suppliers')->assertStatus(403);
        $this->actingAs($outsider)
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/invite", [
                'name' => 'Nope', 'email' => 'nope@example.test',
            ])->assertStatus(403);
        $this->actingAs($outsider)
            ->patchJson("/api/v1/b2b/portal-access/suppliers/{$user->hash_id}/deactivate")->assertStatus(403);
        $this->actingAs($outsider)
            ->patchJson("/api/v1/b2b/portal-access/suppliers/{$user->hash_id}/reactivate")->assertStatus(403);
        $this->actingAs($outsider)
            ->deleteJson("/api/v1/b2b/portal-access/suppliers/{$user->hash_id}/tokens")->assertStatus(403);

        $this->assertTrue((bool) $user->fresh()->is_active);
    }

    public function test_a_supplier_portal_principal_cannot_reach_the_access_administration_routes(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->portalUser($vendor, 'self-admin@example.test');

        Sanctum::actingAs($user, ['*'], 'supplier_portal');

        // A supplier must never be able to enumerate or mutate portal accounts,
        // least of all another vendor's.
        $this->getJson('/api/v1/b2b/portal-access/suppliers')->assertStatus(401);
        $this->patchJson("/api/v1/b2b/portal-access/suppliers/{$user->hash_id}/deactivate")->assertStatus(401);
    }
}
