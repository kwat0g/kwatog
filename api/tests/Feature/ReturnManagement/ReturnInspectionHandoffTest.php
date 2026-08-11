<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ChainListenerRun;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\ReturnManagement\Enums\ReturnInspectionHandoffStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Events\ReturnInspectionRequested;
use App\Modules\ReturnManagement\Listeners\CreateReturnInspectionOnRequested;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReturnInspectionHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_missing_quality_spec_keeps_rma_received_and_records_recovery_request(): void
    {
        $product = Product::factory()->create([
            'part_number' => 'RMA-QC-MISSING',
            'name' => 'Return product without a Quality spec',
        ]);
        $rma = $this->receivedRma($product);

        $updated = app(ReturnRequestService::class)->inspect($rma, 'Needs QC setup.', $this->user);

        $this->assertSame(ReturnRequestStatus::Received, $updated->status);
        $this->assertSame(ReturnInspectionHandoffStatus::ManualRequired, $updated->inspection_handoff_status);
        $this->assertNull($updated->inspection_id);
        $this->assertStringContainsString('no active inspection spec', strtolower((string) $updated->inspection_handoff_message));
        $this->assertSame(0, Inspection::query()->where('entity_type', 'return_request')->where('entity_id', $rma->id)->count());

        $outbox = DB::table('event_outbox')
            ->where('event_type', ReturnInspectionRequested::class)
            ->where('dedupe_key', 'return-inspection-request:' . $rma->id)
            ->firstOrFail();

        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $outbox->id,
            'chain' => 'returns',
            'entity_type' => 'return_request',
            'entity_id' => $rma->id,
            'step' => 'inspection_handoff',
        ]);
        $this->assertDatabaseHas('chain_listener_runs', [
            'outbox_id' => $outbox->id,
            'listener_class' => CreateReturnInspectionOnRequested::class,
            'outcome_status' => ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
            'outcome_code' => 'return_inspection_manual_required',
        ]);
    }

    public function test_replay_stages_one_inspection_after_quality_setup_is_fixed(): void
    {
        $product = Product::factory()->create([
            'part_number' => 'RMA-QC-RECOVER',
            'name' => 'Recoverable return product',
        ]);
        $rma = $this->receivedRma($product);
        app(ReturnRequestService::class)->inspect($rma, null, $this->user);

        $spec = InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Visual return condition',
            'parameter_type' => InspectionParameterType::Visual->value,
            'is_critical' => true,
            'sort_order' => 1,
        ]);

        $outbox = DB::table('event_outbox')
            ->where('event_type', ReturnInspectionRequested::class)
            ->where('dedupe_key', 'return-inspection-request:' . $rma->id)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode(
            (string) $outbox->event_type,
            json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertInstanceOf(ReturnInspectionRequested::class, $event);

        $listener = app(CreateReturnInspectionOnRequested::class);
        $listener->handle($event);
        $listener->handle($event);

        $recovered = $rma->fresh();
        $this->assertSame(ReturnRequestStatus::Inspected, $recovered->status);
        $this->assertSame(ReturnInspectionHandoffStatus::Generated, $recovered->inspection_handoff_status);
        $this->assertNotNull($recovered->inspection_id);
        $this->assertSame(1, Inspection::query()
            ->where('entity_type', 'return_request')
            ->where('entity_id', $rma->id)
            ->where('product_id', $product->id)
            ->count());
    }

    public function test_item_only_return_is_explicitly_not_required(): void
    {
        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-QC-ITEM-ONLY',
            'type' => 'customer_return',
            'status' => ReturnRequestStatus::Received,
            'received_at' => now(),
            'created_by' => $this->user->id,
        ]);
        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id' => Item::factory()->create()->id,
            'quantity' => 2,
            'returned_quantity' => 2,
            'unit_price' => '10.00',
            'total' => '20.00',
        ]);

        $updated = app(ReturnRequestService::class)->inspect($rma, null, $this->user);

        $this->assertSame(ReturnRequestStatus::Inspected, $updated->status);
        $this->assertSame(ReturnInspectionHandoffStatus::NotRequired, $updated->inspection_handoff_status);
        $this->assertSame(0, DB::table('event_outbox')->where('event_type', ReturnInspectionRequested::class)->count());
    }

    public function test_generated_handoff_does_not_authorize_disposition_without_a_pass(): void
    {
        $product = Product::factory()->create(['part_number' => 'RMA-QC-GENERATED']);
        $rma = $this->receivedRma($product);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-RMA-'.substr(uniqid(), -8),
            'stage'             => InspectionStage::CustomerReturn->value,
            'status'            => 'draft',
            'product_id'        => $product->id,
            'entity_type'       => InspectionEntityType::ReturnRequest->value,
            'entity_id'         => $rma->id,
            'batch_quantity'    => 5,
            'sample_size'       => 5,
            'accept_count'      => 0,
            'reject_count'      => 1,
            'defect_count'      => 0,
            'inspector_id'      => $this->user->id,
        ]);
        $rma->forceFill([
            'status' => ReturnRequestStatus::Inspected,
            'inspection_id' => $inspection->id,
            'inspection_handoff_status' => ReturnInspectionHandoffStatus::Generated,
        ])->save();

        $blocked = false;
        try {
            app(ReturnRequestService::class)->dispose($rma->fresh('items'), [[
                'item_id' => $rma->items->first()->hash_id,
                'disposition' => 'scrap',
            ]], $this->user);
        } catch (BusinessRuleException $e) {
            $blocked = true;
            $this->assertStringContainsString('passed', strtolower($e->getMessage()));
        }

        $this->assertTrue($blocked, 'A generated handoff is not a Quality pass.');
        $this->assertNull($rma->fresh()->disposition_status);
        $this->assertNull($rma->items->first()->fresh()->disposition);
    }

    public function test_retry_route_uses_hashed_id_and_manage_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->forceFill([
            'role_id' => DB::table('roles')->where('slug', 'system_admin')->value('id'),
        ])->save();

        $product = Product::factory()->create(['part_number' => 'RMA-QC-ROUTE']);
        $rma = $this->receivedRma($product);
        app(ReturnRequestService::class)->inspect($rma, null, $this->user);

        $spec = InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Return condition',
            'parameter_type' => InspectionParameterType::Visual->value,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/retry-inspection")
            ->assertOk()
            ->assertJsonPath('data.inspection_handoff.status', 'generated')
            ->assertJsonPath('data.status', 'inspected');
    }

    private function receivedRma(Product $product): ReturnRequest
    {
        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-QC-' . substr(uniqid(), -8),
            'type' => 'customer_return',
            'status' => ReturnRequestStatus::Received,
            'received_at' => now(),
            'created_by' => $this->user->id,
        ]);
        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'returned_quantity' => 5,
            'unit_price' => '10.00',
            'total' => '50.00',
        ]);

        return $rma->load('items.product');
    }
}
