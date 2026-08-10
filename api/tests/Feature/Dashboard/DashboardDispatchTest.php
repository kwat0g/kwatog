<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\DashboardDispatchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landing dashboard is derived from permissions, never from role names.
 *
 * These tests exist because the old SPA-side map keyed dashboards by
 * `role.slug`: renaming a role, adding one, or moving a dashboard
 * permission between roles silently dropped users onto the generic page,
 * and five of the thirteen seeded roles were missing from the map outright.
 */
class DashboardDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * The mapping every seeded role must produce. Asserting all of them
     * together is what catches a role losing its dashboard.
     */
    public static function roleLandingProvider(): array
    {
        return [
            'production_manager' => ['production_manager', '/dashboard/plant-manager'],
            'hr_officer'         => ['hr_officer',         '/dashboard/hr'],
            'ppc_head'           => ['ppc_head',           '/dashboard/ppc'],
            'finance_officer'    => ['finance_officer',    '/dashboard/finance'],
            'purchasing_officer' => ['purchasing_officer', '/dashboard/purchasing'],
            'warehouse_staff'    => ['warehouse_staff',    '/dashboard/warehouse'],
            'qc_inspector'       => ['qc_inspector',       '/dashboard/quality'],
            // Qualifies for all eight; the rarest permission wins.
            'system_admin'       => ['system_admin',       '/dashboard/admin'],
            // No purpose-built dashboard — these five were missing from the
            // old role map entirely and rendered nothing useful.
            'department_head'    => ['department_head',    '/dashboard/default'],
            'maintenance_tech'   => ['maintenance_tech',   '/dashboard/default'],
            'impex_officer'      => ['impex_officer',      '/dashboard/default'],
            'employee'           => ['employee',           '/dashboard/default'],
            'driver'             => ['driver',             '/dashboard/default'],
        ];
    }

    /** @dataProvider roleLandingProvider */
    public function test_each_role_lands_on_its_permission_derived_dashboard(string $slug, string $expected): void
    {
        $resp = $this->actingAs($this->userWithRole($slug))
            ->getJson('/api/v1/dashboard/dispatch')
            ->assertOk();

        $this->assertSame($expected, $resp->json('data.target.path'));
    }

    public function test_dashboard_follows_the_permission_not_the_role_name(): void
    {
        // A brand-new role the dispatcher has never heard of. It gets the
        // quality dashboard purely by holding that permission — no code,
        // seeder or catalog entry mentions this slug.
        $role = Role::create([
            'name'      => 'Line QA Auditor',
            'slug'      => 'line_qa_auditor',
            'is_system' => false,
        ]);
        $role->permissions()->sync([
            Permission::where('slug', 'dashboard.quality.view')->value('id'),
        ]);

        $resp = $this->actingAs($this->userWithRole('line_qa_auditor'))
            ->getJson('/api/v1/dashboard/dispatch')
            ->assertOk();

        $this->assertSame('/dashboard/quality', $resp->json('data.target.path'));
    }

    public function test_revoking_the_permission_moves_the_user_to_the_fallback(): void
    {
        $user = $this->userWithRole('qc_inspector');

        $this->actingAs($user)->getJson('/api/v1/dashboard/dispatch')
            ->assertJsonPath('data.target.path', '/dashboard/quality');

        $role = Role::where('slug', 'qc_inspector')->first();
        $role->permissions()->detach(
            Permission::where('slug', 'dashboard.quality.view')->value('id'),
        );
        $user->flushPermissionsCache();

        $this->actingAs($user)->getJson('/api/v1/dashboard/dispatch')
            ->assertJsonPath('data.target.path', '/dashboard/default');
    }

    public function test_fallback_target_reports_no_key_so_callers_can_tell_it_apart(): void
    {
        $resp = $this->actingAs($this->userWithRole('employee'))
            ->getJson('/api/v1/dashboard/dispatch')
            ->assertOk();

        $this->assertNull($resp->json('data.target.key'));
        $this->assertSame([], $resp->json('data.candidates'));
    }

    public function test_admin_sees_every_dashboard_as_a_candidate_most_specific_first(): void
    {
        $resp = $this->actingAs($this->userWithRole('system_admin'))
            ->getJson('/api/v1/dashboard/dispatch')
            ->assertOk();

        $candidates = $resp->json('data.candidates');
        $this->assertCount(8, $candidates);
        $this->assertSame('admin', $candidates[0]['key']);

        $counts = array_column($candidates, 'holder_count');
        $sorted = $counts;
        sort($sorted);
        $this->assertSame($sorted, $counts, 'Candidates must be ordered most-specific first.');
    }

    /**
     * `users.role_id` is NOT NULL, so a roleless user cannot exist. The
     * reachable way to lose a role is for it to be soft-deleted: `Role` uses
     * SoftDeletes, so the belongsTo resolves to null and the dispatcher must
     * fall back rather than error.
     */
    public function test_a_user_whose_role_was_soft_deleted_gets_the_fallback(): void
    {
        $user = $this->userWithRole('qc_inspector');
        $this->assertSame('/dashboard/quality', app(DashboardDispatchService::class)->resolve($user)['path']);

        Role::where('slug', 'qc_inspector')->first()->delete();
        $user->flushPermissionsCache();

        $this->assertSame(
            '/dashboard/default',
            app(DashboardDispatchService::class)->resolve($user->fresh())['path'],
        );
    }

    public function test_dispatch_requires_authentication(): void
    {
        // Touch the seeded data so this case shares the same schema
        // expectations as the rest of the class before hitting the route.
        $this->assertNotNull(Role::where('slug', 'employee')->value('id'));

        $this->getJson('/api/v1/dashboard/dispatch')->assertUnauthorized();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'email'   => 'disp+'.substr(uniqid(), -8).'@t.test',
        ]);
    }
}
