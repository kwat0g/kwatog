<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalRecord;
use App\Common\Models\WorkflowDefinition;
use App\Common\Services\ApprovalService;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tranche B / F-045 — approval-step skipping must compare exact money.
 *
 * The skip path is live, not dead code: 2 of the 17 shipped workflow_definitions
 * rows carry a threshold on their final VP step — purchase_request and
 * purchase_order, seeded at WorkflowSeeder:64,73 — so this branch decides
 * whether VP approval is skipped on the two highest-value approval chains.
 *
 * This must still seed its own workflow, because RefreshDatabase leaves
 * workflow_definitions empty, and a threshold of its own lets the boundary be
 * probed at centavo distance rather than only at the shipped rows' single value.
 * The semantic under test is strict: a step is skipped when amount < threshold,
 * so an amount exactly equal to the threshold is RETAINED.
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

    /**
     * The tests above prove the comparison is exact by supplying their own
     * threshold as a JSON string. This one holds the SHIPPED rows to the same
     * standard: a threshold written as a PHP float is json_encoded to a JSON
     * number, and json_decode hands numbers back as PHP int or float — a float
     * for any value carrying centavos. That happens upstream of ApprovalService,
     * so no care taken inside the service can recover the lost precision.
     * 50000.00 survives today only by accident: it encodes to `50000` and comes
     * back an exact int. Storing the string is what keeps the seeded gate on the
     * two highest-value approval chains out of float arithmetic regardless of
     * what value an operator later puts there.
     */
    public function test_the_seeded_step_threshold_is_stored_as_a_json_string(): void
    {
        $this->seed(WorkflowSeeder::class);

        $steps = WorkflowDefinition::query()
            ->where('workflow_type', 'purchase_request')
            ->value('steps');

        $vp = collect($steps)->firstWhere('role', 'system_admin');

        $this->assertIsString($vp['threshold'], 'a JSON number carrying centavos decodes to a float upstream of ApprovalService');
        $this->assertSame('50000.00', $vp['threshold']);
    }
}
