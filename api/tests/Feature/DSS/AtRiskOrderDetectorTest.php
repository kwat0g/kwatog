<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\DSS\Detectors\AtRiskOrderDetector;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\MrpPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D03 — at-risk customer orders read the MRP plan diagnostics the team
 * already reviews (active plan per SO): shortage counts and planning
 * blockers. Aggregated to one emission per risk bucket; superseded/cancelled
 * plans and terminal orders stay out.
 */
class AtRiskOrderDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('alerts.ar.warning_overdue_days', 30);
        app(SettingsService::class)->set('alerts.ar.critical_overdue_days', 60);
    }

    private function detector(): AtRiskOrderDetector
    {
        return app(AtRiskOrderDetector::class);
    }

    private function salesOrder(string $status = SalesOrderStatus::Confirmed->value): SalesOrder
    {
        return SalesOrder::factory()->create(['status' => $status]);
    }

    private function activePlan(SalesOrder $so, int $version, int $shortages, array $diagnostics): MrpPlan
    {
        return MrpPlan::create([
            'mrp_plan_no' => 'MRP-'.str_pad((string) $so->id, 3, '0', STR_PAD_LEFT).'-V'.$version,
            'sales_order_id' => $so->id,
            'version' => $version,
            'status' => MrpPlanStatus::Active->value,
            'generated_by' => User::factory()->create()->id,
            'total_lines' => 1,
            'shortages_found' => $shortages,
            'diagnostics' => $diagnostics,
            'generated_at' => now(),
        ]);
    }

    public function test_clean_slate_emits_nothing(): void
    {
        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_emits_one_row_per_risk_bucket_and_skips_terminal_and_superseded(): void
    {
        $short = $this->salesOrder(); // confirmed, shortage
        $this->activePlan($short, 1, 3, []);

        $review = $this->salesOrder(); // confirmed, planning blockers, no shortage
        $this->activePlan($review, 1, 0, [
            ['kind' => 'warning', 'type' => 'missing_bom', 'product_id' => 1, 'message' => 'No active BOM found.'],
        ]);

        $dead = $this->salesOrder(SalesOrderStatus::Cancelled->value); // terminal, must stay out
        $this->activePlan($dead, 1, 5, []);

        $superseded = $this->salesOrder(); // history plan: not the live one
        MrpPlan::create([
            'mrp_plan_no' => 'MRP-SUP-1',
            'sales_order_id' => $superseded->id,
            'version' => 1,
            'status' => MrpPlanStatus::Superseded->value,
            'generated_by' => User::factory()->create()->id,
            'total_lines' => 1,
            'shortages_found' => 9,
            'diagnostics' => [],
            'generated_at' => now()->subDay(),
        ]);

        $byKey = [];
        foreach ($this->detector()->detect()->emissions as $emission) {
            $byKey[$emission->dedupeKey] = $emission;
        }

        $this->assertArrayHasKey('at-risk:mrp_shortage', $byKey);
        $this->assertArrayHasKey('at-risk:mrp_supply_review', $byKey);

        $shortage = $byKey['at-risk:mrp_shortage'];
        $this->assertSame('at_risk_order', $shortage->type);
        $this->assertSame('critical', $shortage->severity);
        $this->assertSame(1, $shortage->rationale['affected_order_count']);
        $this->assertSame(3, $shortage->rationale['shortage_line_total']);
        $this->assertSame(['ppc_head', 'sales_officer', 'vice_president'], $shortage->rationale['audience_roles']);
        $this->assertSame('/crm/sales-orders', $shortage->action['route']);
        $this->assertSame(1.0, $shortage->confidence);

        $supplyReview = $byKey['at-risk:mrp_supply_review'];
        $this->assertSame('warning', $supplyReview->severity);
        $this->assertSame(1, $supplyReview->rationale['affected_order_count']);
        $this->assertStringContainsString($review->so_number, $supplyReview->rationale['evidence'][0]);
    }

    public function test_evidence_lists_orders_capped(): void
    {
        $a = $this->salesOrder();
        $b = $this->salesOrder();
        $c = $this->salesOrder();
        $this->activePlan($a, 1, 1, []);
        $this->activePlan($b, 1, 2, []);
        $this->activePlan($c, 1, 4, []);

        $emissions = $this->detector()->detect(['max_evidence' => 2])->emissions;

        $shortage = null;
        foreach ($emissions as $emission) {
            if ($emission->dedupeKey === 'at-risk:mrp_shortage') {
                $shortage = $emission;
            }
        }

        $this->assertNotNull($shortage);
        $this->assertSame(3, $shortage->rationale['affected_order_count']);
        $this->assertSame(7, $shortage->rationale['shortage_line_total']);
        $this->assertCount(2, $shortage->rationale['evidence']);
        $this->assertStringContainsString($a->so_number, $shortage->rationale['evidence'][0]);
        $this->assertStringContainsString($b->so_number, $shortage->rationale['evidence'][1]);
    }

    public function test_detector_contract_metadata(): void
    {
        $detector = $this->detector();

        $this->assertSame('dss.at_risk_orders', $detector->id());
        $this->assertSame('crm', $detector->domain());
        $this->assertSame(['dss.view'], $detector->ownerPermissions());
        $this->assertSame('v1', $detector->version());
    }

    public function test_registered_in_production_registry(): void
    {
        $this->assertTrue(app(DetectorRegistry::class)->has('dss.at_risk_orders'));
    }
}
