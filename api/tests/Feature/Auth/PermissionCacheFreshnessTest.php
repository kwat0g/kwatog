<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Enums\PermissionOverrideType;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * H-9.1 — cache freshness regression suite.
 *
 * The pre-fix cache stored the resolved per-user slug list for 5 min. An
 * override that expired mid-TTL kept applying until the cache rebuilt.
 * Under the new model, role permissions are cached by role_id; overrides
 * are read fresh per call so expires_at is honored within a second.
 */
class PermissionCacheFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_expired_grant_override_stops_applying_immediately_without_manual_flush(): void
    {
        // 'employee' role lacks hr.employees.view — see RolePermissionSeeder.
        $user = $this->userWithRole('employee');
        $perm = Permission::where('slug', 'hr.employees.view')->firstOrFail();

        // Insert an already-expired Grant directly. The DB path skips the
        // service so no flush has run — this mimics the cache going stale
        // between override creation and natural expiry.
        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $perm->id,
            'type'          => PermissionOverrideType::Grant,
            'granted_by'    => $user->id,
            'reason'        => 'cache-freshness regression',
            'expires_at'    => now()->subSecond(),
        ]);

        // No flushPermissionsCache(); the override-resolver must read the
        // row fresh and see it as expired on the very first call.
        $this->assertFalse($user->fresh()->hasPermission('hr.employees.view'));
        $this->assertNotContains('hr.employees.view', $user->fresh()->permission_slugs);
    }

    public function test_active_grant_still_applies_and_role_perms_are_cached_by_role(): void
    {
        $user = $this->userWithRole('employee');
        $perm = Permission::where('slug', 'hr.employees.view')->firstOrFail();

        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $perm->id,
            'type'          => PermissionOverrideType::Grant,
            'granted_by'    => $user->id,
            'reason'        => 'active grant',
            'expires_at'    => now()->addDay(),
        ]);

        $this->assertTrue($user->fresh()->hasPermission('hr.employees.view'));

        // Role-permission set is cached by role_id.
        $this->assertTrue(Cache::has("auth:role_perms:{$user->role_id}"));

        // Flushing only the role cache should not break — next call rebuilds.
        Cache::forget("auth:role_perms:{$user->role_id}");
        $this->assertFalse(Cache::has("auth:role_perms:{$user->role_id}"));

        $this->assertTrue($user->fresh()->hasPermission('hr.employees.view'));
        $this->assertTrue(Cache::has("auth:role_perms:{$user->role_id}"));
    }

    public function test_expired_revoke_restores_role_permission(): void
    {
        // 'hr_officer' role grants hr.employees.view by default.
        $user = $this->userWithRole('hr_officer');
        $perm = Permission::where('slug', 'hr.employees.view')->firstOrFail();
        $this->assertContains('hr.employees.view', $user->fresh()->permission_slugs);

        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $perm->id,
            'type'          => PermissionOverrideType::Revoke,
            'granted_by'    => $user->id,
            'reason'        => 'expired revoke',
            'expires_at'    => now()->subSecond(),
        ]);

        // No cache flush — fresh read must see the revoke as expired and
        // restore the role's default permission.
        $this->assertTrue($user->fresh()->hasPermission('hr.employees.view'));
    }

    public function test_flush_permissions_cache_forgets_both_legacy_and_role_keys(): void
    {
        $user = $this->userWithRole('employee');

        Cache::put("auth:permissions:{$user->id}", ['legacy'], 300);
        Cache::put("auth:role_perms:{$user->role_id}", [1 => 'cached'], 300);

        $this->assertTrue(Cache::has("auth:permissions:{$user->id}"));
        $this->assertTrue(Cache::has("auth:role_perms:{$user->role_id}"));

        $user->flushPermissionsCache();

        $this->assertFalse(Cache::has("auth:permissions:{$user->id}"));
        $this->assertFalse(Cache::has("auth:role_perms:{$user->role_id}"));
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'email'   => $slug.'+'.uniqid().'@t.test',
        ]);
    }
}
