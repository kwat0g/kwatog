<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H-9.2 coverage — prune routes through the audited service path.
 *
 * Asserts:
 *   - expired rows are deleted, active and never-expire rows are kept,
 *   - one audit_logs row per pruned override (action=deleted, model_type=
 *     UserPermissionOverride morph),
 *   - the affected user's permission cache (legacy and role-keyed) is
 *     flushed after prune,
 *   - --dry-run does not delete or audit.
 */
class PruneExpiredPermissionOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_expired_overrides(): void
    {
        $role = Role::create([
            'name' => 'PruneTestRole',
            'slug' => 'prune_test_role_'.uniqid(),
            'description' => 'Test',
            'is_system' => false,
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);

        $permission = Permission::create([
            'name' => 'Test Permission',
            'slug' => 'test.permission',
            'module' => 'admin',
            'description' => 'Test permission for unit tests',
        ]);

        // Expired override → should be pruned (and audited).
        $expired = UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $permission->id,
            'type'          => 'grant',
            'granted_by'    => $user->id,
            'reason'        => 'Test expired grant',
            'expires_at'    => now()->subDay(),
        ]);

        // Active override → should NOT be pruned.
        $secondPermission = Permission::create([
            'name' => 'Second Test Permission',
            'slug' => 'second.test.permission',
            'module' => 'admin',
            'description' => 'Second test permission for unit tests',
        ]);
        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $secondPermission->id,
            'type'          => 'grant',
            'granted_by'    => $user->id,
            'reason'        => 'Test active grant',
            'expires_at'    => now()->addDay(),
        ]);

        // No expiration → should NOT be pruned.
        $thirdPermission = Permission::create([
            'name' => 'Third Test Permission',
            'slug' => 'third.test.permission',
            'module' => 'admin',
            'description' => 'Third test permission for unit tests',
        ]);
        UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $thirdPermission->id,
            'type'          => 'grant',
            'granted_by'    => $user->id,
            'reason'        => 'Test no expiration',
            'expires_at'    => null,
        ]);

        // Prime both legacy and new cache keys so we can verify the prune
        // path flushes them via UserPermissionOverrideService::remove.
        Cache::put("auth:permissions:{$user->id}", ['stale'], 300);
        Cache::put("auth:role_perms:{$user->role_id}", [99 => 'stale'], 300);

        $this->artisan('overrides:prune-expired')
            ->expectsOutputToContain('Pruned 1')
            ->assertSuccessful();

        $this->assertEquals(2, DB::table('user_permission_overrides')->count());
        $this->assertDatabaseMissing('user_permission_overrides', ['id' => $expired->id]);

        // Audit row written for the deleted override.
        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'deleted',
            'model_type' => $expired->getMorphClass(),
            'model_id'   => $expired->id,
        ]);

        // Per-user cache flushed by the service path.
        $this->assertFalse(Cache::has("auth:permissions:{$user->id}"));
        $this->assertFalse(Cache::has("auth:role_perms:{$user->role_id}"));
    }

    public function test_dry_run_mode_does_not_delete(): void
    {
        $user = User::factory()->create();
        $permission = Permission::create([
            'name' => 'Test Permission',
            'slug' => 'test.permission.dry',
            'module' => 'admin',
            'description' => 'Test permission for dry run',
        ]);

        $row = UserPermissionOverride::create([
            'user_id'       => $user->id,
            'permission_id' => $permission->id,
            'type'          => 'grant',
            'granted_by'    => $user->id,
            'reason'        => 'Expired override',
            'expires_at'    => now()->subDays(7),
        ]);

        $this->artisan('overrides:prune-expired --dry-run')
            ->expectsOutputToContain('Would prune 1')
            ->assertSuccessful();

        $this->assertEquals(1, DB::table('user_permission_overrides')->count());

        // Dry-run must not write a deleted audit row.
        $this->assertDatabaseMissing('audit_logs', [
            'action'     => 'deleted',
            'model_type' => $row->getMorphClass(),
            'model_id'   => $row->id,
        ]);
    }
}
