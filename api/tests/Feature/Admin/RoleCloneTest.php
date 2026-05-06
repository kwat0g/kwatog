<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WS-B.1 — Clone an existing role with its permission set.
 */
class RoleCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeAdmin(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name' => 'Admin', 'email' => 'admin_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId,
        ]);
    }

    public function test_clone_copies_permissions_and_derives_a_unique_slug(): void
    {
        $source = Role::where('slug', 'hr_officer')->firstOrFail();
        $sourcePermCount = $source->permissions()->count();
        $this->assertGreaterThan(0, $sourcePermCount);

        $resp = $this->actingAs($this->makeAdmin())
            ->postJson("/api/v1/admin/roles/{$source->hash_id}/clone")
            ->assertCreated()
            ->assertJsonPath('data.slug', 'hr_officer_copy');

        $newRole = Role::where('slug', 'hr_officer_copy')->firstOrFail();
        $this->assertSame($sourcePermCount, $newRole->permissions()->count());
        $this->assertSame('HR Officer (Copy)', $newRole->name);
    }

    public function test_clone_accepts_explicit_overrides(): void
    {
        $source = Role::where('slug', 'finance_officer')->firstOrFail();

        $this->actingAs($this->makeAdmin())
            ->postJson("/api/v1/admin/roles/{$source->hash_id}/clone", [
                'name'        => 'Finance Manager',
                'slug'        => 'finance_manager',
                'description' => 'Senior finance role',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Finance Manager')
            ->assertJsonPath('data.slug', 'finance_manager');

        $this->assertDatabaseHas('roles', [
            'slug'        => 'finance_manager',
            'name'        => 'Finance Manager',
            'description' => 'Senior finance role',
        ]);
    }

    public function test_cloning_when_default_slug_collides_appends_a_counter(): void
    {
        $source = Role::where('slug', 'hr_officer')->firstOrFail();

        // First clone gets `_copy`.
        $this->actingAs($this->makeAdmin())
            ->postJson("/api/v1/admin/roles/{$source->hash_id}/clone")
            ->assertCreated();

        // Second clone with the same source must not 422 on slug uniqueness;
        // service appends a counter.
        $resp = $this->actingAs($this->makeAdmin())
            ->postJson("/api/v1/admin/roles/{$source->hash_id}/clone")
            ->assertCreated();

        $this->assertSame('hr_officer_copy_2', $resp->json('data.slug'));
    }

    public function test_user_without_permission_cannot_clone(): void
    {
        $emp = Role::query()->where('slug', 'employee')->value('id');
        $u = User::create([
            'name' => 'E', 'email' => 'e_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $emp,
        ]);

        $source = Role::where('slug', 'hr_officer')->firstOrFail();

        $this->actingAs($u)
            ->postJson("/api/v1/admin/roles/{$source->hash_id}/clone")
            ->assertStatus(403);
    }
}
