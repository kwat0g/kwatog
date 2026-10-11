<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Services\KpiSnapshotService;
use Database\Seeders\KpiDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who may read which KPI, pinned as a matrix.
 *
 * `KpiSnapshotService::MODULE_PERMISSIONS` is a slug SET per module because one
 * slug per module left an audience unreadable. The company-overview health strip
 * draws production, quality, supply-chain and accounting KPIs, and the vice
 * president holds none of those four module dashboard slugs — so the page built
 * for them would have rendered raw empty states, 0 of its 6 tiles
 * (01-matrix §D4 #3, G-P3-2).
 * Granting them four whole module dashboards would have widened their surface
 * and moved their landing page; adding a company-wide read slug to every
 * module's set is the narrow fix.
 *
 * The matrix is asserted here rather than at each surface because re-expressing
 * a row scope per call site is exactly how the module row-scope leaks recorded
 * in CLAUDE.md happened. `KpiSnapshotService::getScorecard` is the path the
 * endpoint itself uses, so a change to the read boundary fails here first.
 */
class KpiVisibilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, KpiDefinitionSeeder::class]);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'kpi+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    /** The codes a viewer may read, through the endpoint's own path. */
    private function readableCodes(User $user): array
    {
        $scorecard = app(KpiSnapshotService::class)->getScorecard(2026, 6, $user);

        return array_map(static fn (array $item): string => $item['definition']['code'], $scorecard);
    }

    /**
     * The company-wide read admits every active KPI.
     *
     * This is the assertion the executive page depends on. Without it a strip
     * tile renders its empty message, and "you may not read this" is
     * indistinguishable on screen from "there is no data".
     */
    public function test_company_read_admits_every_module(): void
    {
        $readable = $this->readableCodes($this->userWithRole('vice_president'));

        $expected = KpiDefinition::query()->active()->pluck('code')->all();

        sort($expected);
        sort($readable);

        $this->assertSame($expected, $readable, 'the company read must admit every active KPI');
    }

    /**
     * The six tiles 01-matrix §B puts on the company-overview health strip — four
     * of them owned by modules the executive holds no dashboard for.
     */
    public function test_the_executive_health_strip_is_fully_readable(): void
    {
        $strip = ['oee', 'on_time_delivery', 'first_pass_yield', 'dppm', 'ar_aging_60d', 'budget_utilization'];

        $readable = $this->readableCodes($this->userWithRole('vice_president'));

        $missing = array_values(array_diff($strip, $readable));

        $this->assertSame([], $missing, 'health-strip tiles the executive cannot read: '.implode(', ', $missing));
    }

    /** A role holding none of the gates reads none of the KPIs. */
    public function test_a_role_holding_no_gate_reads_none(): void
    {
        // driver holds neither a module dashboard slug nor the company read.
        $this->assertSame([], $this->readableCodes($this->userWithRole('driver')));
    }

    /**
     * A module's own dashboard holder keeps exactly its own module.
     *
     * Adding the company read to every set is the point of the change, but it
     * must not become a quiet widening: the module slug stays the ONLY other way
     * into a module's KPIs.
     */
    public function test_a_module_dashboard_holder_reads_only_its_own_module(): void
    {
        $readable = $this->readableCodes($this->userWithRole('qc_inspector'));

        $this->assertNotEmpty($readable);

        $modules = KpiDefinition::query()
            ->whereIn('code', $readable)
            ->pluck('module')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['quality'], $modules);
    }

    /**
     * The role that owns the production schedule can read its own production
     * numbers.
     *
     * `production.dashboard.view` is a PAGE grant ("may open
     * /production/dashboard") held by production_manager and the admin wildcard,
     * so gating the production KPIs on it left ppc_head — which plans every work
     * order — reading 1 of the 4 codes on its own strip (G-P2-2 / G-P2-3).
     * `production.view` is the production DATA read, so the schedule owner gains
     * oee and wo_completion_rate.
     */
    public function test_the_production_data_read_admits_the_schedule_owner(): void
    {
        $readable = $this->readableCodes($this->userWithRole('ppc_head'));

        $this->assertContains('oee', $readable);
        $this->assertContains('wo_completion_rate', $readable);
    }

    /**
     * …and stops there: a module's DATA read must not reach into another module.
     *
     * ppc_head holds no `quality.*` grant, so first_pass_yield stays unreadable.
     * That is why `PPC_KPIS` no longer lists it (G-P2-3): the strip renders an
     * empty message for a code the viewer cannot read, so a strip tile is only
     * honest if the code is readable by the role holding the page. Reading it
     * there is a GRANT decision, and this test is what makes granting it a
     * deliberate edit rather than a side effect of a data-read slug.
     */
    public function test_the_data_read_does_not_widen_a_role_beyond_its_own_modules(): void
    {
        $readable = $this->readableCodes($this->userWithRole('ppc_head'));

        $this->assertNotContains('first_pass_yield', $readable);

        $modules = KpiDefinition::query()
            ->whereIn('code', $readable)
            ->pluck('module')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['production', 'supply_chain'], $modules);
    }

    /**
     * The officer who owns deliveries can read the on-time-delivery rate.
     *
     * G-P3-2's resolution, and the reason `dashboard.logistics.view` sits in the
     * supply_chain set: `on_time_delivery` was gated on `dashboard.ppc.view`, so
     * the role accountable for OTD could not read it, and the logistics page
     * shipped as a surface for a number it was forbidden to show.
     *
     * The fix is a SECOND qualified gate, not a role-name branch — the check
     * below is on the grant, so who may read OTD stays a `role_permissions` row.
     */
    public function test_the_delivery_owner_can_read_on_time_delivery(): void
    {
        $readable = $this->readableCodes($this->userWithRole('impex_officer'));

        $this->assertContains('on_time_delivery', $readable);

        // …and it stops there: a supply-chain gate must not carry the whole
        // scorecard. impex_officer holds no `quality.*` or `production.*` read.
        $modules = KpiDefinition::query()
            ->whereIn('code', $readable)
            ->pluck('module')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['supply_chain'], $modules);
    }

    /**
     * The second gate did not become a company-wide one: holders of the
     * logistics page are the only roles the supply_chain set admits beyond PPC,
     * and the company read is asserted separately above.
     */
    public function test_the_logistics_gate_is_held_only_by_its_page_role(): void
    {
        $holders = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('permissions.slug', 'dashboard.logistics.view'))
            ->pluck('slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['impex_officer', 'system_admin'], $holders);
    }

    /**
     * Every declared gate is a slug some role can hold.
     *
     * A gate naming a permission that does not exist refuses everyone: the KPI
     * looks configured and is unreadable by design, with no error anywhere.
     */
    public function test_every_declared_gate_exists_in_the_permission_catalog(): void
    {
        $catalog = DB::table('permissions')->pluck('slug')->all();
        $missing = [];

        foreach (KpiSnapshotService::MODULE_PERMISSIONS as $module => $gates) {
            foreach ($gates as $slug) {
                if (! in_array($slug, $catalog, true)) {
                    $missing[] = "{$module} → {$slug}";
                }
            }
        }

        $this->assertSame([], $missing, "KPI gates naming unknown permissions:\n".implode("\n", $missing));
    }

    /**
     * The company read is held by exactly the two roles decided on 2026-10-11.
     *
     * Asserted on the grant rather than on behaviour because this IS the
     * decision (01-matrix §D4 #4). Widening who may read company-wide numbers
     * has to be a deliberate edit to this list, not a side effect of a role
     * picking up an unrelated slug.
     */
    public function test_company_read_is_held_by_exactly_the_decided_roles(): void
    {
        $holders = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('permissions.slug', 'dashboard.company.view'))
            ->pluck('slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['system_admin', 'vice_president'], $holders);
    }
}
