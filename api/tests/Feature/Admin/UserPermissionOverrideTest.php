<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Enums\PermissionOverrideType;
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
