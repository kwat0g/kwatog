<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Models\ApprovalRecord;
use App\Modules\DSS\Detectors\ApprovalBottleneckDetector;
use App\Modules\DSS\Services\DetectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalBottleneckDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function detector(): ApprovalBottleneckDetector
    {
        return new ApprovalBottleneckDetector;
    }

    private function pendingRecord(string $role, int $ageHours, array $overrides = []): ApprovalRecord
    {
        return ApprovalRecord::create(array_merge([
            'approvable_type' => 'TestApprovable',
            'approvable_id' => random_int(1, 999999),
            'step_order' => 1,
            'role_slug' => $role,
            'action' => 'pending',
            'is_current' => true,
            'created_at' => now()->subHours($ageHours),
        ], $overrides));
    }

    public function test_clean_slate_emits_nothing(): void
    {
        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_flags_stuck_approvals_aggregated_per_role(): void
    {
        $this->pendingRecord('department_head', 30);
        $this->pendingRecord('department_head', 40);
        $this->pendingRecord('department_head', 1); // fresh: not stuck

        $scan = $this->detector()->detect();
        $this->assertSame(2, $scan->rowsScanned);
        $emissions = $scan->emissions;
        $byKey = [];
        foreach ($emissions as $emission) {
            $byKey[$emission->rationale['role_slug']] = $emission;
        }

        $this->assertArrayHasKey('department_head', $byKey);
        $stuck = $byKey['department_head'];
        $this->assertSame('approval_bottleneck', $stuck->type);
        $this->assertSame('warning', $stuck->severity);
        $this->assertSame(2, $stuck->rationale['affected_count']);
        $this->assertSame(24, $stuck->rationale['thresholds']['reminder_hours']);
        $this->assertSame(48, $stuck->rationale['thresholds']['escalation_hours']);
        $this->assertSame('/approvals', $stuck->action['route']);
        $this->assertSame(1.0, $stuck->confidence);
    }

    public function test_escalation_age_is_critical(): void
    {
        $this->pendingRecord('finance_officer', 60);

        $emissions = $this->detector()->detect()->emissions;

        $this->assertCount(1, $emissions);
        $this->assertSame('critical', $emissions[0]->severity);
        $this->assertSame('finance_officer', $emissions[0]->rationale['role_slug']);
    }

    public function test_decided_or_superseded_rows_are_ignored(): void
    {
        $this->pendingRecord('department_head', 30, ['action' => 'approved']);
        $this->pendingRecord('department_head', 30, ['is_current' => false]);

        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_detector_contract_metadata(): void
    {
        $detector = $this->detector();

        $this->assertSame('dss.approval_bottleneck', $detector->id());
        $this->assertSame('platform', $detector->domain());
        $this->assertSame(['dss.view'], $detector->ownerPermissions());
        $this->assertSame('v1', $detector->version());
    }

    public function test_registered_in_production_registry(): void
    {
        $this->assertTrue(app(DetectorRegistry::class)->has('dss.approval_bottleneck'));
    }
}
