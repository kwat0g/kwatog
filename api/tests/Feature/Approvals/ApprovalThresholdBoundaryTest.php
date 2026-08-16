<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalRecord;
use App\Common\Models\WorkflowDefinition;
use App\Common\Services\ApprovalService;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tranche B / F-045 — approval-step skipping must compare exact money.
 *
 * This must seed its own workflow: no shipped workflow_definitions row carries a
 * threshold, so the skip path is unreachable with the default data. The
 * semantic under test is strict: a step is skipped when amount < threshold, so
 * an amount exactly equal to the threshold is RETAINED.
 */
class ApprovalThresholdBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function workflowWithThreshold(): void
    {
        WorkflowDefinition::query()->create([
            'workflow_type' => 'tranche_b_threshold',
            'name'          => 'Threshold boundary probe',
            // Stored as a JSON string, not a JSON number: a number would lose
            // precision at json_decode before the comparison ever runs.
            'steps'         => [
                ['order' => 1, 'role' => 'department_head', 'label' => 'Dept Head', 'threshold' => '50000.00'],
                ['order' => 2, 'role' => 'finance_officer', 'label' => 'Finance'],
            ],
        ]);
    }

    private function actionForStepOne(string $amount): string
    {
        $pr = PurchaseRequest::factory()->create();
        app(ApprovalService::class)->submit($pr, 'tranche_b_threshold', $amount);

        return (string) ApprovalRecord::query()
            ->where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->getKey())
            ->where('step_order', 1)
            ->value('action');
    }

    public function test_one_centavo_below_the_threshold_skips_the_step(): void
    {
        $this->workflowWithThreshold();

        $this->assertSame('skipped', $this->actionForStepOne('49999.99'));
    }

    public function test_exactly_the_threshold_retains_the_step(): void
    {
        $this->workflowWithThreshold();

        $this->assertSame('pending', $this->actionForStepOne('50000.00'));
    }

    public function test_one_centavo_above_the_threshold_retains_the_step(): void
    {
        $this->workflowWithThreshold();

        $this->assertSame('pending', $this->actionForStepOne('50000.01'));
    }

    public function test_a_step_without_a_threshold_is_always_retained(): void
    {
        $this->workflowWithThreshold();
        $pr = PurchaseRequest::factory()->create();
        app(ApprovalService::class)->submit($pr, 'tranche_b_threshold', '1.00');

        $this->assertSame('pending', (string) ApprovalRecord::query()
            ->where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->getKey())
            ->where('step_order', 2)
            ->value('action'));
    }
}
