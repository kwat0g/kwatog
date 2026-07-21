<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Models\SodConflictRule;
use App\Modules\Admin\Services\SodService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SodConflictRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REC-01 — data-driven Segregation-of-Duties matrix + violation report.
 */
class SodMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SodConflictRuleSeeder::class);
    }

    public function test_seeder_creates_known_conflicts(): void
    {
        $this->assertGreaterThanOrEqual(6, SodConflictRule::count());
        $this->assertDatabaseHas('sod_conflict_rules', ['code' => 'je_create_vs_post', 'active' => true]);
    }

    /** A role that legitimately splits duties trips no rule. */
    public function test_clean_user_has_no_violations(): void
    {
        // qc_inspector holds neither side of any money/HR conflict pair.
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);

        $this->assertTrue(app(SodService::class)->check($user)->isEmpty());
    }

    /** A user granted both sides of a pair (via override) is flagged. */
    public function test_user_holding_both_sides_is_flagged(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);

        // Grant both sides of the JE create-vs-post conflict via overrides.
        foreach (['accounting.journal.create', 'accounting.journal.post'] as $slug) {
            $perm = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => 'accounting']
            );
            \App\Modules\Admin\Models\UserPermissionOverride::create([
                'user_id'       => $user->id,
                'permission_id' => $perm->id,
                'type'          => 'grant',
                'granted_by'    => $user->id,
                'reason'        => 'test',
            ]);
        }
        $user->flushPermissionsCache();

        $violated = app(SodService::class)->check($user->fresh());
        $this->assertTrue($violated->contains(fn ($r) => $r->code === 'je_create_vs_post'));
    }

    /** system_admin holds '*' but is excluded from the report as break-glass. */
    public function test_scan_excludes_system_admin(): void
    {
        User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $report = app(SodService::class)->scanAllUsers();
        $this->assertEmpty($report);
    }

    /** The scan surfaces a real over-privileged non-admin user. */
    public function test_scan_reports_over_privileged_user(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
        foreach (['payroll.periods.compute', 'payroll.periods.approve'] as $slug) {
            $perm = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'payroll']);
            \App\Modules\Admin\Models\UserPermissionOverride::create([
                'user_id' => $user->id, 'permission_id' => $perm->id,
                'type' => 'grant', 'granted_by' => $user->id, 'reason' => 'test',
            ]);
        }
        $user->flushPermissionsCache();

        $report = app(SodService::class)->scanAllUsers();
        $this->assertCount(1, $report);
        $this->assertSame((int) $user->id, (int) $report[0]['user']->id);
        $this->assertTrue($report[0]['rules']->contains(fn ($r) => $r->code === 'payroll_compute_vs_approve'));
    }
}
