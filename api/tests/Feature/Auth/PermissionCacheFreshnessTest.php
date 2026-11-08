<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Enums\PermissionOverrideType;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\RolePermissionCacheGeneration;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\MigrationsEnded;
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

        // Role-permission set is cached by restore namespace, role, and DB version.
        $cacheKey = $this->rolePermissionCacheKey($user);
        $this->assertTrue(Cache::has($cacheKey));

        // Flushing only the role cache should not break — next call rebuilds.
        Cache::forget($cacheKey);
        $this->assertFalse(Cache::has($cacheKey));

        $this->assertTrue($user->fresh()->hasPermission('hr.employees.view'));
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_restore_namespace_prevents_reuse_when_permissions_version_repeats(): void
    {
        $user = $this->userWithRole('hr_officer');
        $this->assertContains('hr.employees.view', $user->permission_slugs);

        $oldKey = $this->rolePermissionCacheKey($user);
        $this->assertTrue(Cache::has($oldKey));

        self::assertTrue(app(RolePermissionCacheGeneration::class)->invalidateForDatabaseRestore());

        $newKey = $this->rolePermissionCacheKey($user);
        $this->assertNotSame($oldKey, $newKey, 'A restored DB version must not select an earlier permission snapshot.');
        $this->assertTrue(Cache::has($oldKey), 'The retired entry remains TTL-bounded but is no longer addressable.');
        $this->assertFalse(Cache::has($newKey));
        $this->assertContains('hr.employees.view', $this->userWithRole('hr_officer')->permission_slugs);
    }

    public function test_completed_migration_batch_rotates_role_permission_namespace(): void
    {
        $generation = app(RolePermissionCacheGeneration::class)->current();

        event(new MigrationsEnded('up'));

        $this->assertNotSame($generation, app(RolePermissionCacheGeneration::class)->current());
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

    public function test_role_perm_edit_invalidates_cache_for_all_members(): void
    {
        $user1 = $this->userWithRole('hr_officer');
        $user2 = $this->userWithRole('hr_officer');
        $user1->permission_slugs; // prime role cache
        $user1CacheKey = $this->rolePermissionCacheKey($user1);
        $user2CacheKey = $this->rolePermissionCacheKey($user2);
        $this->assertTrue(Cache::has($user1CacheKey));

        // Simulate a role permissions sync forgetting the role-keyed cache.
        Cache::forget($user1CacheKey);
        $this->assertFalse(Cache::has($user2CacheKey));
    }

    public function test_request_scoped_memo_avoids_repeat_override_queries(): void
    {
        $user = $this->userWithRole('hr_officer');
        // First call hydrates memo.
        $first = $user->permission_slugs;
        // Insert a new override AFTER hydration.
        $extraPerm = \App\Modules\Auth\Models\Permission::query()->firstOrCreate(
            ['slug' => 'memo.test.grant'],
            ['name' => 'Memo Test', 'module' => 'admin', 'description' => 'Memo test'],
        );
        \App\Modules\Admin\Models\UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $extraPerm->id,
            'type'          => 'grant',
            'granted_by'    => $user->id,
            'reason'        => 'memo test',
        ]);
        // Memo means we still see the original set on the same instance.
        $this->assertSame($first, $user->permission_slugs);
        // After flush, the new grant becomes visible.
        $user->flushPermissionsCache();
        $this->assertContains('memo.test.grant', $user->permission_slugs);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'email'   => $slug.'+'.uniqid().'@t.test',
        ]);
    }

    private function rolePermissionCacheKey(User $user): string
    {
        return app(RolePermissionCacheGeneration::class)->keyFor(
            (int) $user->role_id,
            (int) $user->role->permissions_version,
        );
    }
}
