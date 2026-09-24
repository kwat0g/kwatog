<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\SupplyChain\Services\DeliveryService;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionOutcome;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Resources\InspectionResource;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class InspectionMakerCheckerPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private User $checker;

    private InspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maker = User::factory()->create(['is_active' => true]);
        $this->checker = User::factory()->create(['is_active' => true]);
        $this->service = app(InspectionService::class);
    }

    public function test_incoming_completion_stops_at_review_before_releasing_a_high_risk_result(): void
    {
        $inspection = $this->makeInspection(InspectionStage::Incoming, true);

        $result = $this->service->complete($inspection, $this->maker);

        $this->assertSame(InspectionStatus::AwaitingReview, $result->status);
        $this->assertSame(InspectionOutcome::Passed, $result->proposed_result);
        $this->assertNull($result->completed_at);
        $this->assertNull($result->reviewed_by);
    }

    public function test_in_process_completion_remains_single_actor(): void
    {
        $inspection = $this->makeInspection(InspectionStage::InProcess, true);

        $result = $this->service->complete($inspection, $this->maker);

        $this->assertSame(InspectionStatus::Passed, $result->status);
        $this->assertNull($result->proposed_result);
        $this->assertNull($result->reviewed_by);
    }

    public function test_high_risk_evidence_is_locked_while_awaiting_review(): void
    {
        $inspection = $this->makeInspection(InspectionStage::Incoming, true);
        $result = $this->service->complete($inspection, $this->maker);
        $measurement = $result->measurements->firstOrFail();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('evidence is locked');

        $this->service->recordMeasurements($result, [
            $measurement->id => ['is_pass' => false],
        ], $this->maker);
    }

    public function test_checker_cannot_finalize_the_makers_inspection(): void
    {
        $inspection = $this->makeInspection(InspectionStage::Outgoing, true);
        $inspection = $this->service->complete($inspection, $this->maker);

        $this->expectException(ForbiddenActionException::class);
        $this->expectExceptionMessage('cannot review an inspection you performed');

        $this->service->review($inspection, 'passed', null, $this->maker);
    }

    public function test_review_flag_hides_checker_actions_from_the_maker(): void
    {
        $role = Role::create(['name' => 'QC Checker', 'slug' => 'qc-checker-'.uniqid(), 'is_system' => false]);
        $role->permissions()->attach(Permission::firstOrCreate(
            ['slug' => 'quality.inspections.review'],
            ['name' => 'Check High-Risk Inspections', 'module' => 'quality'],
        ));
        $this->maker->forceFill(['role_id' => $role->id])->save();
        $this->checker->forceFill(['role_id' => $role->id])->save();
        $outsider = User::factory()->create(['is_active' => true]);

        $inspection = $this->service->complete($this->makeInspection(InspectionStage::Incoming, true), $this->maker);
        $canReview = function (User $user) use ($inspection): bool {
            $request = Request::create('/');
            $request->setUserResolver(fn () => $user);

            return (new InspectionResource($inspection))->toArray($request)['can_review'];
        };

        // The maker holds the permission but the service refuses self-review.
        $this->assertFalse($canReview($this->maker->fresh()));
        $this->assertTrue($canReview($this->checker->fresh()));
        $this->assertFalse($canReview($outsider));
    }

    public function test_failed_checker_decision_requires_remarks_and_is_audited(): void
    {
        $inspection = $this->makeInspection(InspectionStage::Incoming, false);
        $inspection = $this->service->complete($inspection, $this->maker);

        try {
            $this->service->review($inspection, 'failed', null, $this->checker);
            $this->fail('A failed high-risk disposition must require remarks.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('remarks', strtolower($exception->getMessage()));
        }

        $reviewed = $this->service->review($inspection, 'failed', 'Critical incoming defect confirmed.', $this->checker);

        $this->assertSame(InspectionStatus::Failed, $reviewed->status);
        $this->assertSame($this->checker->id, $reviewed->reviewed_by);
        $this->assertSame('Critical incoming defect confirmed.', $reviewed->review_remarks);
        $this->assertNotNull($reviewed->completed_at);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => Inspection::class,
            'model_id' => $inspection->id,
            'reason' => 'Critical incoming defect confirmed.',
        ]);
    }

    public function test_passing_a_failed_proposal_requires_an_override_reason(): void
    {
        $inspection = $this->makeInspection(InspectionStage::Outgoing, false);
        $inspection = $this->service->complete($inspection, $this->maker);

        try {
            $this->service->review($inspection, 'passed', null, $this->checker);
            $this->fail('An override from failed to passed must require remarks.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('remarks', strtolower($exception->getMessage()));
        }

        $reviewed = $this->service->review($inspection, 'passed', 'Override approved after documented recheck.', $this->checker);

        $this->assertSame(InspectionStatus::Passed, $reviewed->status);
        $this->assertSame(InspectionOutcome::Failed, $reviewed->proposed_result);
        $this->assertSame($this->checker->id, $reviewed->reviewed_by);
    }

    public function test_grn_acceptance_rejects_a_passed_incoming_inspection_without_checker_evidence(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->maker->id,
        ]);
        $poItem = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Policy material',
            'quantity' => '10.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '100.00',
            'quantity_received' => '0.000',
        ]);
        $grn = app(GrnService::class)->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => '10.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->maker);
        $inspection = Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)->firstOrFail();
        $inspection->forceFill(['status' => InspectionStatus::Passed])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('checked');

        app(GrnService::class)->accept($grn->fresh(), $this->maker);
    }

    public function test_delivery_release_rejects_a_passed_outgoing_inspection_without_checker_evidence(): void
    {
        $inspection = $this->makeInspection(InspectionStage::Outgoing, true);
        $inspection->forceFill(['status' => InspectionStatus::Passed])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('has not been checked');

        app(DeliveryService::class)->lockAndValidateInspectionForDelivery(
            $inspection->id,
            1,
            1,
            $inspection->product_id,
            '1',
        );
    }

    private function makeInspection(InspectionStage $stage, bool $passes): Inspection
    {
        $inspection = Inspection::query()->create([
            'inspection_number' => 'QC-POLICY-'.uniqid(),
            'stage' => $stage,
            'status' => InspectionStatus::InProgress,
            'entity_type' => $stage === InspectionStage::Incoming ? 'grn' : 'work_order',
            'entity_id' => 1,
            'product_id' => Product::factory()->create()->id,
            'batch_quantity' => 1,
            'sample_size' => 1,
            'accept_count' => 0,
            'reject_count' => 1,
            'inspector_id' => $this->maker->id,
            'started_at' => now(),
        ]);

        InspectionMeasurement::query()->create([
            'inspection_id' => $inspection->id,
            'sample_index' => 1,
            'parameter_name' => 'Policy check',
            'parameter_type' => 'visual',
            'is_critical' => true,
            'is_pass' => $passes,
        ]);

        return $inspection->fresh(['measurements']);
    }
}
