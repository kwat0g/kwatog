<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\CopqSnapshot;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T3.6.B — GET /api/v1/quality/copq/trend.
 *
 * Covers:
 *  - returns the last N persisted snapshots in ascending period order
 *  - 403 when the caller lacks `quality.copq.view`
 *  - the `?months=` query is clamped server-side at 36 (1..36)
 */
class CopqTrendTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(string $slug): User
    {
        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $perm = Permission::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'View COPQ Trends', 'module' => 'quality'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function userWithoutPermission(): User
    {
        $role = Role::firstOrCreate(['slug' => 'random_role'], ['name' => 'Random Role']);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function makeSnapshot(int $year, int $month, float $total = 100.0): CopqSnapshot
    {
        return CopqSnapshot::create([
            'period_year'  => $year,
            'period_month' => $month,
            'total_cost'   => $total,
            'breakdown'    => ['total' => $total],
            'computed_at'  => now(),
        ]);
    }

    public function test_trend_returns_last_n_snapshots_ordered_ascending(): void
    {
        $user = $this->userWithPermission('quality.copq.view');

        $this->makeSnapshot(2026, 3, 100);
        $this->makeSnapshot(2026, 4, 200);
        $this->makeSnapshot(2026, 5, 150);

        $res = $this->actingAs($user)->getJson('/api/v1/quality/copq/trend?months=12');

        $res->assertOk();
        $this->assertCount(3, $res->json('data'));
        $this->assertSame('2026-03', $res->json('data.0.period_label'));
        $this->assertSame('2026-04', $res->json('data.1.period_label'));
        $this->assertSame('2026-05', $res->json('data.2.period_label'));
    }

    public function test_trend_returns_403_when_user_lacks_permission(): void
    {
        $user = $this->userWithoutPermission();

        $this->actingAs($user)
            ->getJson('/api/v1/quality/copq/trend')
            ->assertForbidden();
    }

    public function test_months_query_is_clamped_at_36(): void
    {
        $user = $this->userWithPermission('quality.copq.view');

        // Seed 40 monthly snapshots — Feb 2023 onward (40 months -> May 2026).
        $year = 2023;
        $month = 2;
        for ($i = 0; $i < 40; $i++) {
            $this->makeSnapshot($year, $month, 100 + $i);
            $month++;
            if ($month > 12) { $month = 1; $year++; }
        }

        // Ask for 1000 — server must clamp to 36.
        $res = $this->actingAs($user)->getJson('/api/v1/quality/copq/trend?months=1000');

        $res->assertOk();
        $this->assertCount(36, $res->json('data'));

        // Ascending order — earliest of the kept window first, latest last.
        $first = $res->json('data.0.period_label');
        $last  = $res->json('data.35.period_label');
        $this->assertLessThan($last, $first);
    }
}
