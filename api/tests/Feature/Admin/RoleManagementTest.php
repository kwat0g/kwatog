<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Series R — Task R1.
 *
 * Covers: clone, system-role protection, permission-sync diff audit.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_clone_creates_custom_role_with_copied_permissions(): void
    {
        $admin  = $this->seedAdmin();
        $source = Role::where('slug', 'hr_officer')->firstOrFail();
        $sourcePermCount = $source->permissions()->count();

        $resp = $this->actingAs($admin)
            ->postJson("/api/v1/admin/roles/{$source->hash_id}/clone", [
                'name' => 'HR Specialist',
                'slug' => 'hr_specialist',
                'description' => 'Subset of HR Officer for L1 staff.',
            ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.slug', 'hr_specialist')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.type', 'Custom');

        $clone = Role::where('slug', 'hr_specialist')->firstOrFail();
        $this->assertSame($sourcePermCount, $clone->permissions()->count());
        $this->assertFalse((bool) $clone->is_system);

        // Audit row written for the clone action.
        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'cloned',
            'model_type' => $clone->getMorphClass(),
            'model_id'   => $clone->id,
        ]);
    }

    public function test_clone_rejects_duplicate_slug(): void
    {
        $admin = $this->seedAdmin();
        $source = Role::where('slug', 'hr_officer')->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/roles/{$source->hash_id}/clone", [
                'name' => 'Conflict',
                'slug' => 'hr_officer', // already exists
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('slug');
    }

    public function test_update_blocked_on_system_role(): void
    {
        $admin = $this->seedAdmin();
        $sys = Role::where('slug', 'hr_officer')->firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/roles/{$sys->hash_id}", [
                'name' => 'Hacked HR',
            ])
            ->assertStatus(422);

        $this->assertSame('HR Officer', $sys->fresh()->name);
    }

    public function test_destroy_blocked_on_system_role(): void
    {
        $admin = $this->seedAdmin();
        $sys = Role::where('slug', 'hr_officer')->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/roles/{$sys->hash_id}")
            ->assertStatus(422);

        $this->assertNotNull(Role::find($sys->id));
    }

    public function test_destroy_blocked_when_role_has_users(): void
    {
        $admin = $this->seedAdmin();
        $custom = $this->makeCustomRole('custom_with_users', ['hr.employees.view']);
        // Assign a user to it.
        User::factory()->create(['role_id' => $custom->id, 'email' => 'u'.uniqid().'@x.test']);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/roles/{$custom->hash_id}")
            ->assertStatus(422);
    }

    public function test_permission_sync_records_audit_diff(): void
    {
        $admin = $this->seedAdmin();
        $custom = $this->makeCustomRole('audit_diff', ['hr.employees.view']);

        $newSlugs = ['hr.employees.view', 'hr.employees.create'];

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/roles/{$custom->hash_id}/permissions", [
                'permission_slugs' => $newSlugs,
            ])
            ->assertOk();

        $audit = AuditLog::query()
            ->where('model_type', $custom->getMorphClass())
            ->where('model_id', $custom->id)
            ->where('action', 'permissions_synced')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEqualsCanonicalizing(['hr.employees.create'], $audit->new_values['added'] ?? []);
        $this->assertEqualsCanonicalizing([], $audit->new_values['removed'] ?? []);
    }

    public function test_permission_sync_blocked_on_system_admin(): void
    {
        $admin = $this->seedAdmin();
        $sysAdmin = Role::where('slug', 'system_admin')->firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/roles/{$sysAdmin->hash_id}/permissions", [
                'permission_slugs' => ['hr.employees.view'],
            ])
            ->assertStatus(422);
    }

    public function test_permission_sync_blocked_on_non_system_admin_system_role(): void
    {
        // System roles other than system_admin must also reject permission
        // sync via the API so deployments stay in lockstep with the seeder.
        // Custom roles remain freely editable (covered by the diff test above).
        $admin = $this->seedAdmin();
        $hrOfficer = Role::where('slug', 'hr_officer')->firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/roles/{$hrOfficer->hash_id}/permissions", [
                'permission_slugs' => ['hr.employees.view'],
            ])
            ->assertStatus(422);
    }

    public function test_clone_requires_admin_roles_manage_permission(): void
    {
        // Create a user without admin.roles.manage.
        $user = $this->seedUser(['hr.employees.view']);
        $source = Role::where('slug', 'hr_officer')->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/v1/admin/roles/{$source->hash_id}/clone", [
                'name' => 'X', 'slug' => 'x_clone',
            ])
            ->assertForbidden();
    }

    /**
     * P3.8 — lastModifiedFor() must return the LATEST timestamp, not an older one.
     *
     * Create a custom role, then insert two audit_log rows for it at different
     * timestamps (old and newer). Call GET /roles/{id} and assert that
     * last_modified_at reflects the NEWER timestamp, not the older one.
     * This pins the correlated-subquery fix and guards against regressions
     * where unbounded ORDER BY DESC was replaced by a MAX subquery.
     */
    public function test_last_modified_for_returns_latest_timestamp_not_oldest(): void
    {
        $admin  = $this->seedAdmin();
        $custom = $this->makeCustomRole('audit_order_check', ['hr.employees.view']);

        $morph = $custom->getMorphClass();
        $older = now()->subHours(2)->toDateTimeString();
        $newer = now()->subMinutes(30)->toDateTimeString();

        // Insert OLDER audit row first.
        DB::table('audit_logs')->insert([
            'user_id'    => $admin->id,
            'action'     => 'updated',
            'model_type' => $morph,
            'model_id'   => $custom->id,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => $older,
        ]);

        // Insert NEWER audit row second.
        DB::table('audit_logs')->insert([
            'user_id'    => $admin->id,
            'action'     => 'updated',
            'model_type' => $morph,
            'model_id'   => $custom->id,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => $newer,
        ]);

        $resp = $this->actingAs($admin)
            ->getJson("/api/v1/admin/roles/{$custom->hash_id}");

        $resp->assertOk();

        $returnedAt = $resp->json('data.last_modified_at');
        $this->assertNotNull($returnedAt, 'last_modified_at must not be null when audit rows exist');

        // The returned ISO timestamp must correspond to the NEWER row,
        // not the older one. We compare Carbon instances for tolerance-free assertion.
        $returnedCarbon = \Illuminate\Support\Carbon::parse($returnedAt);
        $newerCarbon    = \Illuminate\Support\Carbon::parse($newer);
        $olderCarbon    = \Illuminate\Support\Carbon::parse($older);

        $this->assertTrue(
            $returnedCarbon->greaterThan($olderCarbon),
            "last_modified_at ({$returnedAt}) should be newer than the older audit row ({$older})"
        );

        $this->assertEqualsWithDelta(
            $newerCarbon->timestamp,
            $returnedCarbon->timestamp,
            1, // 1-second tolerance for timestamp comparison
            "last_modified_at should match the most recent audit row ({$newer}), got {$returnedAt}"
        );
    }

    private function seedAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email'   => 'admin+'.uniqid().'@test.local',
        ]);
    }

    private function makeCustomRole(string $slug, array $permissionSlugs): Role
    {
        $role = Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'description' => 'Custom test role',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return $role;
    }

    private function seedUser(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Tester',
            'slug' => 'tester_'.uniqid(),
            'description' => 'Test',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $permissions)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return User::factory()->create([
            'role_id' => $role->id,
            'email' => 'u'.uniqid().'@test.local',
        ]);
    }
}
