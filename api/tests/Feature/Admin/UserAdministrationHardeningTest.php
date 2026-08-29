<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Admin\Services\UserAdminService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class UserAdministrationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_delegated_user_manager_cannot_assign_system_admin(): void
    {
        $manager = $this->userWithPermissions(['admin.users.manage']);
        $systemRole = Role::where('slug', 'system_admin')->firstOrFail();

        $this->actingAs($manager)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Escalation Attempt',
                'email' => 'escalation@t.test',
                'role_id' => $systemRole->hash_id,
                'send_welcome' => false,
            ])
            ->assertForbidden();
    }

    public function test_self_deactivation_is_rejected(): void
    {
        $admin = $this->systemAdmin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$admin->hash_id}/deactivate")
            ->assertForbidden();

        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_last_active_system_admin_cannot_be_removed(): void
    {
        $systemRoleId = Role::where('slug', 'system_admin')->value('id');

        // Establish the premise instead of assuming a clean users table.
        //
        // RbacConcurrencyTest declares `protected array $connectionsToTransact = []`,
        // which switches RefreshDatabase's per-test transaction OFF for that class
        // (it forks, so its fixtures must be visible to a second connection). Its
        // cleanupConcurrencyFixtures() cannot delete the `users` rows — they are
        // referenced by append-only `audit_logs` — so an ACTIVE system_admin
        // ("concurrency-admin-…@test.local") used to stay committed for the rest of
        // the PHPUnit process. It sorts before this class in tests/Feature/Admin, so
        // in a full-suite run this test saw two active system admins — where
        // deactivating one is correctly ALLOWED — and the guard never fired.
        //
        // That leak is now fixed at source: RbacConcurrencyTest resets
        // RefreshDatabaseState::$migrated in tearDownAfterClass, so the next
        // RefreshDatabase class re-runs migrate:fresh and drops the committed rows.
        // The write below is KEPT anyway, because "the LAST active system admin" is
        // this test's whole premise and must be owned here rather than depending on
        // the teardown discipline of unrelated classes: any future committed row
        // would silently disarm the assertion again. It runs inside this test's own
        // transaction and is rolled back.
        //
        // UserAdministrationEscalationTest deliberately does NOT do this — it
        // asserts the premise instead, so it fails loudly if the leak returns.
        User::query()->where('role_id', $systemRoleId)->update(['is_active' => false]);

        $actor = $this->systemAdmin(['is_active' => false]);
        $target = $this->systemAdmin();

        $this->assertSame(
            1,
            User::query()->where('role_id', $systemRoleId)->where('is_active', true)->count(),
            'Precondition: $target must be the only active system administrator.',
        );

        $this->expectException(BusinessRuleException::class);
        app(UserAdminService::class)->deactivate($target, $actor);
    }

    public function test_reset_returns_one_time_credential_and_writes_audit(): void
    {
        NotificationFacade::fake();
        $admin = $this->systemAdmin();
        $target = $this->userWithRole('employee');

        $response = $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$target->hash_id}/reset-password")
            ->assertOk()
            ->assertJsonStructure(['message', 'sent_to', 'temp_password']);

        $this->assertNotEmpty($response->json('temp_password'));
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => $target->getMorphClass(),
            'model_id' => $target->id,
            'action' => 'password_reset',
        ]);
    }

    public function test_bulk_role_change_reports_decodable_but_missing_users(): void
    {
        $admin = $this->systemAdmin();
        $target = $this->userWithRole('employee');
        $role = Role::where('slug', 'hr_officer')->firstOrFail();
        $missingId = app('hashids')->encode(999999);

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/users/bulk-role', [
                'user_ids' => [$target->hash_id, $missingId],
                'role_id' => $role->hash_id,
                'reason' => 'Quarterly access review',
                'expected_role_ids' => [
                    $target->hash_id => Role::where('slug', 'employee')->firstOrFail()->hash_id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonCount(1, 'data.missing');
    }

    public function test_user_options_do_not_require_role_management_or_expose_system_admin_to_delegates(): void
    {
        $manager = $this->userWithPermissions(['admin.users.manage']);

        $this->actingAs($manager)
            ->getJson('/api/v1/admin/users/options')
            ->assertOk()
            ->assertJsonMissingPath('data.roles.0.permissions')
            ->assertJsonPath('data.roles', function (array $roles): bool {
                return ! collect($roles)->contains('slug', 'system_admin');
            });
    }

    public function test_standalone_profile_can_be_corrected_and_is_audited(): void
    {
        $admin = $this->systemAdmin();
        $target = $this->userWithRole('employee', ['employee_id' => null]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$target->hash_id}/profile", [
                'name' => 'Corrected Name',
                'email' => 'corrected@t.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Corrected Name')
            ->assertJsonPath('data.email', 'corrected@t.test');

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => $target->getMorphClass(),
            'model_id' => $target->id,
            'action' => 'profile_updated',
        ]);
    }

    private function systemAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'admin+'.uniqid().'@t.test',
        ], $overrides));
    }

    private function userWithRole(string $slug, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'email' => $slug.'+'.uniqid().'@t.test',
        ], $overrides));
    }

    /** @param array<int, string> $permissionSlugs */
    private function userWithPermissions(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'User manager',
            'slug' => 'user_manager_'.uniqid(),
            'description' => 'Test delegated user manager',
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all());

        return User::factory()->create([
            'role_id' => $role->id,
            'email' => 'manager+'.uniqid().'@t.test',
        ]);
    }
}
