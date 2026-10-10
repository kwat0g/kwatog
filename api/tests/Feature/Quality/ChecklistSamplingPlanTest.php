<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\AuditLog;
use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxDispatcher;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Controllers\InspectionController;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistSamplingPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $inspector;

    private User $checker;

    private InspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.checklist_capture_enabled' => true]);
        $this->inspector = User::factory()->create(['is_active' => true]);
        $this->service = app(InspectionService::class);

        app(SettingsService::class)->set('quality.aql.sample_plan', [
            'tiny_batch' => ['code' => 'A', 'accept' => 0, 'reject' => 1],
            'rows' => [['max_lot' => 1000, 'code' => 'F', 'sample_size' => 20, 'accept' => 1, 'reject' => 2]],
            'overflow' => ['code' => 'Q', 'sample_size' => 100, 'accept' => 2, 'reject' => 3],
        ]);
        app(SettingsService::class)->set('quality.incoming.default_checklist', [[
            'parameter_name' => 'Surface', 'acceptance_criterion' => 'No cracks or contamination.',
        ]]);
    }

    public function test_unplanned_piece_receipt_requires_full_count_and_one_condition_attestation(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 100);

        $inspection = $this->service->createIncomingForItem($item, 100, $grn->id, $this->inspector, null, $line->id);

        $this->assertSame(100, $inspection->sample_size);
        $this->assertNull($inspection->aql_code);
        $this->assertSame(99, $inspection->accept_count);
        $this->assertSame(100, $inspection->reject_count);
        $this->assertSame(1, $inspection->measurements->count());
        $this->assertSame('Full piece count', $inspection->measurements->first()->parameter_name);
    }

    public function test_full_count_releases_only_good_pieces_after_independent_review(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $admin = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Admin']);
        User::factory()->create(['role_id' => $admin->id, 'is_active' => true]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 100);
        $inspection = $this->service->createIncomingForItem($item, 100, $grn->id, $this->inspector, null, $line->id);
        $this->grantInspectionPermission();
        $endpoint = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $payload = [
            'expected_result_version' => 0,
            'items' => [['id' => $inspection->measurements->first()->hash_id, 'result' => 'passed']],
            'coverage_confirmed' => true, 'inspected_quantity' => 100,
            'physical_defects_confirmed' => true, 'sample_defect_count' => 2,
            'problem_description' => 'Two torn bags.', 'defects_removed_confirmed' => true,
        ];

        $this->actingAs($this->inspector)->postJson($endpoint, array_diff_key($payload, ['sample_defect_count' => true]))->assertUnprocessable();
        $this->postJson($endpoint, array_replace($payload, ['sample_defect_count' => 101]))->assertUnprocessable();
        $this->postJson($endpoint, $payload)->assertOk()
            ->assertJsonPath('data.count_only_incoming', true)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value)
            ->assertJsonPath('data.proposed_result', 'passed');
        $this->assertSame('0.000', (string) $line->fresh()->quantity_accepted);

        $this->actingAs($this->checker)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', [
            'decision' => 'passed', 'expected_result_version' => 1,
        ])->assertOk();
        $this->assertSame(GrnStatus::PartialAccepted, $grn->fresh()->status);
        $this->assertSame('98.000', (string) $line->fresh()->quantity_accepted);
    }

    public function test_full_count_of_all_defective_pieces_proposes_failure(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 10);
        $inspection = $this->service->createIncomingForItem($item, 10, $grn->id, $this->inspector, null, $line->id);
        $this->grantInspectionPermission();

        $payload = [
            'expected_result_version' => 0,
            'items' => [['id' => $inspection->measurements->first()->hash_id, 'result' => 'passed']],
            'coverage_confirmed' => true, 'inspected_quantity' => 10,
            'physical_defects_confirmed' => true, 'sample_defect_count' => 10,
            'problem_description' => 'All bags torn.',
        ];
        $endpoint = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $this->actingAs($this->inspector)->postJson($endpoint, $payload)->assertUnprocessable();
        $this->postJson($endpoint, $payload + ['defects_removed_confirmed' => true])->assertOk()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value)
            ->assertJsonPath('data.proposed_result', 'failed');
        $this->assertSame('0.000', (string) $line->fresh()->quantity_accepted);
    }

    public function test_untouched_incoming_draft_can_be_replaced_without_losing_history(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 10);
        $old = $this->service->createIncomingForItem($item, 10, $grn->id, $this->inspector, null, $line->id);

        $this->service->cancel($old, 'Replace untouched fallback', $this->inspector, 0);
        app(GrnService::class)->retryIncomingQcHandoff($grn);

        $new = Inspection::query()->where('grn_item_id', $line->id)->where('status', '!=', 'cancelled')->sole();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($new->id, $grn->fresh()->qc_inspection_id);
        $this->assertSame(10, $new->sample_size);
        $this->assertSame(InspectionStatus::Cancelled, $old->fresh()->status);
    }

    public function test_incoming_aql_checklist_keeps_approved_sample_and_acceptance_counts(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 100);
        $plan = $this->qualityPlan($item, 'aql');

        $inspection = $this->service->createIncomingFromPlan($plan, $line, $grn, $this->inspector);

        $this->assertSame(20, $inspection->sample_size);
        $this->assertSame('F', $inspection->aql_code);
        $this->assertSame(1, $inspection->accept_count);
        $this->assertSame(2, $inspection->reject_count);
    }

    public function test_incoming_fixed_checklist_keeps_fixed_sample_size(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 100);
        $plan = $this->qualityPlan($item, 'fixed', 7);

        $inspection = $this->service->createIncomingFromPlan($plan, $line, $grn, $this->inspector);

        $this->assertSame(7, $inspection->sample_size);
        $this->assertNull($inspection->aql_code);
        $this->assertSame(0, $inspection->accept_count);
        $this->assertSame(1, $inspection->reject_count);
    }

    public function test_full_incoming_checklist_plan_still_requires_the_full_piece_lot(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 100);
        $plan = $this->qualityPlan($item, 'full');

        $inspection = $this->service->createIncomingFromPlan($plan, $line, $grn, $this->inspector);

        $this->assertSame(100, $inspection->sample_size);
    }

    public function test_api_enforces_the_pinned_fixed_sample_and_rolls_back_undercoverage(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 100);
        $plan = $this->qualityPlan($item, 'fixed', 7);
        $inspection = $this->service->createIncomingFromPlan($plan, $line, $grn, $this->inspector);
        $this->grantInspectionPermission();
        $items = $inspection->measurements->map(fn ($row) => ['id' => $row->hash_id, 'result' => 'passed'])->all();
        $endpoint = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';

        $this->actingAs($this->inspector)->postJson($endpoint, [
            'expected_result_version' => 0,
            'items' => $items,
            'coverage_confirmed' => true,
            'inspected_quantity' => 6,
            'sample_defect_count' => 0,
        ])->assertUnprocessable();

        $this->assertSame(0, (int) $inspection->fresh()->result_version);
        $this->assertSame(InspectionStatus::Draft, $inspection->fresh()->status);
        $this->assertNull($inspection->measurements()->firstOrFail()->fresh()->item_result);

        $this->postJson($endpoint, [
            'expected_result_version' => 0,
            'items' => $items,
            'coverage_confirmed' => true,
            'inspected_quantity' => 7,
            'sample_defect_count' => 0,
        ])->assertOk()
            ->assertJsonPath('data.sample_size', 7)
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value)
            ->assertJsonPath('data.proposed_result', 'passed');
    }

    public function test_outgoing_checklist_keeps_the_effective_aql_sample_and_counts(): void
    {
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        app(InspectionSpecService::class)->upsertForProduct($product->id, [[
            'parameter_name' => 'Surface', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks.',
        ]], $this->inspector->id, null, 'checklist');
        $workOrder = WorkOrder::factory()->create(['product_id' => $product->id, 'status' => 'in_progress']);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'shift' => 'day', 'good_count' => 100,
            'reject_count' => 0, 'recorded_by' => $this->inspector->id, 'recorded_at' => now(),
        ]);

        $inspection = $this->service->create([
            'stage' => 'outgoing', 'product_id' => $product->id,
            'batch_quantity' => 100, 'work_order_output_id' => $output->id,
        ], $this->inspector);

        $this->assertSame(20, $inspection->sample_size);
        $this->assertSame('F', $inspection->aql_code);
        $this->assertSame(1, $inspection->accept_count);
        $this->assertSame(2, $inspection->reject_count);
    }

    public function test_manual_outgoing_checklist_options_advertise_aql_sampling(): void
    {
        $sampling = array_column(app(InspectionController::class)->options()->getData(true)['data']['sampling_methods'], null, 'stage');

        $this->assertSame('aql', $sampling['outgoing']['value']);
        $this->assertStringStartsWith('AQL ', $sampling['outgoing']['label']);
    }

    public function test_api_requires_planned_sample_and_allows_aql_accepted_defects_after_removal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $systemAdminRole = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Admin']);
        User::factory()->create(['role_id' => $systemAdminRole->id, 'is_active' => true]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 100);
        $plan = $this->qualityPlan($item, 'aql');
        $inspection = $this->service->createIncomingFromPlan($plan, $line, $grn, $this->inspector);
        $this->grantInspectionPermission();
        $items = $inspection->measurements->map(fn ($row) => ['id' => $row->hash_id, 'result' => 'passed'])->all();
        $endpoint = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';

        $this->actingAs($this->inspector)->postJson($endpoint, [
            'expected_result_version' => 0,
            'items' => $items,
            'coverage_confirmed' => true,
            'inspected_quantity' => 19,
            'sample_defect_count' => 0,
        ])->assertUnprocessable();
        $this->assertSame(0, (int) $inspection->fresh()->result_version);
        $this->assertNull($inspection->measurements()->firstOrFail()->fresh()->item_result);

        $this->postJson($endpoint, [
            'expected_result_version' => 0,
            'items' => $items,
            'coverage_confirmed' => true,
            'inspected_quantity' => 20,
            'physical_defects_confirmed' => true,
            'sample_defect_count' => 1,
            'problem_description' => 'One chipped piece was found and segregated.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['error']);
        $this->assertSame(0, (int) $inspection->fresh()->result_version);
        $this->assertNull($inspection->measurements()->firstOrFail()->fresh()->item_result);

        $this->postJson($endpoint, [
            'expected_result_version' => 0,
            'items' => $items,
            'coverage_confirmed' => true,
            'inspected_quantity' => 20,
            'physical_defects_confirmed' => true,
            'sample_defect_count' => 1,
            'problem_description' => 'One chipped piece was found and segregated.',
            'defects_removed_confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value)
            ->assertJsonPath('data.proposed_result', 'passed')
            ->assertJsonPath('data.result_version', 1);

        $inspection = $inspection->fresh();
        $this->actingAs($this->inspector)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', [
            'decision' => 'passed', 'expected_result_version' => 1,
        ])->assertForbidden();
        $this->actingAs($this->checker)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', [
            'decision' => 'passed', 'expected_result_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', InspectionStatus::Passed->value)
            ->assertJsonPath('data.result_version', 2);

        $reviewed = $inspection->fresh();
        $this->assertSame($this->checker->id, $reviewed->reviewed_by);
        $this->assertNotNull($reviewed->reviewed_at);
        $this->assertSame(InspectionStatus::Passed, $reviewed->status);
        $this->assertSame(1, (int) $reviewed->sample_defect_count);
        $this->assertTrue($reviewed->defects_removed_confirmed);
        $approvalAudit = AuditLog::query()
            ->where('model_type', Inspection::class)
            ->where('model_id', $inspection->id)
            ->get()
            ->first(fn (AuditLog $audit): bool => (int) ($audit->new_values['reviewed_by'] ?? 0) === (int) $this->checker->id);
        $this->assertNotNull($approvalAudit);
        $this->assertSame($this->checker->id, $approvalAudit->user_id);

        $released = $grn->fresh();
        $acceptedLine = $line->fresh();
        $this->assertSame(GrnStatus::PartialAccepted, $released->status);
        $this->assertSame('99.000', (string) $acceptedLine->quantity_accepted);
        $this->assertNotNull($released->remainder_rejected_at);
        $this->assertSame('99.000', (string) $line->purchaseOrderItem->fresh()->quantity_accepted);
        $movements = StockMovement::query()
            ->where('reference_type', 'goods_receipt_note')
            ->where('reference_id', $grn->id);
        $this->assertSame(1, (clone $movements)->count());
        $this->assertSame('99.000', (string) $movements->sole()->quantity);

        $bill = Bill::query()->where('goods_receipt_note_id', $grn->id)->sole();
        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertSame('99.00', (string) $bill->subtotal);
        $this->assertSame(1, $bill->items()->count());

        $outbox = OutboxMessage::query()->where('event_type', InspectionPassed::class)->firstOrFail();
        (new DispatchOutboxMessage((string) $outbox->getKey()))->handle(app(OutboxDispatcher::class));

        $this->assertSame(1, $movements->count());
        $this->assertSame(1, Bill::query()->where('goods_receipt_note_id', $grn->id)->count());
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => InspectionPassed::class,
            'status' => OutboxMessage::STATUS_PUBLISHED,
        ]);
    }

    public function test_api_fails_piece_checklist_when_defects_exceed_pinned_aql_accept_count(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        [$grn, $line] = $this->receipt($item, 100);
        $plan = $this->qualityPlan($item, 'aql');
        $inspection = $this->service->createIncomingFromPlan($plan, $line, $grn, $this->inspector);
        $this->grantInspectionPermission();
        $items = $inspection->measurements->map(fn ($row) => ['id' => $row->hash_id, 'result' => 'passed'])->all();

        $this->actingAs($this->inspector)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', [
            'expected_result_version' => 0,
            'items' => $items,
            'coverage_confirmed' => true,
            'inspected_quantity' => 20,
            'physical_defects_confirmed' => true,
            'sample_defect_count' => 2,
            'problem_description' => 'Two chipped pieces were found.',
        ])->assertOk()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value)
            ->assertJsonPath('data.proposed_result', 'failed');
    }

    /** @return array{GoodsReceiptNote, GrnItem} */
    private function receipt(Item $item, int $quantity): array
    {
        $purchaseOrder = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->inspector->id,
        ]);
        $purchaseOrderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id, 'item_id' => $item->id,
            'description' => 'Checklist sample material', 'quantity' => (string) $quantity,
            'unit' => 'PCS', 'unit_price' => '1.00', 'total' => number_format($quantity, 2, '.', ''),
            'quantity_received' => number_format($quantity, 3, '.', ''),
        ]);
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $purchaseOrder->vendor_id,
            'received_by' => $this->inspector->id,
            'status' => GrnStatus::PendingQc,
        ]);
        $line = GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => number_format($quantity, 3, '.', ''),
            'quantity_accepted' => '0.000',
            'unit_cost' => '1.00',
        ]);

        return [$grn, $line];
    }

    private function qualityPlan(Item $item, string $method, ?int $fixedSampleSize = null): ItemQualityPlan
    {
        return ItemQualityPlan::query()->create([
            'item_id' => $item->id, 'vendor_id' => null, 'version' => 1, 'stage' => 'incoming',
            'sampling_method' => $method, 'fixed_sample_size' => $fixedSampleSize,
            'capture_method' => 'checklist', 'is_active' => true,
            'effective_from' => now()->toDateString(), 'created_by' => $this->inspector->id,
            'parameters' => [[
                'parameter_name' => 'Surface', 'acceptance_criterion' => 'No cracks or contamination.',
            ]],
        ]);
    }

    private function grantInspectionPermission(): void
    {
        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $permission = Permission::firstOrCreate(
            ['slug' => 'quality.inspections.manage'],
            ['name' => 'Manage Quality inspections', 'module' => 'quality'],
        );
        $reviewPermission = Permission::firstOrCreate(
            ['slug' => 'quality.inspections.review'],
            ['name' => 'Review Quality inspections', 'module' => 'quality'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id, $reviewPermission->id]);
        $this->inspector->forceFill(['role_id' => $role->id])->save();
        $this->checker = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
