<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Models\AuditLog;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Admin\Services\UserPermissionOverrideService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Series R — Task R2.
 *
 * Covers: grant adds, revoke removes, expiry ignores, cache busting,
 * and 403-without-permission for the override endpoints.
 */
class UserPermissionOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_grant_override_appears_in_effective_permissions(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('employee'); // role lacks hr.employees.view
        $perm = Permission::where('slug', 'hr.employees.view')->firstOrFail();

        $this->assertNotContains('hr.employees.view', $target->fresh()->permission_slugs);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'grant',
                'reason'          => 'Backup HR cover during onboarding sprint.',
            ])
            ->assertStatus(201);

        $this->assertContains('hr.employees.view', $target->fresh()->permission_slugs);
        $this->assertSame(1, UserPermissionOverride::where('user_id', $target->id)
            ->where('permission_id', $perm->id)->count());
    }

    public function test_revoke_override_removes_role_permission(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('hr_officer'); // role grants hr.employees.view

        $this->assertContains('hr.employees.view', $target->fresh()->permission_slugs);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'revoke',
                'reason'          => 'Sensitive data freeze during audit.',
            ])
            ->assertStatus(201);

        $this->assertNotContains('hr.employees.view', $target->fresh()->permission_slugs);
    }

    public function test_expired_override_is_ignored(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('employee');
        $perm = Permission::where('slug', 'hr.employees.view')->firstOrFail();

        // Manually create an expired grant — bypasses the API's "after:now" rule.
        UserPermissionOverride::create([
            'user_id'       => $target->id,
            'permission_id' => $perm->id,
            'type'          => PermissionOverrideType::Grant,
            'granted_by'    => $admin->id,
            'reason'        => 'Expired test',
            'expires_at'    => now()->subDay(),
        ]);

        // Bust cache to ensure we read fresh.
        $target->flushPermissionsCache();

        $this->assertNotContains('hr.employees.view', $target->fresh()->permission_slugs);
    }

    public function test_remove_override_restores_role_default(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('hr_officer');

        // Revoke a permission the role grants…
        app(UserPermissionOverrideService::class)->set(
            $target,
            $admin,
            'hr.employees.view',
            PermissionOverrideType::Revoke,
            'temporary lockdown',
        );
        $this->assertNotContains('hr.employees.view', $target->fresh()->permission_slugs);

        // …then remove the override and confirm the role default is restored.
        $override = UserPermissionOverride::where('user_id', $target->id)->firstOrFail();
        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/users/{$target->hash_id}/overrides/{$override->hash_id}")
            ->assertNoContent();

        $this->assertContains('hr.employees.view', $target->fresh()->permission_slugs);
    }

    public function test_endpoint_requires_admin_users_manage_permissions(): void
    {
        // A user with admin.users.manage but NOT admin.users.manage_permissions must be 403.
        $weakAdmin = $this->seedUserWithCustomPerms(['admin.users.manage']);
        $target = $this->seedUserWithRole('employee');

        $this->actingAs($weakAdmin)
            ->getJson("/api/v1/admin/users/{$target->hash_id}/overrides")
            ->assertForbidden();

        $this->actingAs($weakAdmin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'grant',
                'reason'          => 'attempt without permission',
            ])
            ->assertForbidden();
    }

    public function test_reason_is_required(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('employee');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'grant',
                // no reason
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('reason');
    }

    public function test_upsert_replaces_existing_override(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('employee');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'grant',
                'reason'          => 'first override',
            ])
            ->assertStatus(201);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'revoke',
                'reason'          => 'second override flips polarity',
            ])
            ->assertStatus(201);

        $this->assertSame(1, UserPermissionOverride::where('user_id', $target->id)->count());
    }

    public function test_audit_log_created_on_override_grant(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('employee');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'grant',
                'reason'          => 'Grant override for audit test',
            ])
            ->assertStatus(201);

        $override = UserPermissionOverride::where('user_id', $target->id)->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'created',
            'model_type' => $override->getMorphClass(),
            'model_id'   => $override->id,
        ]);

        $audit = AuditLog::where('model_type', $override->getMorphClass())
            ->where('model_id', $override->id)
            ->where('action', 'created')
            ->whereNotNull('new_values->permission_slug')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('hr.employees.view', $audit->new_values['permission_slug']);
        $this->assertEquals('grant', $audit->new_values['type']);
    }

    public function test_audit_log_created_on_override_revoke(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('hr_officer');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'revoke',
                'reason'          => 'Revoke override for audit test',
            ])
            ->assertStatus(201);

        $override = UserPermissionOverride::where('user_id', $target->id)->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'created',
            'model_type' => $override->getMorphClass(),
            'model_id'   => $override->id,
        ]);

        $audit = AuditLog::where('model_type', $override->getMorphClass())
            ->where('model_id', $override->id)
            ->where('action', 'created')
            ->whereNotNull('new_values->permission_slug')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('hr.employees.view', $audit->new_values['permission_slug']);
        $this->assertEquals('revoke', $audit->new_values['type']);
    }

    public function test_audit_log_created_on_override_deletion(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('employee');

        // Create an override first
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'grant',
                'reason'          => 'Override to be deleted',
            ])
            ->assertStatus(201);

        $override = UserPermissionOverride::where('user_id', $target->id)->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/users/{$target->hash_id}/overrides/{$override->hash_id}")
            ->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'deleted',
            'model_type' => $override->getMorphClass(),
            'model_id'   => $override->id,
        ]);

        $audit = AuditLog::where('model_type', $override->getMorphClass())
            ->where('model_id', $override->id)
            ->where('action', 'deleted')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('hr.employees.view', $audit->old_values['permission_slug']);
        $this->assertEquals('grant', $audit->old_values['type']);
    }

    public function test_system_admin_bypasses_all_permission_checks(): void
    {
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('employee');

        // system_admin can access all endpoints without explicit permissions
        $this->actingAs($admin)
            ->getJson("/api/v1/admin/users/{$target->hash_id}/overrides")
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'grant',
                'reason'          => 'System admin bypass test',
            ])
            ->assertStatus(201);

        $override = UserPermissionOverride::where('user_id', $target->id)->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/users/{$target->hash_id}/overrides/{$override->hash_id}")
            ->assertNoContent();
    }

    public function test_delete_endpoint_requires_admin_users_manage_permissions(): void
    {
        // A user with admin.users.manage but NOT admin.users.manage_permissions must be 403 on DELETE.
        $weakAdmin = $this->seedUserWithCustomPerms(['admin.users.manage']);
        $admin = $this->seedAdmin();
        $target = $this->seedUserWithRole('employee');

        // First create an override as a proper admin
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->hash_id}/overrides", [
                'permission_slug' => 'hr.employees.view',
                'type'            => 'grant',
                'reason'          => 'Delete permission test',
            ])
            ->assertStatus(201);

        $overrideModel = UserPermissionOverride::where('user_id', $target->id)->firstOrFail();

        $this->actingAs($weakAdmin)
            ->deleteJson("/api/v1/admin/users/{$target->hash_id}/overrides/{$overrideModel->hash_id}")
            ->assertForbidden();
    }

    private function seedAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email'   => 'admin+'.uniqid().'@t.test',
        ]);
    }

    private function seedUserWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'email'   => $slug.'+'.uniqid().'@t.test',
        ]);
    }

    private function seedUserWithCustomPerms(array $permSlugs): User
    {
        $role = Role::create([
            'name' => 'CustomPerm',
            'slug' => 'custom_perm_'.uniqid(),
            'description' => 'Test',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $permSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return User::factory()->create([
            'role_id' => $role->id,
            'email'   => 'custom+'.uniqid().'@t.test',
        ]);
    }
}
