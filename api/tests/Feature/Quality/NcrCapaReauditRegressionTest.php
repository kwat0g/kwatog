<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\NcrTemplate;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\DefectParetoService;
use App\Modules\Quality\Services\NcrEscalationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M057 re-audit (2026-09-01) regressions.
 *
 * Three defects measured against real PostgreSQL rows:
 *   N-001  `ncr:escalate` printed a zero-count summary and exited SUCCESS when
 *          every candidate threw — indistinguishable from an idle run.
 *   N-008  `PATCH /quality/ncr-templates/{id}/restore` 404'd for every valid
 *          target because the route lacked `->withTrashed()`.
 *   N-009  `DefectParetoService::inspectionsWithDefect()` had one test caller
 *          and it asserted the empty case, so the row-mapping branch had never
 *          executed anywhere.
 */
final class NcrCapaReauditRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function staffRole(string $slug): User
    {
        $role = Role::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'description' => $slug, 'is_system' => true],
        );

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function overdueCriticalNcr(): NonConformanceReport
    {
        $ncr = NonConformanceReport::factory()->create();
        $ncr->forceFill([
            'severity' => NcrSeverity::Critical->value,
            'status' => NcrStatus::Open->value,
            'escalation_level' => 0,
        ])->save();
        DB::table('non_conformance_reports')->where('id', $ncr->id)
            ->update(['created_at' => now()->subDays(30)]);

        return $ncr->fresh();
    }

    private function bindFailingNotifications(): void
    {
        $this->app->bind(NotificationService::class, fn () => new class extends NotificationService
        {
            public function __construct() {}

            public function send($recipients, string $type, array $data): void
            {
                throw new \RuntimeException('notification transport is down');
            }
        });
    }

    /* ───────────────────────── N-001 ───────────────────────── */

    public function test_escalation_run_reports_failures_separately_from_skips(): void
    {
        $this->staffRole('qc_inspector');
        $this->overdueCriticalNcr();
        $this->overdueCriticalNcr();
        $this->bindFailingNotifications();

        $outcome = app(NcrEscalationService::class)->runWithOutcome();

        $this->assertSame(2, $outcome['considered']);
        $this->assertSame(0, $outcome['advanced']);
        $this->assertSame(0, $outcome['skipped']);
        $this->assertSame(0, $outcome['unstaffed']);
        // The whole point: a run where nothing survived must not look like a
        // run where nothing was due.
        $this->assertSame(2, $outcome['failed']);
    }

    public function test_escalate_command_fails_when_every_candidate_throws(): void
    {
        $this->staffRole('qc_inspector');
        $this->overdueCriticalNcr();
        $this->bindFailingNotifications();

        $this->artisan('ncr:escalate')
            ->expectsOutputToContain('1 considered, 0 advanced')
            ->expectsOutputToContain('1 of 1 NCR escalation(s) failed to deliver')
            ->assertExitCode(1);
    }

    public function test_escalate_command_succeeds_and_says_nothing_was_due_when_idle(): void
    {
        $this->staffRole('qc_inspector');

        $this->artisan('ncr:escalate')
            ->expectsOutputToContain('0 considered, 0 advanced, 0 skipped, 0 unstaffed, 0 failed')
            ->assertExitCode(0);
    }

    public function test_escalate_command_reports_an_unstaffed_tier_without_failing(): void
    {
        // No qc_inspector user exists, so tier 1 has no audience at all.
        User::query()->whereHas('role', fn ($q) => $q->where('slug', 'qc_inspector'))->delete();
        $this->overdueCriticalNcr();

        $this->artisan('ncr:escalate')
            ->expectsOutputToContain('1 considered, 0 advanced, 0 skipped, 1 unstaffed, 0 failed')
            ->expectsOutputToContain('reached nobody')
            ->assertExitCode(0);

        $this->assertDatabaseHas('ncr_escalation_deliveries', [
            'tier' => 1,
            'status' => 'pending',
        ]);
    }

    public function test_escalation_still_advances_a_healthy_tier(): void
    {
        $this->staffRole('qc_inspector');
        $ncr = $this->overdueCriticalNcr();

        $outcome = app(NcrEscalationService::class)->runWithOutcome();

        $this->assertSame(1, $outcome['advanced']);
        $this->assertSame(0, $outcome['failed']);
        $this->assertSame(1, (int) $ncr->fresh()->escalation_level);
    }

    /* ───────────────────────── N-008 ───────────────────────── */

    public function test_archived_ncr_template_can_be_restored(): void
    {
        $admin = $this->staffRole('system_admin');
        $template = NcrTemplate::create([
            'name' => 'Short shot template',
            'source' => 'inspection_fail',
            'severity' => 'high',
            'defect_description' => 'Short shot on gate side',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $hashId = $template->hash_id;
        $template->delete();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/quality/ncr-templates/{$hashId}/restore")
            ->assertOk();

        $this->assertNull(NcrTemplate::withTrashed()->findOrFail($template->id)->deleted_at);
    }

    /* ───────────────────────── N-009 ───────────────────────── */

    public function test_pareto_drill_down_maps_rows_with_hash_ids(): void
    {
        $product = Product::factory()->create();
        $inspection = Inspection::create([
            'inspection_number' => 'DRL-'.strtoupper(substr(uniqid(), -8)),
            'stage' => InspectionStage::Outgoing,
            'status' => InspectionStatus::Failed,
            'product_id' => $product->id,
            'batch_quantity' => 10,
            'sample_size' => 5,
            'defect_count' => 3,
            'completed_at' => now(),
        ]);
        foreach (['Burr', 'Burr', 'Short shot'] as $index => $parameter) {
            InspectionMeasurement::create([
                'inspection_id' => $inspection->id,
                'sample_index' => $index + 1,
                'parameter_name' => $parameter,
                'parameter_type' => 'visual',
                'is_critical' => false,
                'is_pass' => false,
            ]);
        }

        $filters = [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ];
        $rows = app(DefectParetoService::class)->inspectionsWithDefect('Burr', $filters);

        // The branch this test exists for: one grouped row, mapped, with the
        // product joined. Its only previous caller asserted the empty case.
        $this->assertCount(1, $rows);
        $this->assertSame($inspection->inspection_number, $rows[0]['inspection_number']);
        $this->assertSame('outgoing', $rows[0]['stage']);
        $this->assertSame('failed', $rows[0]['status']);
        $this->assertSame(3, $rows[0]['defect_count']);
        $this->assertSame($product->part_number, $rows[0]['product']['part_number']);

        // No raw integer PK may reach a caller.
        $this->assertSame($inspection->hash_id, $rows[0]['id']);
        $this->assertSame($product->hash_id, $rows[0]['product']['id']);
        $this->assertNotSame((string) $inspection->id, $rows[0]['id']);

        // And the same aggregate over an empty window stays honest.
        $this->assertSame([], app(DefectParetoService::class)
            ->inspectionsWithDefect('Burr', ['from' => '2001-01-01', 'to' => '2001-01-02']));
    }

    public function test_pareto_drill_down_is_reachable_over_http(): void
    {
        $viewer = $this->staffRole('qc_inspector');
        $product = Product::factory()->create();
        $inspection = Inspection::create([
            'inspection_number' => 'DRH-'.strtoupper(substr(uniqid(), -8)),
            'stage' => InspectionStage::Outgoing,
            'status' => InspectionStatus::Failed,
            'product_id' => $product->id,
            'batch_quantity' => 10,
            'sample_size' => 5,
            'defect_count' => 1,
            'completed_at' => now(),
        ]);
        InspectionMeasurement::create([
            'inspection_id' => $inspection->id,
            'sample_index' => 1,
            'parameter_name' => 'Flash',
            'parameter_type' => 'visual',
            'is_critical' => true,
            'is_pass' => false,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/quality/analytics/defect-pareto/drill?parameter_name=Flash')
            ->assertOk()
            ->assertJsonPath('data.0.id', $inspection->hash_id)
            ->assertJsonPath('data.0.product.id', $product->hash_id);
    }
}
