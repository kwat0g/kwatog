<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * M003 — regression lock for the user-administration privilege boundary.
 *
 * Every case here was measured against the running stack during the
 * 2026-08-30 re-audit; this class pins those measurements so a future change
 * to `UserAdminService` cannot quietly reopen an escalation path.
 *
 * `test_last_active_system_admin_survives_every_admin_module_verb` deliberately
 * does NOT neutralise pre-existing active administrators the way
 * UserAdministrationHardeningTest::test_last_active_system_admin_cannot_be_removed
 * has to. It asserts the premise instead, so it fails loudly if another test
 * class commits an ACTIVE system_admin outside a rolled-back transaction —
 * which RbacConcurrencyTest did until it reset RefreshDatabaseState::$migrated.
 * That makes this test the drift guard for the fixture defect, not just for the
 * guard it exercises.
 */
class UserAdministrationEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        NotificationFacade::fake();
    }

    /**
     * A role holding only `admin.users.manage` must not be able to reach
     * `system_admin` by any of the four write paths that accept a role id, nor
     * by re-roling itself, nor by managing an existing administrator.
     */
    public function test_delegated_user_manager_cannot_reach_system_admin_by_any_path(): void
    {
        $manager = $this->delegate(['admin.users.manage']);
        $managerRoleHash = $manager->role->hash_id;
        $systemRole = Role::where('slug', 'system_admin')->firstOrFail();
        $employeeRole = Role::where('slug', 'employee')->firstOrFail();
        $financeRole = Role::where('slug', 'finance_officer')->firstOrFail();
        $target = $this->withRole('employee');
        $administrator = $this->systemAdmin();

        // create with role=system_admin
        $this->actingAs($manager)->postJson('/api/v1/admin/users', [
            'name' => 'Escalation Attempt',
            'email' => 'escalation-create@t.test',
            'role_id' => $systemRole->hash_id,
            'send_welcome' => false,
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'escalation-create@t.test']);

        // single role change of another user to system_admin
        $this->actingAs($manager)->patchJson("/api/v1/admin/users/{$target->hash_id}/role", [
            'role_id' => $systemRole->hash_id,
            'expected_role_id' => $employeeRole->hash_id,
            'reason' => 'Escalation attempt via role change',
        ])->assertForbidden();

        // bulk role change to system_admin
        $this->actingAs($manager)->patchJson('/api/v1/admin/users/bulk-role', [
            'user_ids' => [$target->hash_id],
            'role_id' => $systemRole->hash_id,
            'reason' => 'Escalation attempt via bulk role',
            'expected_role_ids' => [$target->hash_id => $employeeRole->hash_id],
        ])->assertForbidden();

        $this->assertSame($employeeRole->id, (int) $target->fresh()->role_id);

        // self-promotion to system_admin, and self-re-role to any other role
        $this->actingAs($manager)->patchJson("/api/v1/admin/users/{$manager->hash_id}/role", [
            'role_id' => $systemRole->hash_id,
            'expected_role_id' => $managerRoleHash,
            'reason' => 'Escalation attempt on self',
        ])->assertForbidden();
        $this->actingAs($manager)->patchJson("/api/v1/admin/users/{$manager->hash_id}/role", [
            'role_id' => $financeRole->hash_id,
            'expected_role_id' => $managerRoleHash,
            'reason' => 'Lateral escalation attempt on self',
        ])->assertForbidden();

        $this->assertSame((int) $manager->role_id, (int) $manager->fresh()->role_id);

        // managing an existing administrator in any way
        foreach (['reset-password', 'deactivate', 'activate', 'unlock'] as $verb) {
            $this->actingAs($manager)
                ->patchJson("/api/v1/admin/users/{$administrator->hash_id}/{$verb}")
                ->assertForbidden();
        }
        $this->actingAs($manager)->patchJson("/api/v1/admin/users/{$administrator->hash_id}/role", [
            'role_id' => $employeeRole->hash_id,
            'expected_role_id' => $systemRole->hash_id,
            'reason' => 'Escalation attempt stripping an administrator',
        ])->assertForbidden();
        $this->actingAs($manager)->patchJson("/api/v1/admin/users/{$administrator->hash_id}/profile", [
            'name' => 'Hijacked', 'email' => 'hijacked@t.test',
        ])->assertForbidden();

        $freshAdmin = $administrator->fresh();
        $this->assertSame($systemRole->id, (int) $freshAdmin->role_id);
        $this->assertTrue((bool) $freshAdmin->is_active);
    }

    /**
     * Per-user permission overrides are the other way to hand out authority.
     * They stay system-admin-only even for a role that holds the named
     * `admin.users.manage_permissions` permission.
     */
    public function test_delegated_manager_cannot_grant_permission_overrides(): void
    {
        $manager = $this->delegate(['admin.users.manage', 'admin.users.manage_permissions']);
        $target = $this->withRole('employee');

        // ... not to another user
        $this->actingAs($manager)->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
            'permission_slug' => 'admin.roles.manage', 'type' => 'grant', 'reason' => 'Override escalation attempt',
        ])->assertForbidden();

        // ... and not to itself
        $this->actingAs($manager)->postJson("/api/v1/admin/users/{$manager->hash_id}/overrides", [
            'permission_slug' => 'admin.roles.manage', 'type' => 'grant', 'reason' => 'Self override escalation attempt',
        ])->assertForbidden();

        $this->assertDatabaseCount('user_permission_overrides', 0);
    }

    /** Roles without `admin.users.manage` see nothing on this surface. */
    public function test_roles_without_the_permission_cannot_read_the_user_surface(): void
    {
        foreach (['hr_officer', 'finance_officer', 'employee', 'production_manager'] as $slug) {
            $this->actingAs($this->withRole($slug))
                ->getJson('/api/v1/admin/users')
                ->assertForbidden();
        }
    }

    /**
     * Deliberately asserts the premise rather than forcing it — see the class
     * docblock. Covers every verb the Admin module exposes that could remove
     * the final administrator.
     */
    public function test_last_active_system_admin_survives_every_admin_module_verb(): void
    {
        $systemRole = Role::where('slug', 'system_admin')->firstOrFail();
        $employeeRole = Role::where('slug', 'employee')->firstOrFail();

        $lastAdmin = $this->systemAdmin();
        $this->assertSame(
            1,
            User::query()->where('role_id', $systemRole->id)->where('is_active', true)->count(),
            'Premise: exactly one ACTIVE system administrator must exist. A committed row from '
            .'another test class (see RbacConcurrencyTest) silently disarms this assertion.',
        );

        // The actor cannot be the target for a "removed by someone else" case
        // while only one administrator is active, so it is an inactive peer.
        $peer = $this->systemAdmin(['is_active' => false]);

        // self-verbs
        $this->actingAs($lastAdmin)
            ->patchJson("/api/v1/admin/users/{$lastAdmin->hash_id}/deactivate")
            ->assertForbidden();
        $this->actingAs($lastAdmin)->patchJson("/api/v1/admin/users/{$lastAdmin->hash_id}/role", [
            'role_id' => $employeeRole->hash_id,
            'expected_role_id' => $systemRole->hash_id,
            'reason' => 'Self demotion attempt',
        ])->assertForbidden();

        // peer-driven verbs
        $this->actingAs($peer)
            ->patchJson("/api/v1/admin/users/{$lastAdmin->hash_id}/deactivate")
            ->assertUnprocessable();
        $this->actingAs($peer)->patchJson("/api/v1/admin/users/{$lastAdmin->hash_id}/role", [
            'role_id' => $employeeRole->hash_id,
            'expected_role_id' => $systemRole->hash_id,
            'reason' => 'Peer demotion attempt',
        ])->assertUnprocessable();
        $this->actingAs($peer)->patchJson('/api/v1/admin/users/bulk-role', [
            'user_ids' => [$lastAdmin->hash_id],
            'role_id' => $employeeRole->hash_id,
            'reason' => 'Peer bulk demotion attempt',
            'expected_role_ids' => [$lastAdmin->hash_id => $systemRole->hash_id],
        ])->assertUnprocessable();

        // there is no destroy route, so the module cannot soft-delete the last
        // administrator either. 405 (not 404) proves the URI exists for other
        // verbs and DELETE is simply not routed.
        $this->actingAs($peer)
            ->deleteJson("/api/v1/admin/users/{$lastAdmin->hash_id}")
            ->assertStatus(405);

        $fresh = $lastAdmin->fresh();
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertSame($systemRole->id, (int) $fresh->role_id);
        $this->assertSame(
            1,
            User::query()->where('role_id', $systemRole->id)->where('is_active', true)->count(),
        );
    }

    /**
     * Revoking a role must bite on the request that follows, not when the
     * cookie expires. Overrides are read fresh per call for the same reason.
     */
    public function test_revoked_authority_stops_working_on_the_next_request(): void
    {
        $administrator = $this->systemAdmin();
        $victim = $this->delegate(['admin.users.manage']);

        $this->actingAs($victim)->getJson('/api/v1/admin/users')->assertOk();

        $this->actingAs($administrator)->patchJson("/api/v1/admin/users/{$victim->hash_id}/role", [
            'role_id' => Role::where('slug', 'employee')->firstOrFail()->hash_id,
            'expected_role_id' => $victim->role->hash_id,
            'reason' => 'Quarterly access review removal',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($victim->fresh())->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_override_revoke_stops_working_on_the_next_request(): void
    {
        $administrator = $this->systemAdmin();
        $victim = $this->delegate(['admin.users.manage']);

        $this->actingAs($victim)->getJson('/api/v1/admin/users')->assertOk();

        $this->actingAs($administrator)->postJson("/api/v1/admin/users/{$victim->hash_id}/overrides", [
            'permission_slug' => 'admin.users.manage',
            'type' => 'revoke',
            'reason' => 'Quarterly access review removal',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->actingAs($victim->fresh())->getJson('/api/v1/admin/users')->assertForbidden();
    }

    /**
     * Soft-deleting a user must end the session immediately. There is no admin
     * route that soft-deletes a user, so this pins the auth-provider behaviour
     * the module relies on rather than a route.
     */
    public function test_soft_deleted_user_cannot_authenticate(): void
    {
        $victim = $this->delegate(['admin.users.manage']);
        $this->actingAs($victim)->getJson('/api/v1/admin/users')->assertOk();

        $victim->delete();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->assertSame(0, User::query()->where('id', $victim->id)->count());
    }

    /**
     * No raw integer key may appear in a payload or an error body. A
     * `{"id": 42}` shape in a 404 is an existence oracle.
     */
    public function test_no_raw_integer_ids_are_exposed_in_payloads_or_error_bodies(): void
    {
        $administrator = $this->systemAdmin();
        $target = $this->withRole('employee');

        $list = $this->actingAs($administrator)->getJson('/api/v1/admin/users')->assertOk();
        $this->assertContains($target->hash_id, array_column((array) $list->json('data'), 'id'));
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d/', $list->getContent());

        $detail = $this->actingAs($administrator)
            ->getJson("/api/v1/admin/users/{$target->hash_id}")
            ->assertOk();
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d/', $detail->getContent());

        foreach ([app('hashids')->encode(999999), 'not-a-hash', '42'] as $candidate) {
            $missing = $this->actingAs($administrator)->getJson("/api/v1/admin/users/{$candidate}");
            $missing->assertNotFound();
            $this->assertDoesNotMatchRegularExpression(
                '/"(id|user_id|model_id)"\s*:\s*\d/',
                $missing->getContent(),
                "404 body for [{$candidate}] must not carry a raw integer key.",
            );
        }
    }

    /**
     * `sort` and `direction` reach `orderBy()`, so both must be whitelisted at
     * the request boundary. The canonical service template in
     * docs/PATTERNS.md passes `direction` through unvalidated; this pins that
     * the bug is not present here.
     */
    public function test_list_rejects_unwhitelisted_sort_and_direction(): void
    {
        $administrator = $this->systemAdmin();

        $this->actingAs($administrator)
            ->getJson('/api/v1/admin/users?sort=name&direction=;drop')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction');

        $this->actingAs($administrator)
            ->getJson('/api/v1/admin/users?sort=password&direction=asc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        $this->actingAs($administrator)
            ->getJson('/api/v1/admin/users?role_id=not-a-real-hash')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');
    }

    /**
     * Every lifecycle mutation carries actor, IP and user agent. Without the
     * request metadata an audit row cannot answer "who, from where".
     */
    public function test_every_lifecycle_mutation_is_audited_with_ip_and_user_agent(): void
    {
        $administrator = $this->systemAdmin();
        $target = $this->withRole('employee', ['employee_id' => null]);

        $this->actingAs($administrator)->patchJson("/api/v1/admin/users/{$target->hash_id}/unlock")->assertOk();
        $this->actingAs($administrator)->patchJson("/api/v1/admin/users/{$target->hash_id}/deactivate")->assertOk();
        $this->actingAs($administrator)->patchJson("/api/v1/admin/users/{$target->hash_id}/activate")->assertOk();
        $this->actingAs($administrator)->patchJson("/api/v1/admin/users/{$target->hash_id}/reset-password")->assertOk();
        $this->actingAs($administrator)->patchJson("/api/v1/admin/users/{$target->hash_id}/profile", [
            'name' => 'Corrected Name', 'email' => 'corrected-lifecycle@t.test',
        ])->assertOk();
        $this->actingAs($administrator)->patchJson("/api/v1/admin/users/{$target->hash_id}/role", [
            'role_id' => Role::where('slug', 'hr_officer')->firstOrFail()->hash_id,
            'expected_role_id' => Role::where('slug', 'employee')->firstOrFail()->hash_id,
            'reason' => 'Quarterly access review promotion',
        ])->assertOk();

        $rows = DB::table('audit_logs')
            ->where('model_type', $target->getMorphClass())
            ->where('model_id', $target->id)
            ->get();

        foreach (['unlocked', 'deactivated', 'activated', 'password_reset', 'profile_updated', 'role_changed'] as $action) {
            $row = $rows->firstWhere('action', $action);
            $this->assertNotNull($row, "Missing audit row for [{$action}].");
            $this->assertSame($administrator->id, (int) $row->user_id, "Audit row [{$action}] lost the actor.");
            $this->assertNotNull($row->ip_address, "Audit row [{$action}] has no IP address.");
            $this->assertNotNull($row->user_agent, "Audit row [{$action}] has no user agent.");
            $this->assertNotNull($row->reason, "Audit row [{$action}] has no reason.");
        }
    }

    /**
     * An admin-initiated reset must force a change on next login and push the
     * superseded hash into `password_history` so the reuse rule can see it.
     */
    public function test_admin_reset_forces_a_change_and_records_password_history(): void
    {
        $administrator = $this->systemAdmin();
        $target = $this->withRole('employee', ['must_change_password' => false]);
        $supersededHash = $target->password;

        $response = $this->actingAs($administrator)
            ->patchJson("/api/v1/admin/users/{$target->hash_id}/reset-password")
            ->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertNull($fresh->locked_until);
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertTrue(
            Hash::check((string) $response->json('temp_password'), $fresh->password),
            'The returned one-time credential must be the credential that was stored.',
        );
        $this->assertDatabaseHas('password_history', [
            'user_id' => $target->id,
            'password_hash' => $supersededHash,
        ]);
    }

    // ------------------------------------------------------------- fixtures

    private function systemAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'sysadmin+'.uniqid().'@t.test',
        ], $overrides));
    }

    private function withRole(string $slug, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'email' => $slug.'+'.uniqid().'@t.test',
        ], $overrides));
    }

    /** @param  array<int, string>  $permissionSlugs */
    private function delegate(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'Delegated manager',
            'slug' => 'deleg_'.uniqid(),
            'description' => 'Test delegated user manager',
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all());

        return User::factory()->create([
            'role_id' => $role->id,
            'email' => 'delegate+'.uniqid().'@t.test',
        ]);
    }
}
